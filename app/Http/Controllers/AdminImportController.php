<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExampleRequest;
use App\Models\AdminLog;
use App\Models\Example;
use App\Models\Sector;
use App\Services\ExampleContent;
use App\Services\ImageLocalizer;
use Illuminate\Support\Facades\DB;
use App\Services\QuizSourceImporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** /admin/import/* — lấy đề từ nguồn ngoài / file CSV (CHỈ administrator). */
class AdminImportController extends Controller
{
    /**
     * POST /admin/import/csv — tạo đề từ file CSV. Trình duyệt đọc file (.csv) thành
     * nội dung đề giống trang tạo đề (`question` = các phần) → kiểm tra y hệt trang tạo đề
     * (ExampleRequest + quy tắc từng dạng câu), tạo dưới tài khoản người đang đăng nhập.
     * Body: { name, sector, credits, question, source_file? }.
     */
    public function csv(ExampleRequest $request, ExampleContent $content)
    {
        $data = $request->validated();
        $file = mb_substr(trim((string) $request->input('source_file', '')), 0, 200);

        $example = DB::transaction(function () use ($data, $content) {
            $example = new Example();
            $example->forceFill([
                'name' => $data['name'],
                'count_test' => 0,
                'count_like' => 0,
                'num_question' => 0,
                'credits' => $data['credits'],
                'sector_id' => Sector::where('slug', $data['sector'])->value('id'),
                'user_id' => auth()->id(),
            ])->save();
            $content->sync($example, $data['question']);

            return $example;
        });

        AdminLog::record('import.create', 'exam', $example->slug, $example->name, [
            'source' => 'CSV' . ($file !== '' ? ": $file" : ''),
            'questions' => $example->num_question,
        ]);

        return response()->json([
            'slug' => $example->slug,
            'name' => $example->name,
            'questions' => $example->num_question,
        ], 201);
    }

    /** GET /admin/import/source?key=13995&source=MAIN — xem trước nội dung đề. */
    public function preview(Request $request, QuizSourceImporter $importer)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'source' => ['required', Rule::in(QuizSourceImporter::SOURCES)],
        ], ['key.regex' => 'Key chỉ gồm chữ, số, - và _.']);

        try {
            $result = $importer->fetch($data['key'], $data['source']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        AdminLog::record('import.fetch', 'import', $data['key'], "{$data['source']} #{$data['key']}", ['questions' => $result['total']]);

        return response()->json($result);
    }

    /**
     * POST /admin/import/source — lấy lại đề từ nguồn (không tin dữ liệu client gửi), chuyển
     * mọi ảnh về Cloudinary rồi tạo đề dưới tài khoản người đang đăng nhập.
     * Body { key, source, name, sector (slug), credits }.
     */
    public function store(Request $request, QuizSourceImporter $importer, ImageLocalizer $localizer, ExampleContent $content)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'source' => ['required', Rule::in(QuizSourceImporter::SOURCES)],
            // Tên đề do người nhập tự đặt (không tự sinh theo nguồn + key).
            'name' => ['required', 'string', 'max:255'],
            'sector' => ['required', 'string', 'exists:sectors,slug'],
            'credits' => ['required', 'integer', 'min:0', 'max:100'],
            // Bỏ qua đề có nhiều ảnh hơn mức này (null = không giới hạn).
            'max_images' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // Cho phép nhập lại đề đã nhập trước đó.
            'allow_duplicate' => ['sometimes', 'boolean'],
            // Bỏ các câu có ảnh (giới hạn ảnh khi đó chỉ tính trên các câu còn lại).
            'skip_image_questions' => ['sometimes', 'boolean'],
        ], [
            'key.regex' => 'Key chỉ gồm chữ, số, - và _.',
            'sector.exists' => 'Ngành không tồn tại.',
            'name.required' => 'Chưa nhập tên đề.',
        ]);
        $data['name'] = trim($data['name']);
        @set_time_limit(600);
        $sourceLabel = "{$data['source']} #{$data['key']}";

        // Đã nhập đề này (và đề vẫn còn) → bỏ qua, tránh trùng.
        if (!($data['allow_duplicate'] ?? false)) {
            $existing = AdminLog::where('action', 'import.create')->where('meta->source', $sourceLabel)
                ->pluck('target_id')->first(fn ($slug) => Example::where('slug', $slug)->exists());
            if ($existing) {
                return response()->json(['message' => 'Đề này đã được nhập trước đó.', 'code' => 'duplicate', 'slug' => $existing], 409);
            }
        }

        try {
            $result = $importer->fetch($data['key'], $data['source'], (bool) ($data['skip_image_questions'] ?? false));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (isset($data['max_images']) && $result['images'] > $data['max_images']) {
            return response()->json([
                'message' => "Đề có {$result['images']} ảnh (giới hạn {$data['max_images']}).",
                'code' => 'too_many_images',
                'images' => $result['images'],
            ], 422);
        }

        // Ảnh câu hỏi + ảnh nhúng trong nội dung → Cloudinary; ảnh chết bị gỡ.
        $parts = array_map(fn ($part) => [
            'name' => $part['name'],
            'questions' => array_map(function ($q) use ($localizer) {
                $q['name'] = $localizer->localizeHtml($q['name']);
                if (isset($q['explanation'])) {
                    $q['explanation'] = $localizer->localizeHtml($q['explanation']);
                }
                $q['answers'] = array_map(fn ($a) => ['option' => $localizer->localizeHtml($a['option'])] + $a, $q['answers']);
                $q['img'] = array_values(array_filter(array_map(
                    fn ($img) => ($url = $localizer->localize($img['url'])) ? ['url' => $url] : null,
                    $q['img'] ?? [],
                )));

                return $q;
            }, $part['questions']),
        ], $result['parts']);

        $example = DB::transaction(function () use ($data, $parts, $content, $sourceLabel) {
            $example = new Example();
            $example->forceFill([
                'name' => $data['name'],
                'count_test' => 0,
                'count_like' => 0,
                'num_question' => 0,
                'credits' => $data['credits'],
                'sector_id' => Sector::where('slug', $data['sector'])->value('id'),
                'user_id' => auth()->id(),
            ])->save();
            $content->sync($example, $parts);

            return $example;
        });

        $stats = $localizer->stats;
        AdminLog::record('import.create', 'exam', $example->slug, $example->name, [
            'source' => $sourceLabel,
            'questions' => $result['total'],
            'images' => $stats['uploaded'] + $stats['reused'],
        ] + ($result['skipped_image_questions'] ? ['skipped_image_questions' => $result['skipped_image_questions']] : []));

        return response()->json([
            'slug' => $example->slug,
            'name' => $example->name,
            'questions' => $result['total'],
            'skipped_image_questions' => $result['skipped_image_questions'],
            'images' => [
                'moved' => $stats['uploaded'] + $stats['reused'],
                'kept' => $stats['kept_heavy'],
                'removed' => $stats['broken'] + $result['images_dropped'],
            ],
        ], 201);
    }
}

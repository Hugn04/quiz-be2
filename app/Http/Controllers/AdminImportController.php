<?php

namespace App\Http\Controllers;

use App\Models\AdminLog;
use App\Models\Example;
use App\Models\Sector;
use App\Services\ExampleContent;
use App\Services\ImageLocalizer;
use Illuminate\Support\Facades\DB;
use App\Services\EduquizImporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/** /admin/import/* — lấy đề từ nguồn ngoài (CHỈ administrator). */
class AdminImportController extends Controller
{
    /** GET /admin/import/eduquiz?key=13995&source=EDUQUIZ — xem trước nội dung đề. */
    public function eduquiz(Request $request, EduquizImporter $importer)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'source' => ['required', Rule::in(EduquizImporter::SOURCES)],
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
     * POST /admin/import/eduquiz — lấy lại đề từ nguồn (không tin dữ liệu client gửi), chuyển
     * mọi ảnh về Cloudinary rồi tạo đề dưới tài khoản người đang đăng nhập.
     * Body { key, source, name, sector (slug), credits }.
     */
    public function store(Request $request, EduquizImporter $importer, ImageLocalizer $localizer, ExampleContent $content)
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/'],
            'source' => ['required', Rule::in(EduquizImporter::SOURCES)],
            'name' => ['nullable', 'string', 'max:255'],
            'sector' => ['required', 'string', 'exists:sectors,slug'],
            'credits' => ['required', 'integer', 'min:0', 'max:100'],
            // Bỏ qua đề có nhiều ảnh hơn mức này (null = không giới hạn).
            'max_images' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // Cho phép nhập lại đề đã nhập trước đó.
            'allow_duplicate' => ['sometimes', 'boolean'],
        ], ['key.regex' => 'Key chỉ gồm chữ, số, - và _.', 'sector.exists' => 'Ngành không tồn tại.']);
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
            $result = $importer->fetch($data['key'], $data['source']);
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
                'name' => trim((string) ($data['name'] ?? '')) ?: $sourceLabel,
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
        ]);

        return response()->json([
            'slug' => $example->slug,
            'name' => $example->name,
            'questions' => $result['total'],
            'images' => [
                'moved' => $stats['uploaded'] + $stats['reused'],
                'kept' => $stats['kept_heavy'],
                'removed' => $stats['broken'] + $result['images_dropped'],
            ],
        ], 201);
    }
}

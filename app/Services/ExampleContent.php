<?php

namespace App\Services;

use App\Models\Example;
use App\Models\Part;
use App\Models\Question;
use App\Models\Sector;
use App\QuestionTypes\QuestionTypes;
use Illuminate\Support\Facades\DB;

/**
 * Đọc / ghi nội dung đề (phần → câu hỏi → đáp án, ảnh) giữa các bảng chuẩn hóa và
 * định dạng JSON mà API trả cho frontend:
 *
 *   [{ id, name, questions: [{ id, type, name, explanation, points, settings,
 *                               img: [{ url }], answers: [{ id, option, is_correct, meta }] }] }]
 *
 * Giữ tên trường `name` / `option` / `img` như bản JSON cũ để frontend không phải đổi.
 */
class ExampleContent
{
    /** Quan hệ cần eager load trước khi gọi toArray(). */
    public const RELATIONS = ['parts.questions.answers', 'parts.questions.media'];

    public function __construct(private CloudinaryImages $images)
    {
    }

    /**
     * Ghi đè toàn bộ nội dung đề bằng `$parts` (đã validate) và cập nhật `num_question`.
     * Trả về tổng số câu hỏi.
     */
    public function sync(Example $example, array $parts): int
    {
        return DB::transaction(function () use ($example, $parts) {
            $oldMedia = $this->mediaUrls($example);
            // Xóa phần cũ → câu hỏi / đáp án / media xóa theo (cascade).
            $example->parts()->delete();

            $total = 0;
            foreach (array_values($parts) as $pi => $partData) {
                /** @var Part $part */
                $part = $example->parts()->create([
                    'name' => $partData['name'],
                    'position' => $pi,
                ]);

                foreach (array_values($partData['questions']) as $qi => $questionData) {
                    $type = QuestionTypes::get($questionData['type'] ?? null);
                    $questionData = $type->normalize($questionData);

                    /** @var Question $question */
                    $question = $part->questions()->create([
                        'type' => $type->key(),
                        'content' => $questionData['name'],
                        'explanation' => $questionData['explanation'] ?? null,
                        'points' => $questionData['points'] ?? 1,
                        'settings' => $questionData['settings'] ?? null,
                        'position' => $qi,
                    ]);

                    $question->answers()->createMany(
                        collect($questionData['answers'])->values()->map(fn ($answer, $ai) => [
                            'content' => $answer['option'],
                            'is_correct' => (bool) ($answer['is_correct'] ?? false),
                            'meta' => $answer['meta'] ?? null,
                            'position' => $ai,
                        ])->all(),
                    );

                    $question->media()->createMany(
                        collect($questionData['img'] ?? [])->values()
                            ->filter(fn ($media) => !empty($media['url']))
                            ->values()
                            ->map(fn ($media, $mi) => ['url' => $media['url'], 'position' => $mi])
                            ->all(),
                    );

                    $total++;
                }
            }

            $example->forceFill(['num_question' => $total])->save();

            // Ảnh câu hỏi không còn dùng trong nội dung mới → xóa trên Cloudinary (sau commit).
            foreach ($oldMedia as $url) {
                $this->images->delete($url);
            }

            return $total;
        });
    }

    /** Nội dung đề theo định dạng API (cần load RELATIONS trước). */
    public function toArray(Example $example): array
    {
        $example->loadMissing(self::RELATIONS);

        return $example->parts->map(fn (Part $part) => [
            'id' => $part->id,
            'name' => $part->name,
            'questions' => $part->questions->map(fn (Question $question) => [
                'id' => $question->id,
                'type' => $question->type,
                'name' => $question->content,
                'explanation' => $question->explanation,
                'points' => $question->points,
                'settings' => $question->settings,
                'img' => $question->media->map(fn ($media) => ['url' => $media->url])->all(),
                'answers' => $question->answers->map(fn ($answer) => [
                    'id' => $answer->id,
                    'option' => $answer->content,
                    'is_correct' => $answer->is_correct,
                    'meta' => $answer->meta,
                ])->all(),
            ])->all(),
        ])->all();
    }

    /** URL ảnh của mọi câu hỏi trong đề. */
    public function mediaUrls(Example $example): array
    {
        return DB::table('question_media')
            ->join('questions', 'questions.id', '=', 'question_media.question_id')
            ->join('parts', 'parts.id', '=', 'questions.part_id')
            ->where('parts.example_id', $example->id)
            ->pluck('question_media.url')
            ->unique()
            ->values()
            ->all();
    }

    /** Chi tiết đề kèm toàn bộ nội dung (định dạng API: `question` = các phần). */
    public function detail(Example $example): array
    {
        $example = Example::withAll()->with(self::RELATIONS)->findOrFail($example->id);
        $data = $example->toArray();
        unset($data['parts']);
        $data['question'] = $this->toArray($example);

        return $data;
    }

    /**
     * Sửa đề (dữ liệu đã validate bằng ExampleRequest): thông tin + ghi đè nội dung.
     * Đổi / bỏ ảnh bìa → ảnh cũ trên Cloudinary bị xóa sau khi lưu thành công.
     * Dùng chung cho chủ đề (PUT /examples/{slug}) và quản trị (PUT /admin/examples/{slug}).
     */
    public function update(Example $example, array $data): void
    {
        DB::transaction(function () use ($example, $data) {
            $example->forceFill([
                'name' => $data['name'],
                'credits' => $data['credits'],
                'sector_id' => Sector::where('slug', $data['sector'])->value('id'),
            ]);
            // Chỉ đổi ảnh khi client gửi trường `image`.
            if (array_key_exists('image', $data)) {
                $this->images->replaced($example->image, $data['image']);
                $example->image = $data['image'];
            }
            $example->save();
            $this->sync($example, $data['question']);
        });
    }

    /** Xóa đề + bộ câu hỏi (cascade) + ảnh bìa / ảnh câu hỏi trên Cloudinary. */
    public function destroy(Example $example): void
    {
        DB::transaction(function () use ($example) {
            $media = $this->mediaUrls($example);
            $example->delete();
            foreach ([$example->image, ...$media] as $url) {
                $this->images->delete($url);
            }
        });
    }
}

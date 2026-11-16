<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lấy đề từ EduQuiz / PESTHUBT (cùng API EduQuiz, khác `bizapp`) và chuyển sang định dạng
 * nội dung đề của app (như body POST /examples).
 */
class EduquizImporter
{
    public const SOURCES = ['EDUQUIZ', 'PESTHUBT'];

    /**
     * @return array{exam_id:int|null, parts:array, total:int, images:int, images_dropped:int}
     * @throws RuntimeException message hiển thị được cho người dùng
     */
    public function fetch(string $tag, string $source): array
    {
        $token = config('services.eduquiz.token');
        if (!$token) {
            throw new RuntimeException('Chưa cấu hình EDUQUIZ_TOKEN trong .env của backend.');
        }

        try {
            $response = Http::withToken($token)->acceptJson()->timeout(20)
                ->get(rtrim(config('services.eduquiz.url'), '/') . '/exams/' . rawurlencode($tag) . '/sections', [
                    'bizapp' => $source,
                    'is_question_shuffle' => 0,
                    'is_answer_shuffle' => 0,
                    'allow_access_exam' => 'true',
                ]);
        } catch (\Throwable) {
            throw new RuntimeException('Không kết nối được tới EduQuiz.');
        }
        if ($response->status() === 401 || $response->status() === 403) {
            throw new RuntimeException('Token EduQuiz hết hạn hoặc không hợp lệ.');
        }
        if (!$response->successful() || !is_array($response->json('data'))) {
            throw new RuntimeException('Không tìm thấy đề với key này.');
        }

        $sections = $response->json('data');
        // EduQuiz trả về đề KHÁC khi key không tồn tại → đối chiếu exam_id với key số.
        $examId = isset($sections[0]['exam_id']) ? (int) $sections[0]['exam_id'] : null;
        if (!$sections || (ctype_digit($tag) && $examId !== (int) $tag)) {
            throw new RuntimeException('Không tìm thấy đề với key này.');
        }

        $parts = [];
        $total = 0;
        $dropped = 0;
        $images = 0;
        foreach ($sections as $section) {
            $questions = [];
            foreach ($section['questions'] ?? [] as $q) {
                $answers = array_values(array_filter(array_map(fn ($a) => [
                    'option' => (string) ($a['option'] ?? ''),
                    'is_correct' => (bool) ($a['is_correct'] ?? false),
                ], $q['answers'] ?? []), fn ($a) => trim(strip_tags($a['option'])) !== ''));
                if (!$answers || trim(strip_tags((string) ($q['name'] ?? ''), '<img>')) === '') {
                    continue;
                }
                $correct = count(array_filter($answers, fn ($a) => $a['is_correct']));
                $images += count(array_filter($q['files'] ?? [], fn ($f) => !empty($f['url'])))
                    + substr_count(strtolower((string) $q['name']), '<img')
                    + array_sum(array_map(fn ($a) => substr_count(strtolower($a['option']), '<img'), $answers));

                // Ảnh: PESTHUBT lưu đường dẫn tương đối trên S3 của PESTHUBT; ảnh EduQuiz dạng
                // tương đối không có host công khai → bỏ, báo số ảnh bị bỏ. Khi tạo đề, mọi ảnh
                // được chuyển về Cloudinary (ImageLocalizer).
                $img = [];
                foreach ($q['files'] ?? [] as $file) {
                    $url = (string) ($file['url'] ?? '');
                    if ($url === '') {
                        continue;
                    }
                    if (preg_match('~^https?://~i', $url)) {
                        $img[] = ['url' => $url];
                    } elseif ($source === 'PESTHUBT') {
                        $img[] = ['url' => ImageLocalizer::PESTHUBT_HOST . '/' . ltrim($url, '/')];
                    } else {
                        $dropped++;
                    }
                }

                $questions[] = array_filter([
                    'type' => $correct > 1 ? 'multiple_choice' : 'single_choice',
                    'name' => (string) $q['name'],
                    'explanation' => trim(strip_tags((string) ($q['reason'] ?? ''))) ?: null,
                    'img' => $img ?: null,
                    'answers' => $answers,
                ], fn ($v) => $v !== null);
            }
            if ($questions) {
                $parts[] = ['name' => mb_substr(trim((string) ($section['name'] ?? '')) ?: 'Phần ' . (count($parts) + 1), 0, 255), 'questions' => $questions];
                $total += count($questions);
            }
        }
        if (!$parts) {
            throw new RuntimeException('Đề này không có câu hỏi nào lấy được.');
        }

        return ['exam_id' => $examId, 'parts' => $parts, 'total' => $total, 'images' => $images, 'images_dropped' => $dropped];
    }
}

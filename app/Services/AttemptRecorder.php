<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Example;
use Illuminate\Support\Facades\DB;

/**
 * Ghi 1 lượt thi + chi tiết từng câu (`attempt_answers`).
 *
 * Client gửi `answers: [{ question_id, response, correct, answer_ids? }]` cho các câu đã
 * làm. `response` là chỉ số trong mảng đáp án client đang hiển thị (có thể đã xáo) →
 * `answer_ids` (id đáp án theo đúng thứ tự client hiển thị) dùng để quy về id đáp án.
 * Không có `answer_ids` thì coi như thứ tự trong DB.
 *
 * Đúng / sai do SERVER tự chấm từ câu trả lời (cùng quy tắc với frontend — utils/questionTypes
 * gradeQuestion); mọi số liệu điểm client gửi lên (score, correct, earned...) đều bị bỏ qua
 * → không làm giả được bảng xếp hạng.
 *
 * Có tính là 1 lượt thi không: xem Attempt::countable() — kiểm tra bằng số câu server đếm
 * được, không tin cách kết thúc client gửi (báo "làm hết" mà còn câu trống → coi là "nộp").
 */
class AttemptRecorder
{
    public function __construct(private ExampleContent $content) {}

    /**
     * @param  array  $data  dữ liệu đã validate của AttemptController::store
     * @return Attempt|null  null khi không đủ điều kiện tính lượt thi (xem Attempt::countable)
     */
    public function record(Example $example, int $userId, array $data): ?Attempt
    {
        $given = collect($data['answers'] ?? [])
            ->filter(fn ($a) => is_array($a) && isset($a['question_id']))
            ->keyBy(fn ($a) => (int) $a['question_id']);

        $rows = [];
        $position = 0;
        foreach ($this->content->toArray($example) as $part) {
            foreach ($part['questions'] as $question) {
                $answer = $given->get($question['id']);
                $response = $answer === null ? null : $this->normalize($question, $answer);
                // Chưa trả lời đủ (vd ghép thiếu cặp) → coi như chưa làm câu này.
                $correct = $this->isComplete($question, $response) ? $this->grade($question, $response) : null;
                $points = (float) ($question['points'] ?? 1);
                $snapshot = $question;
                unset($snapshot['id']);

                $rows[] = [
                    'question_id' => $question['id'],
                    'position' => $position++,
                    'part_name' => $part['name'],
                    'question' => $snapshot,
                    'response' => $response,
                    'is_correct' => $correct,
                    'points' => $points,
                    'earned_points' => $correct ? $points : 0,
                ];
            }
        }

        $reason = $data['end_reason'] ?? Attempt::SUBMITTED; // client cũ không gửi → coi là nộp
        $answered = collect($rows)->whereNotNull('is_correct');
        // Không khớp câu nào (đề đã đổi / gửi bậy) → không ghi, không tin số liệu client.
        if ($answered->isEmpty()) {
            return null;
        }
        if ($reason === Attempt::FINISHED && $answered->count() < count($rows)) {
            $reason = Attempt::SUBMITTED;
        }
        if (!Attempt::countable($reason, $answered->count(), count($rows))) {
            return null;
        }
        $total = collect($rows)->sum('points');
        $earned = collect($rows)->sum('earned_points');
        $summary = [
            'score' => $total > 0 ? round(10 * $earned / $total, 1) : 0,
            'earned_points' => $earned,
            'total_points' => $total,
            'correct_count' => $answered->where('is_correct', true)->count(),
            'wrong_count' => $answered->where('is_correct', false)->count(),
            'unanswered_count' => count($rows) - $answered->count(),
        ];

        return DB::transaction(function () use ($example, $userId, $data, $summary, $rows, $reason) {
            // Không chạm updated_at: chỉ sửa nội dung đề mới tính là "cập nhật" (lastmod sitemap).
            $example->timestamps = false;
            $example->increment('count_test');
            $example->timestamps = true;

            $attempt = Attempt::create([
                ...$summary,
                'user_id' => $userId,
                'example_id' => $example->id,
                'example_name' => $example->name,
                'duration_seconds' => $data['duration_seconds'] ?? null,
                'end_reason' => $reason,
                'client_key' => $data['client_key'] ?? null,
            ]);
            if ($rows) {
                $attempt->answers()->createMany($rows);
            }

            return $attempt;
        });
    }

    /** Số câu đã trả lời (để kiểm tra thời gian làm bài tối thiểu trước khi ghi). */
    public function answeredCount(Example $example, array $data): int
    {
        $given = collect($data['answers'] ?? [])
            ->filter(fn ($a) => is_array($a) && isset($a['question_id']))
            ->keyBy(fn ($a) => (int) $a['question_id']);
        $count = 0;
        foreach ($this->content->toArray($example) as $part) {
            foreach ($part['questions'] as $question) {
                $answer = $given->get($question['id']);
                if ($answer !== null && $this->isComplete($question, $this->normalize($question, $answer))) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** Câu trả lời đã đủ để chấm chưa (giống isResponseComplete ở frontend). */
    private function isComplete(array $question, mixed $response): bool
    {
        $n = count($question['answers']);

        return match ($question['type']) {
            'fill_blank' => is_string($response) && trim($response) !== '',
            'ordering' => is_array($response) && count($response) === $n,
            'matching' => is_array($response) && count($response) === $n
                && collect($response)->every(fn ($pair) => $pair[0] !== null && $pair[1] !== null),
            default => is_array($response) && count($response) > 0,
        };
    }

    /**
     * Chấm 1 câu (đúng / sai toàn câu) — cùng quy tắc với frontend (utils/questionTypes gradeQuestion).
     * `$response` đã chuẩn hoá theo id đáp án (normalize()).
     */
    private function grade(array $question, mixed $response): bool
    {
        $answers = collect($question['answers'])->keyBy('id');
        $correctIds = $answers->filter(fn ($a) => !empty($a['is_correct']))->keys()->map('intval')->sort()->values()->all();

        switch ($question['type']) {
            case 'multiple_choice':
                $chosen = collect($response)->map('intval')->unique()->sort()->values()->all();

                return $chosen === $correctIds;
            case 'fill_blank':
                $cs = !empty($question['settings']['case_sensitive']);
                $value = $this->fillText($response, $cs);
                foreach ($answers as $answer) {
                    $option = (string) $answer['option'];
                    // Đáp án có thể lưu dạng HTML đã escape (nhập từ CSV) → so cả bản đã giải mã.
                    $plain = html_entity_decode(strip_tags($option), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if ($value === $this->fillText($option, $cs) || $value === $this->fillText($plain, $cs)) {
                        return true;
                    }
                }

                return false;
            case 'ordering':
                // So theo NỘI DUNG từng vị trí với thứ tự đúng trong DB (2 mục trùng chữ vẫn đúng).
                $expected = array_values($question['answers']);
                foreach (array_values($response) as $i => $id) {
                    if (trim((string) ($answers[$id]['option'] ?? "\0")) !== trim((string) ($expected[$i]['option'] ?? ''))) {
                        return false;
                    }
                }

                return true;
            case 'matching':
                // Cặp [vế trái, vế phải]: đúng khi vế phải được chọn có cùng nội dung ghép với vế trái.
                $lefts = [];
                foreach ($response as [$left, $right]) {
                    $want = trim((string) ($answers[$left]['meta']['match'] ?? "\0"));
                    $got = trim((string) ($answers[$right]['meta']['match'] ?? "\1"));
                    if ($want !== $got) {
                        return false;
                    }
                    $lefts[$left] = true;
                }

                // Mỗi vế trái đúng 1 lần.
                return count($lefts) === $answers->count();
            default: // single_choice, true_false
                $chosen = collect($response)->map('intval')->unique()->values()->all();

                return count($chosen) === 1 && in_array($chosen[0], $correctIds, true);
        }
    }

    /** Giống normalizeFill ở frontend: NFC, gộp khoảng trắng, (không phân biệt hoa thường). */
    private function fillText(mixed $value, bool $caseSensitive): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        if (class_exists(\Normalizer::class)) {
            $text = \Normalizer::normalize($text, \Normalizer::FORM_C) ?: $text;
        }
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return $caseSensitive ? $text : mb_strtolower($text, 'UTF-8');
    }

    /** Câu trả lời của client (chỉ số đáp án) → dạng lưu trữ theo id đáp án. */
    private function normalize(array $question, array $answer): mixed
    {
        $response = $answer['response'] ?? null;
        $dbIds = array_column($question['answers'], 'id');
        $shown = array_values(array_filter((array) ($answer['answer_ids'] ?? []), 'is_numeric'));
        // Chỉ tin `answer_ids` khi đúng là các đáp án của câu này.
        $order = count($shown) === count($dbIds) && !array_diff($shown, $dbIds)
            ? array_map('intval', $shown)
            : $dbIds;
        $id = fn ($i) => is_numeric($i) && (int) $i >= 0 ? ($order[(int) $i] ?? null) : null;

        return match ($question['type']) {
            'fill_blank' => is_scalar($response) ? mb_substr((string) $response, 0, 1000) : null,
            'matching' => is_array($response)
                ? collect($response)->map(fn ($right, $left) => [$id($left), $id($right)])->values()->all()
                : null,
            default => array_values(array_filter(
                array_map($id, is_array($response) ? $response : [$response]),
                fn ($v) => $v !== null,
            )),
        };
    }
}

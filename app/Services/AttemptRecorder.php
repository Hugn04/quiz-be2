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
 * Đúng / sai lấy theo client (cùng logic chấm với frontend); số câu đúng / sai / chưa làm,
 * điểm đạt, tổng điểm, điểm thang 10 tính lại từ các câu → luôn khớp với chi tiết.
 */
class AttemptRecorder
{
    public function __construct(private ExampleContent $content) {}

    /**
     * @param  array  $data  dữ liệu đã validate của AttemptController::store
     * @return Attempt|null  null khi không có câu nào được trả lời
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
                $correct = $answer === null ? null : (bool) ($answer['correct'] ?? false);
                $points = (float) ($question['points'] ?? 1);
                $snapshot = $question;
                unset($snapshot['id']);

                $rows[] = [
                    'question_id' => $question['id'],
                    'position' => $position++,
                    'part_name' => $part['name'],
                    'question' => $snapshot,
                    'response' => $answer === null ? null : $this->normalize($question, $answer),
                    'is_correct' => $correct,
                    'points' => $points,
                    'earned_points' => $correct ? $points : 0,
                ];
            }
        }

        $answered = collect($rows)->whereNotNull('is_correct');
        if ($answered->isEmpty()) {
            // Đề đã đổi trong lúc làm (không khớp câu nào) → giữ số liệu client gửi, không có chi tiết.
            if (($data['correct_count'] ?? 0) + ($data['wrong_count'] ?? 0) < 1) {
                return null;
            }
            $rows = [];
            $summary = [
                'score' => round((float) $data['score'], 2),
                'earned_points' => $data['earned_points'] ?? null,
                'total_points' => $data['total_points'] ?? null,
                'correct_count' => $data['correct_count'],
                'wrong_count' => $data['wrong_count'],
                'unanswered_count' => $data['unanswered_count'] ?? null,
            ];
        } else {
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
        }

        return DB::transaction(function () use ($example, $userId, $data, $summary, $rows) {
            $example->increment('count_test');

            $attempt = Attempt::create([
                ...$summary,
                'user_id' => $userId,
                'example_id' => $example->id,
                'example_name' => $example->name,
                'duration_seconds' => $data['duration_seconds'] ?? null,
            ]);
            if ($rows) {
                $attempt->answers()->createMany($rows);
            }

            return $attempt;
        });
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

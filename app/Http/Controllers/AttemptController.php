<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Example;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttemptController extends Controller
{
    /**
     * POST /examples/{example}/attempts — nộp 1 lượt làm bài.
     * Frontend có thể gửi bằng navigator.sendBeacon (body JSON dạng text) khi rời trang.
     * Lượt chưa trả lời câu nào bị từ chối (không tính lượt thi).
     */
    public function store(Request $request, Example $example)
    {
        $raw = json_decode($request->getContent(), true);
        if (is_array($raw)) {
            $request->merge($raw);
        }

        $data = $request->validate([
            'score' => ['required', 'numeric', 'min:0', 'max:10'],
            'earned_points' => ['nullable', 'numeric', 'min:0'],
            'total_points' => ['nullable', 'numeric', 'min:0'],
            'correct_count' => ['required', 'integer', 'min:0', 'max:65535'],
            'wrong_count' => ['required', 'integer', 'min:0', 'max:65535'],
            'unanswered_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'answers' => ['nullable', 'array', 'max:2000'],
        ]);

        if ($data['correct_count'] + $data['wrong_count'] < 1) {
            return response()->json(['message' => 'Chưa trả lời câu nào, không ghi nhận lượt thi.'], 422);
        }

        $attempt = DB::transaction(function () use ($data, $example) {
            $example->increment('count_test');

            return Attempt::create([
                ...$data,
                'score' => round($data['score'], 2),
                'user_id' => auth()->id(),
                'example_id' => $example->id,
            ]);
        });

        return response()->json($attempt, 201);
    }

    /**
     * GET /examples/{example}/leaderboard — top 10 (điểm cao nhất của mỗi người; cùng điểm
     * thì ai đạt trước xếp trên) + điểm, thứ hạng, lịch sử gần đây của tôi.
     * Giữ khóa `scores` / `user_score` như API cũ.
     */
    public function index(Example $example)
    {
        $best = fn () => DB::table('attempts')
            ->where('example_id', $example->id)
            ->groupBy('user_id')
            ->select(
                'user_id',
                DB::raw('MAX(score) as score'),
                DB::raw('COUNT(*) as attempt_count'),
                DB::raw('MIN(created_at) as created_at'),
            );

        $top = $best()->orderByDesc('score')->orderBy('created_at')->limit(10)->get();
        $users = User::whereIn('id', $top->pluck('user_id'))
            ->get(['id', 'slug', 'name', 'avatar', 'email', 'role'])
            ->keyBy('id');

        $scores = $top->values()->map(fn ($row, $i) => [
            'user_id' => $row->user_id,
            'example_id' => $example->id,
            'score' => (float) $row->score,
            'attempt_count' => (int) $row->attempt_count,
            'ranking' => $i + 1,
            'created_at' => $row->created_at,
            'user' => $users[$row->user_id] ?? null,
        ]);

        $me = auth()->user();
        $mine = $best()->where('user_id', $me->id)->first();
        $userScore = ['user' => $me->only(['id', 'slug', 'name', 'avatar', 'role'])];
        if ($mine) {
            $better = DB::query()
                ->fromSub($best(), 'b')
                ->where('score', '>', $mine->score)
                ->count();
            $userScore += [
                'user_id' => $me->id,
                'example_id' => $example->id,
                'score' => (float) $mine->score,
                'attempt_count' => (int) $mine->attempt_count,
                'ranking' => $better + 1,
            ];
        }

        $recent = Attempt::where('example_id', $example->id)
            ->where('user_id', $me->id)
            ->latest()
            ->limit(5)
            ->get(['id', 'score', 'earned_points', 'total_points', 'correct_count', 'wrong_count',
                'unanswered_count', 'duration_seconds', 'created_at']);

        return response()->json([
            'scores' => $scores,
            'user_score' => $userScore,
            'my_attempts' => $recent,
        ]);
    }
}

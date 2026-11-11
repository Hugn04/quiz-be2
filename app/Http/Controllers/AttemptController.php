<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Example;
use App\Models\User;
use App\Services\AttemptRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttemptController extends Controller
{
    /**
     * POST /examples/{example}/attempts — nộp 1 lượt làm bài.
     * Frontend có thể gửi bằng navigator.sendBeacon (body JSON dạng text) khi rời trang.
     * Lượt chưa trả lời câu nào bị từ chối (không tính lượt thi).
     * Chi tiết từng câu lưu ở attempt_answers (xem AttemptRecorder).
     */
    public function store(Request $request, Example $example, AttemptRecorder $recorder)
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
            'answers.*.question_id' => ['nullable', 'integer'],
            'answers.*.correct' => ['required', 'boolean'],
            'answers.*.response' => ['nullable'],
            'answers.*.answer_ids' => ['nullable', 'array', 'max:100'],
        ]);

        $attempt = $recorder->record($example, auth()->id(), $data);
        if (!$attempt) {
            return response()->json(['message' => 'Chưa trả lời câu nào, không ghi nhận lượt thi.'], 422);
        }

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
            ->get(['ulid', 'score', 'earned_points', 'total_points', 'correct_count', 'wrong_count',
                'unanswered_count', 'duration_seconds', 'created_at']);

        return response()->json([
            'scores' => $scores,
            'user_score' => $userScore,
            'my_attempts' => $recent,
        ]);
    }

    /**
     * GET /users/me/attempts — lịch sử làm bài của tôi (mới nhất trước), phân trang.
     * ?example={slug} lọc theo đề, ?per_page= (≤ 50, mặc định 20).
     */
    public function mine(Request $request)
    {
        $request->validate([
            'example' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $attempts = Attempt::where('user_id', auth()->id())
            ->when($request->query('example'), fn ($q, $slug) => $q->whereHas(
                'example',
                fn ($e) => $e->where('slug', $slug),
            ))
            ->with('example:id,slug,name')
            ->latest()
            ->orderByDesc('id')
            ->paginate((int) $request->query('per_page', 20));

        $attempts->getCollection()->transform(fn (Attempt $attempt) => $this->summary($attempt));

        return response()->json($attempts);
    }

    /** GET /attempts/{attempt} — chi tiết 1 lượt thi của tôi: từng câu, câu trả lời, đúng / sai. */
    public function show(Attempt $attempt)
    {
        abort_unless((int) $attempt->user_id === (int) auth()->id(), 404);

        $attempt->load(['example:id,slug,name', 'answers']);

        return response()->json([
            ...$this->summary($attempt),
            'answers' => $attempt->answers,
        ]);
    }

    /** Dữ liệu chung của 1 lượt thi; `example` null khi đề đã bị xóa. */
    private function summary(Attempt $attempt): array
    {
        return [
            'ulid' => $attempt->ulid,
            'score' => $attempt->score,
            'earned_points' => $attempt->earned_points,
            'total_points' => $attempt->total_points,
            'correct_count' => $attempt->correct_count,
            'wrong_count' => $attempt->wrong_count,
            'unanswered_count' => $attempt->unanswered_count,
            'duration_seconds' => $attempt->duration_seconds,
            'created_at' => $attempt->created_at,
            'example_name' => $attempt->example?->name ?? $attempt->example_name,
            'example' => $attempt->example?->only(['slug', 'name']),
        ];
    }
}

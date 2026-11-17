<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Example;
use App\Models\User;
use App\Services\AttemptRecorder;
use App\Services\AttemptToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AttemptController extends Controller
{
    /**
     * POST /examples/{example}/attempts — nộp 1 lượt làm bài.
     * Frontend có thể gửi bằng navigator.sendBeacon (body JSON dạng text) khi rời trang.
     *
     * - `end_reason` (finished / submitted / left) + số câu đã làm quyết định có tính lượt
     *   không (Attempt::countable) → không đủ: 422, KHÔNG tăng lượt thi.
     * - `client_key` (uuid / lượt): gửi lại cùng khóa (beacon + lỗi mạng) → trả lượt đã có (200).
     * Chi tiết từng câu lưu ở attempt_answers (xem AttemptRecorder).
     * - Chống làm giả bảng xếp hạng: server tự chấm (bỏ qua điểm client gửi); phải có
     *   `attempt_token` (vé lấy lúc mở đề) và đủ thời gian làm bài thật (AttemptToken).
     */
    public function store(Request $request, Example $example, AttemptRecorder $recorder)
    {
        abort_unless($example->viewableBy(auth('api')->user()), 404);
        $raw = json_decode($request->getContent(), true);
        if (is_array($raw)) {
            $request->merge($raw);
        }

        $data = $request->validate([
            // Điểm / số câu client tự tính: chỉ nhận cho tương thích, server KHÔNG dùng (tự chấm lại).
            'score' => ['nullable', 'numeric', 'min:0', 'max:10'],
            'earned_points' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'total_points' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'correct_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'wrong_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'unanswered_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'attempt_token' => ['nullable', 'string', 'max:1000'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'answers' => ['nullable', 'array', 'max:2000'],
            'answers.*.question_id' => ['nullable', 'integer'],
            'answers.*.correct' => ['nullable', 'boolean'],
            'answers.*.response' => ['nullable'],
            'answers.*.answer_ids' => ['nullable', 'array', 'max:100'],
            'end_reason' => ['nullable', 'string', 'in:' . implode(',', Attempt::END_REASONS)],
            'client_key' => ['nullable', 'uuid'],
        ]);

        // Đã ghi lượt này rồi (gửi lại) → trả bản cũ, không cộng thêm lượt thi.
        if (!empty($data['client_key'])) {
            $existing = Attempt::where('user_id', auth()->id())
                ->where('client_key', $data['client_key'])
                ->first();
            if ($existing) {
                return response()->json($existing, 200);
            }
        }

        // Phải bắt đầu từ trang làm bài (có vé) và đủ thời gian làm thật: tính từ lúc mở đề hoặc
        // từ lượt trước của chính người này ở đề này (chặn nộp dồn nhiều lượt bằng 1 vé).
        $startedAt = AttemptToken::startedAt($data['attempt_token'] ?? null, auth()->id(), $example->id);
        if ($startedAt === null) {
            return response()->json([
                'message' => 'Lượt làm bài không hợp lệ — hãy mở lại đề và làm bài.',
                'code' => 'invalid_attempt',
            ], 422);
        }
        $lastAttempt = Attempt::where('user_id', auth()->id())->where('example_id', $example->id)->max('created_at');
        $since = max($startedAt, $lastAttempt ? strtotime((string) $lastAttempt) : 0);
        $elapsed = max(0, time() - $since);
        if ($elapsed < $recorder->answeredCount($example, $data) * AttemptToken::MIN_SECONDS_PER_ANSWER) {
            return response()->json([
                'message' => 'Bạn nộp bài quá nhanh — lượt này không được tính.',
                'code' => 'too_fast',
            ], 422);
        }
        // Thời gian làm bài: không vượt quá thời gian server đo được.
        $data['duration_seconds'] = min((int) ($data['duration_seconds'] ?? $elapsed), time() - $startedAt);

        try {
            $attempt = $recorder->record($example, auth()->id(), $data);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // 2 request cùng khóa đến cùng lúc (beacon + gửi lại) → bản kia đã ghi.
            return response()->json(
                Attempt::where('user_id', auth()->id())->where('client_key', $data['client_key'])->first(),
                200,
            );
        }
        if (!$attempt) {
            return response()->json([
                'message' => 'Lượt này chưa đủ để tính là 1 lượt thi.',
                'code' => 'not_counted',
            ], 422);
        }

        return response()->json($attempt, 201);
    }

    /**
     * GET /examples/{example}/leaderboard — CÔNG KHAI. Top 10 (điểm cao nhất của mỗi người; cùng điểm
     * thì ai đạt trước xếp trên) + điểm, thứ hạng, lịch sử gần đây của tôi.
     * Giữ khóa `scores` / `user_score` như API cũ.
     */
    public function index(Example $example)
    {
        abort_unless($example->viewableBy(auth('api')->user()), 404);
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
            ->get(['id', 'uuid', 'name', 'avatar', 'role']) // công khai → không kèm email
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

        // Công khai: khách xem được top 10; điểm / lịch sử của tôi chỉ khi đã đăng nhập.
        $me = auth('api')->user();
        if (!$me) {
            return response()->json([
                'scores' => $scores,
                'user_score' => null,
                'my_attempts' => [],
            ]);
        }
        $mine = $best()->where('user_id', $me->id)->first();
        $userScore = ['user' => $me->only(['id', 'uuid', 'name', 'avatar', 'role'])];
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
                'unanswered_count', 'duration_seconds', 'end_reason', 'created_at']);

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
            'end_reason' => $attempt->end_reason,
            'created_at' => $attempt->created_at,
            'example_name' => $attempt->example?->name ?? $attempt->example_name,
            'example' => $attempt->example?->only(['slug', 'name']),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Example;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** GET /admin/analytics?days=7|30|90 — số liệu cho trang Thống kê. */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate(['days' => ['nullable', Rule::in(['7', '30', '90'])]]);
        $days = (int) ($data['days'] ?? 30);
        $since = now()->subDays($days - 1)->startOfDay();

        // ---- Theo ngày: lượt thi, người dùng mới, đề mới ----
        $perDay = fn ($query) => $query->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as total')->groupBy('d')->pluck('total', 'd');
        $attempts = $perDay(Attempt::query());
        $users = $perDay(User::query());
        $examples = $perDay(Example::query());
        $daily = [];
        for ($day = $since->copy(); $day->lte(now()); $day->addDay()) {
            $key = $day->toDateString();
            $daily[] = [
                'date' => $key,
                'attempts' => (int) ($attempts[$key] ?? 0),
                'users' => (int) ($users[$key] ?? 0),
                'examples' => (int) ($examples[$key] ?? 0),
            ];
        }

        $window = Attempt::query()->where('attempts.created_at', '>=', $since);

        // ---- Điểm trung bình + phân bố điểm ----
        $summary = (clone $window)->selectRaw('COUNT(*) as total, AVG(score) as avg_score, COUNT(DISTINCT user_id) as players, AVG(duration_seconds) as avg_duration')->first();
        $buckets = (clone $window)->selectRaw('LEAST(FLOOR(score), 9) as b, COUNT(*) as total')->groupBy('b')->pluck('total', 'b');
        $distribution = [];
        for ($i = 0; $i < 10; $i++) {
            $distribution[] = ['from' => $i, 'to' => $i + 1, 'total' => (int) ($buckets[$i] ?? 0)];
        }

        // ---- Theo ngành ----
        $bySector = (clone $window)
            ->join('examples', 'examples.id', '=', 'attempts.example_id')
            ->join('sectors', 'sectors.id', '=', 'examples.sector_id')
            ->groupBy('sectors.id', 'sectors.name', 'sectors.slug')
            ->selectRaw('sectors.name, sectors.slug, COUNT(*) as attempts, AVG(attempts.score) as avg_score')
            ->orderByDesc('attempts')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'slug' => $r->slug, 'attempts' => (int) $r->attempts, 'avg_score' => round((float) $r->avg_score, 2)]);

        // ---- Đề được thi nhiều ----
        $topExamples = (clone $window)
            ->join('examples', 'examples.id', '=', 'attempts.example_id')
            ->groupBy('examples.id', 'examples.slug', 'examples.name')
            ->selectRaw('examples.slug, examples.name, COUNT(*) as attempts, AVG(attempts.score) as avg_score, COUNT(DISTINCT attempts.user_id) as players')
            ->orderByDesc('attempts')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name, 'attempts' => (int) $r->attempts, 'players' => (int) $r->players, 'avg_score' => round((float) $r->avg_score, 2)]);

        // ---- Câu khó nhất / đáng ngờ (≥ 5 lượt trả lời trong khoảng thời gian) ----
        $questionStats = DB::table('attempt_answers')
            ->join('attempts', 'attempts.id', '=', 'attempt_answers.attempt_id')
            ->join('questions', 'questions.id', '=', 'attempt_answers.question_id')
            ->join('parts', 'parts.id', '=', 'questions.part_id')
            ->join('examples', 'examples.id', '=', 'parts.example_id')
            ->where('attempts.created_at', '>=', $since)
            ->whereNotNull('attempt_answers.is_correct')
            ->groupBy('questions.id', 'questions.content', 'examples.slug', 'examples.name')
            ->havingRaw('COUNT(*) >= 5')
            ->selectRaw('questions.id, questions.content, examples.slug, examples.name as example_name, COUNT(*) as answered, SUM(attempt_answers.is_correct) as correct')
            ->orderByRaw('SUM(attempt_answers.is_correct) / COUNT(*) asc')
            ->orderByDesc('answered')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'content' => mb_strimwidth(trim(strip_tags((string) $r->content)), 0, 160, '…'),
                'slug' => $r->slug,
                'example_name' => $r->example_name,
                'answered' => (int) $r->answered,
                'correct_rate' => round(((int) $r->correct / max(1, (int) $r->answered)) * 100, 1),
            ]);

        return response()->json([
            'days' => $days,
            'since' => $since->toDateString(),
            'summary' => [
                'attempts' => (int) $summary->total,
                'players' => (int) $summary->players,
                'avg_score' => round((float) $summary->avg_score, 2),
                'avg_duration' => (int) round((float) $summary->avg_duration),
                'new_users' => array_sum(array_column($daily, 'users')),
                'new_examples' => array_sum(array_column($daily, 'examples')),
            ],
            'daily' => $daily,
            'distribution' => $distribution,
            'by_sector' => $bySector,
            'top_examples' => $topExamples,
            'hardest_questions' => $questionStats,
        ]);
    }
}

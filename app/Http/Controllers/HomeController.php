<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use App\Models\Example;
use App\Models\Sector;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Dữ liệu công khai cho trang chủ. */
class HomeController extends Controller
{
    /**
     * GET /examples/trending — đề được thi nhiều nhất trong tháng này (?limit=, mặc định 8, ≤ 24).
     * Mỗi đề có `month_attempts`. Tháng này chưa đủ đề có lượt thi → bù bằng đề nhiều lượt
     * thi nhất từ trước tới nay (month_attempts = 0) để section không trống.
     */
    public function trending(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 8), 1), 24);
        $since = now()->startOfMonth();

        $counts = Attempt::query()
            ->where('created_at', '>=', $since)
            ->whereNotNull('example_id')
            ->groupBy('example_id')
            ->select('example_id', DB::raw('COUNT(*) as month_attempts'))
            ->orderByDesc('month_attempts')
            ->orderByDesc('example_id')
            ->limit($limit)
            ->pluck('month_attempts', 'example_id');

        $trending = Example::whereIn('id', $counts->keys())->withAll()->get()
            ->sortBy(fn (Example $e) => [-$counts[$e->id], -$e->count_test])
            ->values();

        $fill = $trending->count() < $limit
            ? Example::whereNotIn('id', $counts->keys())->withAll()
                ->orderByDesc('count_test')->orderByDesc('id')
                ->limit($limit - $trending->count())->get()
            : collect();

        $data = $trending->concat($fill)->map(function (Example $e) use ($counts) {
            $e->setAttribute('month_attempts', (int) ($counts[$e->id] ?? 0));

            return $e;
        });

        return response()->json([
            'month' => $since->format('Y-m'),
            'data' => $data->values(),
        ]);
    }

    /** GET /stats — số liệu tổng quan + số đề theo ngành. */
    public function stats()
    {
        return response()->json([
            'examples' => Example::count(),
            'questions' => DB::table('questions')->count(),
            'attempts' => Attempt::count(),
            'users' => DB::table('users')->count(),
            'month_attempts' => Attempt::where('created_at', '>=', now()->startOfMonth())->count(),
            'sectors' => Sector::query()
                ->leftJoin('examples', 'examples.sector_id', '=', 'sectors.id')
                ->groupBy('sectors.id', 'sectors.slug', 'sectors.name')
                ->orderByDesc(DB::raw('COUNT(examples.id)'))
                ->orderBy('sectors.id')
                ->get(['sectors.slug', 'sectors.name', DB::raw('COUNT(examples.id) as examples_count')])
                ->map(fn ($s) => [
                    'slug' => $s->slug,
                    'name' => $s->name,
                    'examples_count' => (int) $s->examples_count,
                ]),
        ]);
    }
}

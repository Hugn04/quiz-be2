<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tìm ảnh không tải được (link chết, 4xx/5xx, không phải ảnh) và xóa link khỏi DB để giao
 * diện dùng ảnh mặc định. Dùng chung cho lệnh `images:prune-broken` và trang Công cụ.
 */
class BrokenImages
{
    /** [bảng, cột, xóa cả dòng thay vì đặt NULL] */
    public const TARGETS = [
        ['examples', 'image', false],
        ['users', 'avatar', false],
        ['users', 'banner', false],
        ['question_media', 'url', true],
    ];

    /** Mọi link ảnh đang dùng (không trùng). */
    public function urls()
    {
        return collect(self::TARGETS)
            ->flatMap(fn ($t) => DB::table($t[0])->whereNotNull($t[1])->where($t[1], '!=', '')->pluck($t[1]))
            ->unique()
            ->values();
    }

    /**
     * Kiểm tra song song (mỗi lượt `$concurrency` link). Trả về [url => lý do] các link hỏng.
     * `$onProgress(int $done)` gọi sau mỗi lượt.
     */
    public function scan(int $timeout = 10, int $concurrency = 10, ?callable $onProgress = null): array
    {
        return $this->scanList($this->urls()->all(), $timeout, $concurrency, $onProgress);
    }

    /** Như scan() nhưng với danh sách link cho sẵn. */
    public function scanList(array $urls, int $timeout = 8, int $concurrency = 15, ?callable $onProgress = null): array
    {
        $broken = [];
        $done = 0;
        foreach (collect($urls)->unique()->chunk($concurrency) as $chunk) {
            $chunk = $chunk->values();
            $http = $chunk->filter(fn ($u) => preg_match('~^https?://~i', $u))->values();
            foreach ($chunk->diff($http) as $url) {
                $broken[$url] = 'không phải link http(s)';
            }
            $responses = $http->isEmpty() ? [] : Http::pool(fn (Pool $pool) => $http->map(
                fn ($url) => $pool->as($url)->timeout($timeout)->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; ImageCheck/1.0)',
                    'Accept' => 'image/*,*/*;q=0.8',
                ])->get($url)
            )->all());
            foreach ($http as $url) {
                $reason = $this->reason($responses[$url] ?? null);
                if ($reason !== null) {
                    $broken[$url] = $reason;
                }
            }
            $done += $chunk->count();
            if ($onProgress) {
                $onProgress($done);
            }
        }

        return $broken;
    }

    /** Chi tiết chỗ dùng từng link hỏng: [[column, count, url, reason]]. */
    public function usages(array $broken): array
    {
        $rows = [];
        foreach (self::TARGETS as [$table, $column]) {
            $counts = DB::table($table)->whereIn($column, array_keys($broken))
                ->selectRaw("{$column} as url, COUNT(*) as total")->groupBy($column)->pluck('total', 'url');
            foreach ($counts as $url => $count) {
                $rows[] = ['column' => "{$table}.{$column}", 'count' => (int) $count, 'url' => $url, 'reason' => $broken[$url]];
            }
        }

        return $rows;
    }

    /** Xóa các link hỏng; trả về số dòng đã sửa. */
    public function prune(array $urls): int
    {
        if (!$urls) {
            return 0;
        }

        return DB::transaction(function () use ($urls) {
            $changed = 0;
            foreach (self::TARGETS as [$table, $column, $deleteRow]) {
                $query = DB::table($table)->whereIn($column, $urls);
                $changed += $deleteRow ? $query->delete() : $query->update([$column => null]);
            }

            return $changed;
        });
    }

    private function reason(mixed $res): ?string
    {
        if (!$res instanceof Response) {
            return 'không kết nối được';
        }
        if ($res->status() >= 400) {
            return 'HTTP ' . $res->status();
        }
        $type = strtolower((string) $res->header('Content-Type'));
        if ($type !== '' && !str_starts_with($type, 'image/')) {
            return 'không phải ảnh (' . strtok($type, ';') . ')';
        }
        if (strlen($res->body()) === 0) {
            return 'file rỗng';
        }

        return null;
    }
}

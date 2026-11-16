<?php

namespace App\Console\Commands;

use App\Services\BrokenImages;
use Illuminate\Console\Command;

/**
 * Tìm ảnh không tải được và xóa link khỏi DB để giao diện dùng ảnh mặc định.
 *
 *   php artisan images:prune-broken           # chỉ liệt kê (không sửa gì)
 *   php artisan images:prune-broken --apply   # xóa link hỏng
 */
class PruneBrokenImages extends Command
{
    protected $signature = 'images:prune-broken
        {--apply : Xóa link hỏng (mặc định chỉ liệt kê)}
        {--timeout=10 : Số giây chờ mỗi link}';

    protected $description = 'Xóa link ảnh không tải được để dùng ảnh mặc định';

    public function handle(BrokenImages $images): int
    {
        $total = $images->urls()->count();
        $this->info("Kiểm tra {$total} link ảnh...");
        $bar = $this->output->createProgressBar($total);
        $broken = $images->scan(max(1, (int) $this->option('timeout')), 10, fn ($done) => $bar->setProgress($done));
        $bar->finish();
        $this->newLine(2);

        if (!$broken) {
            $this->info('Không có ảnh hỏng.');

            return self::SUCCESS;
        }

        $this->table(['Cột', 'Số dòng', 'Link', 'Lý do'], array_map(
            fn ($r) => [$r['column'], $r['count'], mb_strimwidth($r['url'], 0, 70, '…'), $r['reason']],
            $images->usages($broken),
        ));

        if (!$this->option('apply')) {
            $this->warn('Chạy thử: chưa sửa gì. Thêm --apply để xóa các link trên.');

            return self::SUCCESS;
        }

        $changed = $images->prune(array_keys($broken));
        $this->info("Đã xóa link hỏng ở {$changed} dòng → giao diện dùng ảnh mặc định.");

        return self::SUCCESS;
    }
}

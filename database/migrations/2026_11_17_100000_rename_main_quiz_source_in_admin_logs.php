<?php

use App\Models\AdminLog;
use Illuminate\Database\Migrations\Migration;

/**
 * Mã "Nguồn chính" trong app đổi từ giá trị `bizapp` của API nguồn sang `MAIN`. Nhật ký nhập đề
 * cũ ghi `meta.source = "<bizapp> #<key>"` → đổi sang `MAIN #<key>` để kiểm tra trùng đề vẫn đúng.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename((string) config('services.quiz_source.bizapp'), 'MAIN');
    }

    public function down(): void
    {
        $this->rename('MAIN', (string) config('services.quiz_source.bizapp'));
    }

    private function rename(string $from, string $to): void
    {
        if ($from === '' || $to === '' || $from === $to) {
            return;
        }
        AdminLog::where('action', 'import.create')
            ->where('meta->source', 'like', "$from #%")
            ->each(function (AdminLog $log) use ($from, $to) {
                $meta = $log->meta;
                $meta['source'] = $to . substr($meta['source'], strlen($from));
                $log->meta = $meta;
                $log->save();
            });
    }
};

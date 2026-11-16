<?php

namespace App\Console\Commands;

use App\Services\ImageLocalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Chuyển ảnh ở trang ngoài trong nội dung đề (ảnh nhúng <img> trong câu hỏi / giải thích /
 * đáp án, ảnh câu hỏi, ảnh bìa đề) về Cloudinary của app; ảnh chết thì gỡ.
 *
 *   php artisan images:localize            # chỉ đếm (không sửa gì)
 *   php artisan images:localize --apply    # chuyển + sửa DB (chạy lại được, không upload trùng)
 */
class LocalizeImages extends Command
{
    protected $signature = 'images:localize {--apply : Chuyển ảnh và sửa DB (mặc định chỉ đếm)}';

    protected $description = 'Chuyển ảnh ngoài trong nội dung đề về Cloudinary, gỡ ảnh chết';

    /** [bảng, cột] chứa HTML có thể nhúng <img>. */
    private const HTML_COLUMNS = [
        ['questions', 'content'],
        ['questions', 'explanation'],
        ['answers', 'content'],
    ];

    /** [bảng, cột, xóa dòng khi ảnh chết] chứa 1 link ảnh. */
    private const URL_COLUMNS = [
        ['question_media', 'url', true],
        ['examples', 'image', false],
    ];

    public function handle(ImageLocalizer $localizer): int
    {
        $apply = (bool) $this->option('apply');
        $changedRows = 0;

        foreach (self::HTML_COLUMNS as [$table, $column]) {
            $query = DB::table($table)->where($column, 'like', '%<img%');
            $total = (clone $query)->count();
            $this->line("<info>{$table}.{$column}</info>: {$total} dòng có ảnh nhúng");
            if (!$apply || !$total) {
                continue;
            }
            $bar = $this->output->createProgressBar($total);
            $query->select('id', $column)->orderBy('id')->chunkById(100, function ($rows) use ($table, $column, $localizer, $bar, &$changedRows) {
                foreach ($rows as $row) {
                    $new = $localizer->localizeHtml($row->{$column});
                    // Gỡ ảnh hỏng xong mà câu trống trơn → giữ nguyên, báo để sửa tay.
                    if ($new !== $row->{$column} && $column === 'content' && $this->isBlank($new)) {
                        $this->needsFix[] = [$table, $row->id];
                    } elseif ($new !== $row->{$column}) {
                        DB::table($table)->where('id', $row->id)->update([$column => $new]);
                        $changedRows++;
                    }
                    $bar->advance();
                }
            });
            $bar->finish();
            $this->newLine();
        }

        foreach (self::URL_COLUMNS as [$table, $column, $deleteRow]) {
            $query = DB::table($table)->whereNotNull($column)->where($column, '!=', '')
                ->where($column, 'not like', '%res.cloudinary.com%');
            $total = (clone $query)->count();
            $this->line("<info>{$table}.{$column}</info>: {$total} ảnh ngoài");
            if (!$apply || !$total) {
                continue;
            }
            foreach ($query->select('id', $column)->get() as $row) {
                $new = $localizer->localize($row->{$column});
                if ($new === $row->{$column}) {
                    continue;
                }
                $deleteRow && $new === null
                    ? DB::table($table)->where('id', $row->id)->delete()
                    : DB::table($table)->where('id', $row->id)->update([$column => $new]);
                $changedRows++;
            }
        }

        if (!$apply) {
            $this->warn('Chạy thử: chưa sửa gì. Thêm --apply để chuyển ảnh.');

            return self::SUCCESS;
        }

        $s = $localizer->stats;
        $this->newLine();
        $this->table(['Upload mới', 'Đã có sẵn', 'Giữ link gốc (nặng / lỗi)', 'Ảnh chết đã gỡ', 'Sửa dòng'], [[
            $s['uploaded'], $s['reused'], $s['kept_heavy'], $s['broken'], $changedRows,
        ]]);

        if ($this->needsFix) {
            $this->warn(count($this->needsFix) . ' câu chỉ có ảnh hỏng (gỡ ảnh thì trống) — giữ nguyên, cần sửa tay:');
            $this->table(['Bảng', 'ID', 'Đề'], array_map(function ($r) {
                $slug = $r[0] === 'questions'
                    ? DB::table('questions')->join('parts', 'parts.id', '=', 'questions.part_id')
                        ->join('examples', 'examples.id', '=', 'parts.example_id')
                        ->where('questions.id', $r[1])->value('examples.slug')
                    : null;

                return [$r[0], $r[1], $slug ?? '—'];
            }, $this->needsFix));
        }

        return self::SUCCESS;
    }

    /** @var array<int, array{0:string,1:int}> */
    private array $needsFix = [];

    /** HTML không còn chữ lẫn ảnh. */
    private function isBlank(?string $html): bool
    {
        return trim(html_entity_decode(strip_tags((string) $html, '<img>'), ENT_QUOTES | ENT_HTML5), " \t\n\r\0\x0B\xC2\xA0") === '';
    }
}

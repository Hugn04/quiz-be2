<?php

namespace App\Http\Controllers;

use App\Models\AdminLog;
use App\Models\Attempt;
use App\Models\Example;
use App\Models\User;
use App\Services\BrokenImages;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** /admin/tools/* — công cụ quản trị. */
class AdminToolController extends Controller
{
    /** POST /admin/tools/images/scan — quét ảnh hỏng (không sửa gì). Chỉ administrator. */
    public function scanImages(BrokenImages $images)
    {
        @set_time_limit(300);
        $total = $images->urls()->count();
        $broken = $images->scan(8, 15);

        return response()->json([
            'checked' => $total,
            'broken' => count($broken),
            'items' => $images->usages($broken),
        ]);
    }

    /** POST /admin/tools/images/prune — Body { urls: [...] } (lấy từ kết quả quét). */
    public function pruneImages(Request $request, BrokenImages $images)
    {
        $data = $request->validate([
            'urls' => ['required', 'array', 'min:1', 'max:1000'],
            'urls.*' => ['string', 'max:2048'],
        ]);
        @set_time_limit(300);
        // Kiểm tra lại đúng các link được gửi — không tin danh sách phía client.
        $candidates = array_values(array_intersect($data['urls'], $images->urls()->all()));
        $broken = array_keys(array_filter(
            $images->scanList($candidates),
        ));
        $changed = $images->prune($broken);
        if ($changed) {
            AdminLog::record('images.prune', 'images', null, 'Ảnh hỏng', ['urls' => count($broken), 'rows' => $changed]);
        }

        return response()->json(['urls' => count($broken), 'rows' => $changed]);
    }

    /** GET /admin/tools/export/users — CSV người dùng. Chỉ administrator. */
    public function exportUsers(): StreamedResponse
    {
        AdminLog::record('export.users', 'export', null, 'Người dùng');

        return $this->csv('nguoi-dung-' . now()->format('Ymd-His') . '.csv',
            ['UUID', 'Tên', 'Email', 'Vai trò', 'Bị khóa', 'Được tạo đề', 'Số đề', 'Số lượt thi', 'Ngày tạo'],
            function ($put) {
                User::query()->withCount(['examples', 'attempts'])->orderBy('id')->chunk(500, function ($users) use ($put) {
                    foreach ($users as $u) {
                        $put([$u->uuid, $u->name, $u->email, $u->role, $u->isBanned() ? 'Có' : '', $u->canCreateExams() ? 'Có' : 'Không',
                            $u->examples_count, $u->attempts_count, optional($u->created_at)->format('Y-m-d H:i')]);
                    }
                });
            });
    }

    /** GET /admin/tools/export/examples/{example}/attempts — CSV lượt thi của 1 đề. */
    public function exportAttempts(Example $example): StreamedResponse
    {
        AdminLog::record('export.attempts', 'exam', $example->slug, $example->name);

        return $this->csv('luot-thi-' . $example->slug . '-' . now()->format('Ymd') . '.csv',
            ['Thời gian', 'Người thi', 'Email', 'Điểm (/10)', 'Đúng', 'Sai', 'Bỏ trống', 'Thời gian làm (giây)'],
            function ($put) use ($example) {
                Attempt::query()->where('example_id', $example->id)->with('user:id,name,email')->orderBy('id')
                    ->chunk(500, function ($attempts) use ($put) {
                        foreach ($attempts as $a) {
                            $put([optional($a->created_at)->format('Y-m-d H:i:s'), $a->user?->name, $a->user?->email, $a->score,
                                $a->correct_count, $a->wrong_count, $a->unanswered_count, $a->duration_seconds]);
                        }
                    });
            });
    }

    /** CSV UTF-8 có BOM (Excel mở đúng tiếng Việt). */
    private function csv(string $filename, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            $rows(function (array $row) use ($out) {
                // Chặn CSV injection (ô bắt đầu bằng = + - @ bị Excel hiểu là công thức).
                fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v, $row));
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Upload / xóa ảnh trên Cloudinary.
 *
 * Xóa ảnh do server tự làm khi bản ghi đổi ảnh hoặc bị xóa (không còn cho client gửi
 * public id để xóa). An toàn:
 *  - chỉ xóa ảnh thuộc cloud + thư mục của app (avatar Google, link ngoài → bỏ qua);
 *  - chỉ xóa sau khi transaction commit (lưu thất bại thì ảnh cũ còn nguyên);
 *  - bỏ qua nếu ẢNH (theo public id, không theo nguyên chuỗi URL) vẫn còn được bản ghi khác
 *    dùng — chặn chiêu gắn link ảnh người khác kèm "?x" / transformation vào đề của mình
 *    rồi xoá đề để xoá luôn ảnh của họ;
 *  - lỗi Cloudinary chỉ ghi log, không làm hỏng request.
 */
class CloudinaryImages
{
    /** Bảng / cột đang lưu URL ảnh Cloudinary. */
    private const REFERENCES = [
        ['examples', 'image'],
        ['users', 'avatar'],
        ['users', 'banner'],
        ['question_media', 'url'],
    ];

    public function folder(): string
    {
        return trim((string) config('cloudinary.folder', 'test'), '/');
    }

    public function upload(UploadedFile $file): string
    {
        return cloudinary()->upload($file->getRealPath(), ['folder' => $this->folder()])->getSecurePath();
    }

    /** Ảnh cũ bị thay bằng ảnh khác (hoặc bỏ ảnh) → xóa ảnh cũ. */
    public function replaced(?string $old, ?string $new): void
    {
        if ($old && $old !== $new) {
            $this->delete($old);
        }
    }

    /** Xóa ảnh theo URL (sau khi transaction hiện tại commit, nếu có). */
    public function delete(?string $url): void
    {
        $publicId = $this->publicId($url);
        if (!$publicId) {
            return;
        }

        DB::afterCommit(function () use ($publicId) {
            if ($this->stillUsed($publicId)) {
                return;
            }
            try {
                cloudinary()->destroy($publicId);
            } catch (Throwable $e) {
                Log::warning('Không xóa được ảnh Cloudinary', ['public_id' => $publicId, 'error' => $e->getMessage()]);
            }
        });
    }

    /**
     * public id từ URL ảnh của app, vd
     * https://res.cloudinary.com/{cloud}/image/upload/v123/{folder}/abc.jpg → {folder}/abc.
     * Không phải ảnh của cloud / thư mục này → null.
     */
    public function publicId(?string $url): ?string
    {
        if (!$url || !$this->cloudName()) {
            return null;
        }
        $pattern = '~^https?://res\.cloudinary\.com/' . preg_quote($this->cloudName(), '~')
            . '/image/upload/(?:[^/]+/)*?(?:v\d+/)?(' . preg_quote($this->folder(), '~') . '/[^?#]+?)(?:\.[a-z0-9]+)?(?:[?#].*)?$~i';

        return preg_match($pattern, $url, $m) ? rawurldecode($m[1]) : null;
    }

    private function cloudName(): ?string
    {
        $host = parse_url((string) config('cloudinary.cloud_url'), PHP_URL_HOST);

        return $host ?: null;
    }

    /**
     * Ảnh (public id) còn được dùng ở đâu không: cột ảnh, cấu hình giao diện, <img> trong nội dung.
     * So theo public id → mọi biến thể URL (?query, transformation, http/https, hoa thường) của
     * cùng một ảnh đều được tính. Khớp rộng (vd "abc" khớp cả "abcd") chỉ làm GIỮ LẠI ảnh thừa,
     * không bao giờ xoá nhầm.
     */
    private function stillUsed(string $publicId): bool
    {
        $like = '%' . addcslashes($publicId, '%_\\') . '%';
        $columns = [...self::REFERENCES, ['settings', 'value'], ['questions', 'content'], ['questions', 'explanation'], ['answers', 'content']];
        foreach ($columns as [$table, $column]) {
            if (DB::table($table)->where($column, 'like', $like)->exists()) {
                return true;
            }
        }

        return false;
    }
}

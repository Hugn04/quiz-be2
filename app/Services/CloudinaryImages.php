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
 *  - bỏ qua nếu URL vẫn còn được bản ghi khác dùng;
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

        DB::afterCommit(function () use ($url, $publicId) {
            if ($this->stillUsed($url)) {
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

    private function stillUsed(string $url): bool
    {
        foreach (self::REFERENCES as [$table, $column]) {
            if (DB::table($table)->where($column, $url)->exists()) {
                return true;
            }
        }

        return false;
    }
}

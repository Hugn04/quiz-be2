<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chuyển ảnh ở trang ngoài (S3 PESTHUBT, nguồn đề ngoài, ảnh base64...) về Cloudinary của app.
 *
 *  - Mỗi link nguồn → 1 public id cố định (sha1 của link) trong `<folder>/imported`:
 *    chạy lại không upload trùng, nhiều câu dùng chung 1 ảnh chỉ tốn 1 lần.
 *  - Cloudinary tự tải ảnh từ link nguồn (server không phải tải về).
 *  - Ảnh nặng quá MAX_BYTES → giữ nguyên link gốc (không chuyển).
 *  - Ảnh chết (4xx/5xx, không phải ảnh, link rác) → null: người gọi gỡ ảnh đó đi.
 */
class ImageLocalizer
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** Đường dẫn tương đối của PESTHUBT nằm trên S3 này (giống frontend). */
    public const PESTHUBT_HOST = 'https://pesthubt.s3-ap-southeast-1.amazonaws.com';

    /** Kết quả đã xử lý trong lần chạy: link nguồn → link mới | null (hỏng) | link gốc (giữ). */
    private array $cache = [];

    /** Thống kê: uploaded | reused | kept_heavy | broken | skipped */
    public array $stats = ['uploaded' => 0, 'reused' => 0, 'kept_heavy' => 0, 'broken' => 0, 'skipped' => 0];

    public function __construct(private CloudinaryImages $images)
    {
    }

    /** Link đã là ảnh Cloudinary của app → không cần chuyển. */
    public function isLocal(string $url): bool
    {
        return $this->images->publicId($url) !== null;
    }

    /** Sửa link hỏng định dạng (thiếu "/" sau host S3, link tương đối của PESTHUBT). */
    public function normalize(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '' || str_starts_with($url, 'data:image/')) {
            return $url === '' ? null : $url;
        }
        if (str_starts_with($url, '//') && !str_starts_with($url, '//:')) {
            $url = 'https:' . $url;
        }
        $url = preg_replace('~^(https?://[^/]+\.amazonaws\.com)(?=[^/])~i', '$1/', $url);
        if (!preg_match('~^https?://[^/]+\.[^/]+/~i', $url)) {
            // Đường dẫn tương đối kiểu "uploads/img/1/2.jpg" của PESTHUBT.
            return preg_match('~^/?(uploads|user)/~i', $url) ? self::PESTHUBT_HOST . '/' . ltrim($url, '/') : null;
        }

        return $url;
    }

    /**
     * Link mới cho ảnh: link Cloudinary, link gốc (ảnh quá nặng / lỗi tạm thời), hoặc null
     * (ảnh chết → nên gỡ).
     */
    public function localize(string $url): ?string
    {
        if ($this->isLocal($url)) {
            $this->stats['skipped']++;

            return $url;
        }
        if (array_key_exists($url, $this->cache)) {
            return $this->cache[$url];
        }

        return $this->cache[$url] = $this->process($url);
    }

    /** Đổi mọi <img src> trong HTML; ảnh chết → gỡ cả thẻ <img>. */
    public function localizeHtml(?string $html): ?string
    {
        if ($html === null || stripos($html, '<img') === false) {
            return $html;
        }

        // Thẻ <img> bị cắt cụt (vd ảnh base64 vượt giới hạn cột TEXT 64KB) → bỏ phần hỏng.
        $html = preg_replace('~<img\b[^>]*$~i', '', $html);

        return preg_replace_callback('~<img\b[^>]*>~i', function ($m) {
            if (!preg_match('~\ssrc\s*=\s*(["\'])(.*?)\1~is', $m[0], $src)) {
                return '';
            }
            $new = $this->localize($src[2]);
            if ($new === null) {
                return '';
            }

            return $new === $src[2] ? $m[0] : str_replace($src[0], ' src=' . $src[1] . $new . $src[1], $m[0]);
        }, $html);
    }

    private function process(string $original): ?string
    {
        $url = $this->normalize($original);
        if ($url === null) {
            $this->stats['broken']++;

            return null;
        }
        $publicId = 'imported/' . sha1($url);

        if (!str_starts_with($url, 'data:')) {
            $size = $this->probe($url);
            if ($size === false) {
                $this->stats['broken']++;

                return null;
            }
            if ($size > self::MAX_BYTES) {
                $this->stats['kept_heavy']++;

                return $original;
            }
        } elseif (strlen($url) * 3 / 4 > self::MAX_BYTES) {
            $this->stats['kept_heavy']++;

            return $original;
        }

        try {
            $result = cloudinary()->upload($url, [
                'folder' => $this->images->folder(),
                'public_id' => $publicId,
                'overwrite' => false,
                'unique_filename' => false,
            ]);
            // overwrite=false: public id đã có (lần chạy trước) → Cloudinary trả ảnh cũ, không upload lại.
            $this->stats[($result->getResponse()['existing'] ?? false) ? 'reused' : 'uploaded']++;

            return $result->getSecurePath();
        } catch (Throwable $e) {
            // Lỗi tạm thời (mạng, giới hạn Cloudinary) → giữ link gốc để chạy lại sau.
            Log::warning('[images:localize] upload lỗi', ['url' => $url, 'error' => $e->getMessage()]);
            $this->stats['kept_heavy']++;

            return $original;
        }
    }

    /** Dung lượng ảnh (byte); false = ảnh chết / không phải ảnh. */
    private function probe(string $url): int|false
    {
        // Chống SSRF: không tải link trỏ vào mạng nội bộ / localhost / metadata.
        if (!\App\Support\SafeUrl::isPublicHttp($url)) {
            return false;
        }
        try {
            $res = Http::timeout(20)->withOptions(\App\Support\SafeUrl::guzzleOptions())->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; ImageImport/1.0)',
                'Range' => 'bytes=0-0',
            ])->get($url);
        } catch (Throwable) {
            return false;
        }
        if ($res->status() >= 400) {
            return false;
        }
        $type = strtolower((string) $res->header('Content-Type'));
        if ($type !== '' && !str_starts_with($type, 'image/') && !str_starts_with($type, 'binary/') && $type !== 'application/octet-stream') {
            return false;
        }
        $range = (string) $res->header('Content-Range');

        return str_contains($range, '/') ? (int) substr($range, strrpos($range, '/') + 1) : (int) $res->header('Content-Length');
    }
}

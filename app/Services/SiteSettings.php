<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Cấu hình giao diện site (bảng `settings`, key `appearance`), administrator chỉnh trong
 * trang quản trị:
 *  - primary_color: màu chủ đạo dạng #rrggbb (null = màu mặc định của giao diện);
 *  - logo, default_card, default_avatar, default_banner, avatar_frame (khung avatar của
 *    administrator / manager): link ảnh (null = ảnh mặc định
 *    có sẵn trong frontend).
 */
class SiteSettings
{
    public const KEY = 'appearance';

    /** Giá trị avatar_frame = không dùng khung (null = khung mặc định). */
    public const NO_FRAME = 'none';

    public const FIELDS = [
        'primary_color', 'logo', 'default_card', 'default_avatar', 'default_banner', 'avatar_frame',
        // Ảnh chia sẻ mạng xã hội mặc định (og:image), nên 1200×630.
        'og_image',
        // Thông tin site (null = giá trị mặc định của frontend).
        'site_name', 'site_description', 'contact_email', 'facebook_url', 'youtube_url', 'tiktok_url',
        // Thanh thông báo: { enabled, message, link, tone, starts_at, ends_at } | null.
        'announcement',
        // Duyệt đề trước khi đăng (true = đề người dùng thường tạo / sửa phải chờ duyệt).
        'exam_approval',
    ];

    /** Trường là ảnh (thay ảnh → xóa ảnh cũ trên Cloudinary). */
    public const IMAGE_FIELDS = ['logo', 'default_card', 'default_avatar', 'default_banner', 'avatar_frame', 'og_image'];

    public function __construct(private CloudinaryImages $images)
    {
    }

    /** Đang bật "Duyệt đề trước khi đăng"? */
    public function examApprovalRequired(): bool
    {
        return (bool) ($this->appearance()['exam_approval'] ?? false);
    }

    /** Cấu hình hiện tại, đủ mọi trường (thiếu = null). */
    public function appearance(): array
    {
        $raw = DB::table('settings')->where('key', self::KEY)->value('value');
        $value = is_string($raw) ? (json_decode($raw, true) ?: []) : [];

        return array_merge(array_fill_keys(self::FIELDS, null), array_intersect_key($value, array_flip(self::FIELDS)));
    }

    /** Cập nhật một phần (chỉ các trường có trong `$changes`); trả về cấu hình mới. */
    public function updateAppearance(array $changes): array
    {
        return DB::transaction(function () use ($changes) {
            $current = $this->appearance();
            $next = $current;
            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $changes)) {
                    $value = $changes[$field];
                    $next[$field] = $value === '' ? null : $value;
                }
            }
            if ($next['primary_color']) {
                $next['primary_color'] = strtolower($next['primary_color']);
            }

            DB::table('settings')->updateOrInsert(
                ['key' => self::KEY],
                ['value' => json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => now(), 'created_at' => now()],
            );

            // Ảnh cũ bị thay / bỏ → xóa trên Cloudinary (sau commit, nếu không còn chỗ nào dùng).
            foreach (self::IMAGE_FIELDS as $field) {
                $this->images->replaced($current[$field], $next[$field]);
            }

            return $next;
        });
    }
}

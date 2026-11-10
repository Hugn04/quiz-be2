<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Tự sinh `slug` duy nhất khi tạo bản ghi (vd "Mạng máy tính" → `mang-may-tinh`, trùng thì
 * `mang-may-tinh-2`...). Slug KHÔNG đổi khi sửa tên để link cũ vẫn dùng được.
 *
 * Model dùng trait phải khai báo `slugSource(): string`.
 */
trait HasSlug
{
    /** Slug không được cấp cho bản ghi (trùng với route đặc biệt như /users/me). */
    protected static array $reservedSlugs = ['me', 'this'];

    public static function bootHasSlug(): void
    {
        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = static::uniqueSlug($model->slugSource());
            }
        });
    }

    abstract public function slugSource(): string;

    public static function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'item';
        $slug = $base;
        $i = 2;
        while (
            in_array($slug, static::$reservedSlugs, true)
            || static::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }
}

<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Cookie;

/**
 * Cookie HttpOnly `token` chứa JWT.
 *
 * Tạo bằng Symfony Cookie (KHÔNG dùng cookie() / Cookie::make của Laravel) để không bị
 * kế thừa SESSION_DOMAIN / SESSION_SECURE_COOKIE / same_site của session — các giá trị đó
 * sai là trình duyệt từ chối hoặc không gửi cookie → đăng nhập xong bị out.
 *
 * Mặc định: host-only (không đặt Domain), Path=/, SameSite=Lax, Secure theo HTTPS.
 * Frontend gọi API qua proxy cùng tên miền (Next rewrites /api/*) nên cookie là first-party
 * của tên miền frontend.
 *
 * .env (tùy chọn):
 *  AUTH_COOKIE_DOMAIN=     để trống = host-only (khuyến nghị)
 *  AUTH_COOKIE_SAMESITE=lax  (lax | strict | none — none bắt buộc Secure)
 *  AUTH_COOKIE_SECURE=     để trống = tự theo request HTTPS
 */
class AuthCookie
{
    public const NAME = 'token';

    public static function make(string $token): Cookie
    {
        $minutes = (int) config('jwt.refresh_ttl');

        return self::build($token, time() + $minutes * 60);
    }

    /** Cookie hết hạn — cùng thuộc tính với cookie đã set thì trình duyệt mới xóa được. */
    public static function forget(): Cookie
    {
        return self::build('', 1);
    }

    private static function build(string $value, int $expires): Cookie
    {
        $sameSite = strtolower((string) config('auth.cookie.same_site', 'lax')) ?: 'lax';
        $secure = config('auth.cookie.secure');
        $secure = $secure === null || $secure === '' ? request()->isSecure() : (bool) $secure;
        if ($sameSite === 'none') {
            $secure = true; // trình duyệt bỏ cookie SameSite=None không có Secure
        }

        return Cookie::create(
            self::NAME,
            $value,
            $expires,
            '/',
            config('auth.cookie.domain') ?: null,
            $secure,
            true,   // HttpOnly
            false,  // raw
            $sameSite,
        );
    }
}

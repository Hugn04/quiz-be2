<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chặn JWT phát hành trước lần đổi/đặt lại mật khẩu gần nhất:
 * claim `ver` trong token phải bằng `users.token_version` hiện tại.
 * Đặt sau `auth:api` (lúc này token đã hợp lệ + có user).
 */
class EnsureTokenVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = auth('api');
        $user = $guard->user();
        if ($user && (int) $guard->payload()->get('ver') !== (int) $user->token_version) {
            $guard->invalidate();

            return response()->json(['message' => 'Phiên đăng nhập đã hết hạn, vui lòng đăng nhập lại.'], 401);
        }

        return $next($request);
    }
}

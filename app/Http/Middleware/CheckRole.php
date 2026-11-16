<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chỉ cho các role liệt kê đi qua, vd `checkRole:administrator,manager`.
 * Đọc role từ DB (user của token) → đổi / thu hồi role có hiệu lực ngay, không cần đăng nhập lại.
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = auth('api')->user();
        if (!$user) {
            return response()->json(['message' => 'Bạn chưa đăng nhập'], 401);
        }
        if ($user->role === User::ROLE_BAN) {
            return response()->json(['message' => 'Tài khoản của bạn đã bị khóa'], 403);
        }
        if (!in_array($user->role, $roles, true)) {
            return response()->json(['message' => 'Bạn không có quyền truy cập chức năng này'], 403);
        }

        return $next($request);
    }
}

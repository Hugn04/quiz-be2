<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /** GET /users/me — trang cá nhân của tôi. */
    public function me()
    {
        return response()->json($this->profile(auth()->user()));
    }

    /** GET /users/{slug} — trang cá nhân của 1 người dùng (kèm 4 đề gần nhất). */
    public function show(User $user)
    {
        return response()->json($this->profile($user));
    }

    /**
     * PATCH /users/me — sửa thông tin của tôi.
     * Chỉ cho sửa các trường an toàn (bản cũ update nguyên body → có thể tự đổi role).
     */
    public function updateMe(Request $request)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'avatar' => 'sometimes|nullable|string|max:2048',
            'banner' => 'sometimes|nullable|string|max:2048',
        ]);

        $user = auth()->user();
        $user->forceFill($data)->save();

        return response()->json($user->fresh());
    }

    /** GET /users?ids=1,2,3 — thông tin rút gọn của nhiều người dùng. */
    public function index(Request $request)
    {
        $ids = $request->query('ids');
        $ids = is_array($ids) ? $ids : array_filter(explode(',', (string) $ids));
        if (empty($ids)) {
            return response()->json(['message' => 'Thiếu tham số ids'], 422);
        }

        $users = User::whereIn('id', $ids)->select('id', 'name', 'avatar')->get();

        return response()->json($users);
    }

    private function profile(User $user): User
    {
        return $user->load([
            // withAll: tác giả, ngành + cờ đã thích / yêu thích của người xem.
            'examples' => fn ($q) => $q->withAll()->latest()->limit(4),
        ]);
    }
}

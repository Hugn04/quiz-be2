<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\CloudinaryImages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /** GET /users/me — trang cá nhân của tôi. */
    public function me()
    {
        return response()->json($this->profile(auth()->user()));
    }

    /** GET /users/{uuid} — trang cá nhân của 1 người dùng (kèm 4 đề gần nhất). */
    public function show(User $user)
    {
        return response()->json($this->profile($user));
    }

    /**
     * GET /users/{user}/card — CÔNG KHAI, chỉ tên + avatar + vai trò: dùng cho thẻ chia sẻ
     * (Facebook / Zalo không đăng nhập nên không gọi được API hồ sơ đầy đủ).
     */
    public function card(User $user)
    {
        abort_if($user->isBanned(), 404);

        return response()->json([
            'uuid' => $user->uuid,
            'name' => $user->name,
            'avatar' => $user->avatar,
            'role' => $user->role,
            'examples_count' => $user->examples()->visible()->count(),
        ]);
    }

    /**
     * PATCH /users/me — sửa thông tin của tôi.
     * Chỉ cho sửa các trường an toàn (bản cũ update nguyên body → có thể tự đổi role).
     */
    public function updateMe(Request $request, CloudinaryImages $images)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'avatar' => 'sometimes|nullable|string|max:2048',
            'banner' => 'sometimes|nullable|string|max:2048',
        ]);

        $user = auth()->user();
        DB::transaction(function () use ($user, $data, $images) {
            foreach (['avatar', 'banner'] as $field) {
                if (array_key_exists($field, $data)) {
                    $images->replaced($user->{$field}, $data[$field]);
                }
            }
            $user->forceFill($data)->save();
        });

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

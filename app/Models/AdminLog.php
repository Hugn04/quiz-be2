<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một dòng nhật ký quản trị (xem migration create_admin_logs_table). */
class AdminLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'actor_name', 'actor_role', 'action', 'target_type', 'target_id', 'target_label', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ghi nhật ký cho người đang đăng nhập (bỏ qua nếu không có ai). */
    public static function record(string $action, ?string $targetType = null, ?string $targetId = null, ?string $targetLabel = null, array $meta = []): void
    {
        $actor = auth('api')->user();
        static::create([
            'user_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'actor_role' => $actor?->role,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'target_label' => $targetLabel !== null ? mb_strimwidth($targetLabel, 0, 255) : null,
            'meta' => $meta ?: null,
        ]);
    }
}

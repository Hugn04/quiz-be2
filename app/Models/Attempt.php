<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một lượt làm bài (nộp bài) của người dùng với 1 đề. URL dùng `ulid`, không dùng id.
 * `example_id` null khi đề đã bị xóa (còn `example_name`).
 */
class Attempt extends Model
{
    use HasUlids;

    /*
     * Cách kết thúc lượt thi — quyết định có được tính là 1 lượt hay không (countable()):
     *  - FINISHED  : làm hết mọi câu → luôn tính;
     *  - SUBMITTED : chủ động bấm "Nộp bài" → tính nếu đã làm ≥ 1 câu;
     *  - LEFT      : bỏ dở (rời trang / tải lại / làm lại khi chưa xong) → chỉ tính khi đã
     *                làm đủ nhiều (leaveThreshold), tránh lượt rác kéo điểm trung bình xuống.
     * Vòng "Làm lại câu sai" chỉ để ôn → frontend không gửi.
     */
    public const FINISHED = 'finished';
    public const SUBMITTED = 'submitted';
    public const LEFT = 'left';
    public const END_REASONS = [self::FINISHED, self::SUBMITTED, self::LEFT];

    /** Bỏ dở cần làm ≥ nửa đề, tối đa 20 câu (đề 5 câu → 3, đề 37 câu → 19, đề 500 câu → 20). */
    public const LEAVE_MAX_REQUIRED = 20;

    public static function leaveThreshold(int $total): int
    {
        return max(1, min(self::LEAVE_MAX_REQUIRED, (int) ceil($total / 2)));
    }

    /** Lượt kết thúc theo `$reason` với `$answered` / `$total` câu có được tính không. */
    public static function countable(string $reason, int $answered, int $total): bool
    {
        return match ($reason) {
            self::FINISHED, self::SUBMITTED => $answered >= 1,
            self::LEFT => $answered >= self::leaveThreshold($total),
            default => false,
        };
    }

    protected $fillable = [
        'user_id',
        'example_id',
        'example_name',
        'score',
        'earned_points',
        'total_points',
        'correct_count',
        'wrong_count',
        'unanswered_count',
        'duration_seconds',
        'end_reason',
        'client_key',
    ];

    protected $hidden = ['id', 'client_key'];

    protected $casts = [
        'score' => 'float',
        'earned_points' => 'float',
        'total_points' => 'float',
    ];

    /** Sinh ulid cho cột `ulid` (khóa chính vẫn là id tăng dần). */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function example(): BelongsTo
    {
        return $this->belongsTo(Example::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(AttemptAnswer::class)->orderBy('position');
    }
}

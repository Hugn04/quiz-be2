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
    ];

    protected $hidden = ['id'];

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

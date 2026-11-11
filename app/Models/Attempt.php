<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một lượt làm bài (nộp bài) của người dùng với 1 đề. */
class Attempt extends Model
{
    protected $fillable = [
        'user_id',
        'example_id',
        'score',
        'earned_points',
        'total_points',
        'correct_count',
        'wrong_count',
        'unanswered_count',
        'duration_seconds',
        'answers',
    ];

    protected $casts = [
        'score' => 'float',
        'earned_points' => 'float',
        'total_points' => 'float',
        'answers' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function example(): BelongsTo
    {
        return $this->belongsTo(Example::class);
    }
}

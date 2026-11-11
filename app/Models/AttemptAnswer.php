<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một câu trong lượt thi: bản chụp câu hỏi lúc nộp + câu trả lời (quy ra id đáp án).
 * `is_correct` null = chưa làm. Xem migration create_attempt_answers_table.
 */
class AttemptAnswer extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'question_id',
        'position',
        'part_name',
        'question',
        'response',
        'is_correct',
        'points',
        'earned_points',
    ];

    protected $hidden = ['id', 'attempt_id'];

    protected $casts = [
        'question' => 'array',
        'response' => 'array',
        'is_correct' => 'boolean',
        'points' => 'float',
        'earned_points' => 'float',
    ];

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(Attempt::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}

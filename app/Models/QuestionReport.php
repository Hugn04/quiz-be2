<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Báo lỗi câu hỏi từ người làm đề. */
class QuestionReport extends Model
{
    public const REASONS = ['wrong_answer', 'typo', 'unclear', 'image', 'other'];

    public const STATUSES = ['open', 'resolved', 'dismissed'];

    protected $fillable = [
        'example_id', 'question_id', 'user_id', 'question_position', 'question_content',
        'reason', 'message', 'status', 'handled_by', 'handled_at', 'handle_note',
    ];

    protected $casts = [
        'handled_at' => 'datetime',
    ];

    public function example(): BelongsTo
    {
        return $this->belongsTo(Example::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}

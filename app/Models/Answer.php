<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Một phương án trả lời của câu hỏi. */
class Answer extends Model
{
    protected $fillable = ['content', 'is_correct', 'meta', 'position'];

    protected $casts = [
        'is_correct' => 'boolean',
        'meta' => 'array',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}

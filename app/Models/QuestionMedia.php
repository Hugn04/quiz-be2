<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ảnh (hoặc media khác qua cột `type`) đính kèm câu hỏi. */
class QuestionMedia extends Model
{
    protected $table = 'question_media';

    protected $fillable = ['type', 'url', 'position'];

    protected $attributes = [
        'type' => 'image',
    ];

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}

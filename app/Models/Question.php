<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một câu hỏi trong phần của đề. */
class Question extends Model
{
    /** Dạng mặc định; danh sách đầy đủ ở App\QuestionTypes\QuestionTypes. */
    public const TYPE_SINGLE_CHOICE = 'single_choice';

    protected $fillable = ['type', 'content', 'explanation', 'points', 'settings', 'position'];

    protected $attributes = [
        'type' => self::TYPE_SINGLE_CHOICE,
        'points' => 1,
    ];

    protected $casts = [
        'points' => 'float',
        'settings' => 'array',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class)->orderBy('position');
    }

    public function media(): HasMany
    {
        return $this->hasMany(QuestionMedia::class)->orderBy('position');
    }
}

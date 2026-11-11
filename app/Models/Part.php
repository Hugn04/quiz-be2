<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Một phần của đề thi (vd "Phần 1: Trắc nghiệm"). */
class Part extends Model
{
    protected $fillable = ['name', 'position'];

    public function example(): BelongsTo
    {
        return $this->belongsTo(Example::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class)->orderBy('position');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Score extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'example_id', 'score'];

    public function example()
    {
        return $this->belongsTo(Example::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

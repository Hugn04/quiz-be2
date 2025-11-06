<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Example extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'count_test',
        'count_like',
        'university',
        'degree',
        'created_at',
    ];
}

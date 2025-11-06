<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserAction extends Model
{
    public $timestamps = false;
    use HasFactory;
    protected $fillable = ['user_id', 'example_id', 'like', 'favorited'];
}

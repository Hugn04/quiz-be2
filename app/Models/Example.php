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
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')
            ->select(["id", "name", "email", "avatar", "role"]);
    }
    public function userActions()
    {
        return $this->hasMany(UserAction::class);
    }
    public function userAction()
    {
        return $this->hasOne(UserAction::class)
            ->where('user_id', auth('sanctum')->id())
            ->select('like', 'favorited',);
    }
}

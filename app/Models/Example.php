<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Example extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'count_test',
        'count_like',
        'num_question',
        'sector_id',
        'image',
        'created_at',
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id')
            ->select(["id", "name", "email", "avatar", "role"]);
    }
    public function sector()
    {
        return $this->belongsTo(Sector::class, 'sector_id');
    }
    public function userActions()
    {
        return $this->hasMany(UserAction::class);
    }
    public function userAction()
    {
        return $this->hasOne(UserAction::class)
            ->withDefault([
                'like' => false,
                'favorited' => false,
                'user_id' => null,
                'example_id' => $this->id, // nếu muốn
            ]);
    }
    public function scopeWithUserAction($query)
    {
        $id = auth('sanctum')->id();
        return $query->with(['userAction' => function ($q) use ($id) {
            $q->where('user_id', $id);
        }]);
    }
    public function scopeWithUser($query)
    {
        return $query->with(['user']);
    }
    public function scopeWithAll($query)
    {
        $id = auth('sanctum')->id();
        return $query->with(['user', 'sector'])->with(['userAction' => function ($q) use ($id) {
            $q->where('user_id', $id);
        }]);;
    }
    public function scopeWithSector($query)
    {
        return $query->with(['sector']);
    }
    public  function question()
    {
        return $this->belongsTo(Question::class);
    }
    public function scopeFilter($query, $filters)
    {
        return $query
            ->when($filters['search'] ?? null, function ($q, $search) {
                $q->where('name', 'like', "%$search%");
            })
            ->when($filters['orderby'] ?? null, function ($q, $orderby) {
                $q->orderBy(
                    'examples.count_test',
                    $orderby
                );
            });
        // ->when($filters['type'] ?? null, function ($q, $type) {
        //     $q->where('type', $type);
        // });
    }
}

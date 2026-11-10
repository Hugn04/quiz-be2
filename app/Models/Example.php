<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class Example extends Model
{
    use HasFactory, HasSlug;

    /** Cột được phép dùng trong ?orderBy= */
    public const SORTABLE = ['count_test', 'count_like', 'created_at', 'updated_at', 'num_question', 'name'];

    public function slugSource(): string
    {
        return (string) $this->name;
    }
    protected $fillable = [
        'name',
        'slug',
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
            ->select(["id", "slug", "name", "email", "avatar", "role"]);
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
    public function scopeMyFavorite($query, $search = null)
    {
        $id = auth('api')->id();
        return $query
            ->withAll()
            ->whereHas('userActions', function ($q) use ($id) {
                $q->where('user_id', $id)
                    ->where('favorited', true);
            })
            ->when($search, function ($q) use ($search) {
                $q->where('name', 'like', "%$search%");
            });
    }

    public function scopeWithUserAction($query)
    {
        $id = auth('api')->id();
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
        $id = auth('api')->id();
        return $query->with(['user', 'sector'])->with(['userAction' => function ($q) use ($id) {
            $q->where('user_id', $id);
        }]);
    }
    public function scopeWithSector($query)
    {
        return $query->with(['sector']);
    }
    public function question()
    {
        return $this->belongsTo(Question::class);
    }
    public function scopeFilter($query, $filters)
    {
        return $query
            // Tìm theo tên đề hoặc tên tác giả (gom trong 1 nhóm để không phá các điều kiện khác).
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($q) use ($filters) {
                // Escape % và _ để người dùng gõ ký tự này không thành wildcard.
                $search = '%' . addcslashes(trim($filters['search']), '%_\\') . '%';
                $q->where(function ($w) use ($search) {
                    $w->where('examples.name', 'like', $search)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $search));
                });
            })
            // Lọc theo slug ngành (vd ?sector=cong-nghe-thong-tin)
            ->when(!empty($filters['sector']), function ($q) use ($filters) {
                $q->whereHas('sector', fn ($s) => $s->where('slug', $filters['sector']));
            })
            // Lọc theo số câu hỏi (?min_questions=30&max_questions=60)
            ->when(is_numeric($filters['min_questions'] ?? null), function ($q) use ($filters) {
                $q->where('examples.num_question', '>=', (int) $filters['min_questions']);
            })
            ->when(is_numeric($filters['max_questions'] ?? null), function ($q) use ($filters) {
                $q->where('examples.num_question', '<=', (int) $filters['max_questions']);
            })
            // Chỉ cho sắp xếp theo các cột cho phép (bản cũ nối thẳng chuỗi → SQL injection).
            ->when(in_array($filters['orderBy'] ?? null, self::SORTABLE, true), function ($q) use ($filters) {
                $sort = strtolower($filters['sort'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
                $q->orderBy('examples.' . $filters['orderBy'], $sort);
            })
            // Thứ tự phụ cố định → phân trang không lặp / sót đề khi nhiều đề trùng giá trị.
            ->orderBy('examples.id', 'desc');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Example extends Model
{
    use HasFactory, HasSlug;

    /** Cột được phép dùng trong ?orderBy= */
    public const SORTABLE = ['count_test', 'count_like', 'created_at', 'updated_at', 'num_question', 'name'];

    public function slugSource(): string
    {
        return (string) $this->name;
    }
    protected $appends = ['user_action'];

    protected $hidden = ['liked_by_me', 'favorited_by_me'];

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
    /** Người dùng đã thích đề (bảng `likes`). */
    public function likers()
    {
        return $this->belongsToMany(User::class, 'likes')->withPivot('created_at');
    }

    /** Người dùng đã yêu thích đề (bảng `favorites`). */
    public function favoritedBy()
    {
        return $this->belongsToMany(User::class, 'favorites')->withPivot('created_at');
    }

    /** Các lượt làm bài. */
    public function attempts()
    {
        return $this->hasMany(Attempt::class);
    }

    /** Đề tôi đã yêu thích. */
    public function scopeMyFavorite($query)
    {
        $id = auth('api')->id();

        return $query
            ->withAll()
            ->whereHas('favoritedBy', fn ($q) => $q->where('users.id', $id));
    }

    public function scopeWithUser($query)
    {
        return $query->with(['user']);
    }

    /**
     * Tác giả, ngành + cờ "tôi đã thích / yêu thích" (khách: false).
     * Trả ra API qua thuộc tính `user_action` (giữ định dạng cũ cho frontend).
     */
    public function scopeWithAll($query)
    {
        $id = auth('api')->id();

        return $query->with(['user', 'sector'])->withExists([
            'likers as liked_by_me' => fn ($q) => $q->where('users.id', $id),
            'favoritedBy as favorited_by_me' => fn ($q) => $q->where('users.id', $id),
        ]);
    }

    /** `{ like, favorited }` của người dùng hiện tại (cần scope withAll). */
    public function getUserActionAttribute(): array
    {
        return [
            'like' => (bool) ($this->attributes['liked_by_me'] ?? false),
            'favorited' => (bool) ($this->attributes['favorited_by_me'] ?? false),
        ];
    }

    public function scopeWithSector($query)
    {
        return $query->with(['sector']);
    }
    /** Các phần của đề, theo thứ tự hiển thị. */
    public function parts()
    {
        return $this->hasMany(Part::class)->orderBy('position');
    }

    /** Mọi câu hỏi của đề (qua các phần). */
    public function questions()
    {
        return $this->hasManyThrough(Question::class, Part::class);
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

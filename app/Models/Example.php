<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Example extends Model
{
    use HasFactory, HasSlug;

    /** Trạng thái duyệt đề (cột `status`). */
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PENDING = 'pending';
    public const STATUS_REJECTED = 'rejected';
    public const STATUSES = [self::STATUS_APPROVED, self::STATUS_PENDING, self::STATUS_REJECTED];

    /** Cột được phép dùng trong ?orderBy= */
    public const SORTABLE = ['count_test', 'count_like', 'created_at', 'updated_at', 'num_question', 'name'];

    public function slugSource(): string
    {
        return (string) $this->name;
    }
    protected $appends = ['user_action'];

    protected $casts = [
        'hidden_at' => 'datetime',
        'pinned_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    protected $hidden = ['liked_by_me', 'favorited_by_me', 'my_best_score', 'my_last_done_at'];

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
        // Tác giả hiện công khai (danh sách đề, trang xem trước) → KHÔNG kèm email.
        return $this->belongsTo(User::class, 'user_id')
            ->select(["id", "uuid", "name", "avatar", "role"]);
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
    /** Chỉ đề đang công khai: chưa bị ẩn VÀ đã được duyệt. */
    public function scopeVisible($query)
    {
        return $query->whereNull('examples.hidden_at')->where('examples.status', self::STATUS_APPROVED);
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isApproved(): bool
    {
        return ($this->status ?? self::STATUS_APPROVED) === self::STATUS_APPROVED;
    }

    /** Công khai với mọi người (không bị ẩn, đã duyệt). */
    public function isPublic(): bool
    {
        return !$this->isHidden() && $this->isApproved();
    }

    /** Người duyệt đề. */
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Người hiện tại xem được đề này không: đề công khai → ai cũng xem;
     * đề bị ẩn / chờ duyệt / bị từ chối → chỉ tác giả và administrator / manager.
     */
    public function viewableBy(?User $user): bool
    {
        if ($this->isPublic()) {
            return true;
        }

        return $user && ($user->id === $this->user_id || $user->isStaff());
    }

    public function scopeMyFavorite($query)
    {
        $id = auth('api')->id();

        return $query
            ->withAll()
            ->whereHas('favoritedBy', fn ($q) => $q->where('users.id', $id))
            ->visible();
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

        $query->with(['user', 'sector'])->withExists([
            'likers as liked_by_me' => fn ($q) => $q->where('users.id', $id),
            'favoritedBy as favorited_by_me' => fn ($q) => $q->where('users.id', $id),
        ]);

        // Điểm cao nhất của tôi ở đề này (null = chưa làm) — dùng index (user_id, example_id).
        if ($id) {
            $query->withMax(['attempts as my_best_score' => fn ($q) => $q->where('user_id', $id)], 'score');
        }

        return $query;
    }

    /** `{ like, favorited, best_score }` của người dùng hiện tại (cần scope withAll). */
    public function getUserActionAttribute(): array
    {
        $best = $this->attributes['my_best_score'] ?? null;

        return [
            'like' => (bool) ($this->attributes['liked_by_me'] ?? false),
            'favorited' => (bool) ($this->attributes['favorited_by_me'] ?? false),
            // Điểm cao nhất của tôi (thang 10), null = chưa làm đề này.
            'best_score' => $best === null ? null : round((float) $best, 2),
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
            ->when(is_string($filters['search'] ?? null) && trim($filters['search']) !== '', function ($q) use ($filters) {
                // Escape % và _ để người dùng gõ ký tự này không thành wildcard.
                $search = '%' . addcslashes(trim($filters['search']), '%_\\') . '%';
                $q->where(function ($w) use ($search) {
                    $w->where('examples.name', 'like', $search)
                        ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $search));
                });
            })
            // Lọc theo slug ngành (vd ?sector=cong-nghe-thong-tin)
            ->when(is_string($filters['sector'] ?? null) && $filters['sector'] !== '', function ($q) use ($filters) {
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

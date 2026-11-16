<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Concerns\HasSlug;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasSlug, HasUuids;

    /**
     * Phân quyền:
     *  - administrator: DUY NHẤT 1 tài khoản (email = ADMINISTRATOR_EMAIL trong .env), cấp role
     *    cho người khác + mọi quyền của manager.
     *  - manager: quản lý (sửa / xóa) MỌI đề thi, chỉ qua API /admin/*.
     *  - person: người dùng thường.
     *  - ban: bị khóa (middleware checkRole chặn).
     */
    public const ROLE_ADMINISTRATOR = 'administrator';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_PERSON = 'person';
    public const ROLE_BAN = 'ban';

    /** Role administrator có thể cấp cho người khác qua trang quản trị. */
    public const ASSIGNABLE_ROLES = [self::ROLE_MANAGER, self::ROLE_PERSON];

    public function isAdministrator(): bool
    {
        return $this->role === self::ROLE_ADMINISTRATOR;
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function canCreateExams(): bool
    {
        return (bool) ($this->can_create_exams ?? true);
    }

    /**
     * Có được khóa / hạn chế tạo đề `$target` không:
     *  - administrator: mọi người trừ administrator (kể cả chính mình);
     *  - manager: chỉ người dùng thường (person);
     *  - còn lại: không.
     */
    public function canModerate(User $target): bool
    {
        if ($target->is($this) || $target->isAdministrator()) {
            return false;
        }
        if ($this->isAdministrator()) {
            return true;
        }

        return $this->role === self::ROLE_MANAGER && $target->role === self::ROLE_PERSON;
    }

    /** Thu hồi mọi phiên đăng nhập (JWT có claim `ver` cũ bị từ chối). */
    public function revokeTokens(): void
    {
        $this->increment('token_version');
    }

    /** Vào được trang quản trị (administrator hoặc manager). */
    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_ADMINISTRATOR, self::ROLE_MANAGER], true);
    }

    /**
     * Đồng bộ role administrator theo ADMINISTRATOR_EMAIL (gọi khi đăng nhập / refresh):
     * tài khoản đó luôn là administrator, ngoài nó ra không ai giữ role này.
     */
    public function syncAdministratorRole(): void
    {
        $email = strtolower(trim((string) config('auth.administrator_email')));
        if ($email === '') {
            return;
        }
        $shouldBe = strtolower((string) $this->email) === $email;
        if ($shouldBe && !$this->isAdministrator()) {
            $this->forceFill(['role' => self::ROLE_ADMINISTRATOR])->save();
        } elseif (!$shouldBe && $this->isAdministrator()) {
            $this->forceFill(['role' => self::ROLE_MANAGER])->save();
        }
    }

    /**
     * Sinh UUID cho cột `uuid` (định danh công khai trên URL /profile/@<uuid>);
     * khóa chính vẫn là id tăng dần.
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** Slug từ phần trước @ của email (vd hung2k4hytq@gmail.com → hung2k4hytq). */
    public function slugSource(): string
    {
        return Str::before((string) $this->email, '@') ?: (string) $this->name;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'slug',
        'email',
        'role',
        'password',
        'email_verified_at',
        'banner'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        // slug sinh từ email → không trả ra API (dùng uuid làm định danh công khai).
        'slug',
        // Thông tin khóa chỉ trả qua API quản trị.
        'banned_at',
        'ban_reason',
        'password',
        'remember_token',
        'token_version',
        'email_verified_at',
        'updated_at',
        'created_at'
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'banned_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'can_create_exams' => 'boolean',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    /** Coi là đang online nếu hoạt động trong ONLINE_MINUTES phút gần nhất. */
    public const ONLINE_MINUTES = 5;

    public function scopeOnline($query)
    {
        return $query->where('last_seen_at', '>=', now()->subMinutes(self::ONLINE_MINUTES));
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gte(now()->subMinutes(self::ONLINE_MINUTES));
    }

    /**
     * Ghi nhận đang hoạt động. Tối đa 1 lần ghi / phút / người (không ghi DB mỗi request),
     * không đụng updated_at.
     */
    public function touchLastSeen(): void
    {
        if ($this->last_seen_at && $this->last_seen_at->gt(now()->subMinute())) {
            return;
        }
        $now = now();
        static::query()->whereKey($this->getKey())->update(['last_seen_at' => $now]);
        $this->last_seen_at = $now;
        $this->syncOriginalAttribute('last_seen_at');
    }

    /** Định danh đưa vào claim `sub` của JWT. */
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    /** Claim thêm: quyền (role) + phiên bản token (thu hồi khi đổi mật khẩu). */
    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
            'ver' => (int) $this->token_version,
        ];
    }

    /** Các lượt làm bài của người dùng. */
    public function attempts()
    {
        return $this->hasMany(Attempt::class);
    }

    public function examples()
    {
        return $this->hasMany(Example::class, 'user_id');
    }
}

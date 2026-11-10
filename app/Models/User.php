<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Concerns\HasSlug;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasSlug;

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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
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

    public function examples()
    {
        return $this->hasMany(Example::class, 'user_id');
    }
}

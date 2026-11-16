<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = ['id', 'slug', 'name', 'position'];

    public $timestamps = false;

    protected $casts = ['position' => 'integer'];

    public function examples()
    {
        return $this->hasMany(Example::class);
    }

    public function slugSource(): string
    {
        return (string) $this->name;
    }
}

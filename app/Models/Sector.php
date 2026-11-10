<?php

namespace App\Models;

use App\Models\Concerns\HasSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sector extends Model
{
    use HasFactory, HasSlug;

    protected $fillable = ['id', 'slug', 'name'];

    public function slugSource(): string
    {
        return (string) $this->name;
    }
}

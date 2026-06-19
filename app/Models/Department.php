<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Department extends Model
{
    /** @use HasFactory<\Database\Factories\DepartmentFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'slug', 'level'];

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}

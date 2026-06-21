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

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** URL segment alias used in route helpers, e.g. route('departments.show', [$dept->levelAlias(), $dept]) */
    public function levelAlias(): string
    {
        return $this->level === 'secretariat_level' ? 'sectt' : 'dept';
    }

    /** Human-readable level label for breadcrumbs and UI */
    public function levelLabel(): string
    {
        return $this->level === 'secretariat_level' ? 'Secretariat' : 'Department';
    }

    public function sections(): HasMany
    {
        return $this->hasMany(Section::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}

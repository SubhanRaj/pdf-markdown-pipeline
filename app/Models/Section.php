<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Section extends Model
{
    /** @use HasFactory<\Database\Factories\SectionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = ['department_id', 'wing', 'name', 'slug', 'requires_approval'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function divisions(): HasMany
    {
        return $this->hasMany(Division::class)->orderBy('name');
    }

    /** Direct section folders only (division_id IS NULL). */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class)->whereNull('division_id')->orderBy('name');
    }
}

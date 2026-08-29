<?php

namespace App\Models;

use App\Models\Concerns\HasUnicodeSlug;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Folder extends Model
{
    use HasFactory, SoftDeletes, HasUnicodeSlug;

    protected $fillable = [
        'department_id',
        'section_id',
        'division_id',
        'parent_id',
        'name',
        'slug',
        'description',
        'visibility',
        'requires_approval',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->orderBy('created_at');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    /** Subfolders — one level deep only, see the folders.parent_id migration. */
    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id')->orderBy('name');
    }

    /** Generate a slug unique among direct section folders (division_id IS NULL). */
    public static function uniqueSlugForSection(string $name, int $sectionId, ?int $exceptId = null): string
    {
        $base = static::makeSlug($name);
        $slug = $base;
        $i    = 2;

        while (
            static::where('section_id', $sectionId)
                ->whereNull('division_id')
                ->where('slug', $slug)
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->withTrashed()
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /** Generate a slug unique among division folders. */
    public static function uniqueSlugForDivision(string $name, int $divisionId, ?int $exceptId = null): string
    {
        $base = static::makeSlug($name);
        $slug = $base;
        $i    = 2;

        while (
            static::where('division_id', $divisionId)
                ->where('slug', $slug)
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->withTrashed()
                ->exists()
        ) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}

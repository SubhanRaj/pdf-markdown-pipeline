<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUnicodeSlug;

class Division extends Model
{
    use HasFactory, SoftDeletes, HasUnicodeSlug;

    protected $fillable = ['section_id', 'name', 'slug', 'description', 'requires_approval'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->orderBy('created_at');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class)->orderBy('name');
    }

    /** Generate a slug unique within the section, checking soft-deleted records. */
    public static function uniqueSlugForSection(string $name, int $sectionId, ?int $exceptId = null): string
    {
        $base = static::makeSlug($name);
        $slug = $base;
        $i    = 2;

        while (
            static::where('section_id', $sectionId)
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

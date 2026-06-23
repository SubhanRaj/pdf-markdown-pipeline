<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUnicodeSlug;

class RuleSet extends Model
{
    /** @use HasFactory<\Database\Factories\RuleSetFactory> */
    use HasFactory, SoftDeletes, HasUnicodeSlug;

    protected $fillable = ['department_id', 'name', 'slug', 'description', 'metadata'];

    protected $casts = ['metadata' => 'array'];

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
        return $this->hasMany(Document::class)->orderBy('created_at');
    }

    /** Generate a slug unique within the department, checking soft-deleted records. */
    public static function uniqueSlugForDepartment(string $name, int $departmentId, ?int $exceptId = null): string
    {
        $base = static::makeSlug($name);
        $slug = $base;
        $i    = 2;

        while (
            static::where('department_id', $departmentId)
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

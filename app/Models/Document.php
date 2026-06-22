<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Document extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory, SoftDeletes;

    public const DOCUMENT_TYPES = [
        'go'             => 'Government Order',
        'policy'         => 'Policy',
        'notice'         => 'Notice',
        'court_order'    => 'Court Order',
        'service_code'   => 'Service Code',
        'rule_amendment' => 'Rule / Amendment',
        'other'          => 'Other',
    ];

    public const VISIBILITY = [
        'public'        => 'Public',
        'authenticated' => 'Authenticated Only',
    ];

    public const STATUSES = [
        'uploaded'    => ['label' => 'Uploaded',    'color' => 'slate'],
        'processing'  => ['label' => 'Processing',  'color' => 'blue'],
        'ocr_pending' => ['label' => 'OCR Pending', 'color' => 'amber'],
        'review'      => ['label' => 'Review',      'color' => 'indigo'],
        'verified'    => ['label' => 'Verified',    'color' => 'green'],
        'failed'      => ['label' => 'Failed',      'color' => 'red'],
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Slug unique within a section (section-based documents). */
    public static function uniqueSlugForSection(string $title, int $sectionId, ?int $exceptId = null): string
    {
        $base = Str::slug($title);
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

    /** Slug unique within a rule set (rule-amendment documents). */
    public static function uniqueSlugForRuleSet(string $title, int $ruleSetId, ?int $exceptId = null): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i    = 2;

        while (
            static::where('rule_set_id', $ruleSetId)
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

    protected $fillable = [
        'department_id',
        'section_id',
        'rule_set_id',
        'user_id',
        'title',
        'slug',
        'document_type',
        'original_filename',
        'original_pdf_path',
        'markdown_path',
        'vault_path',
        'status',
        'visibility',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(DocumentStatusHistory::class);
    }
}

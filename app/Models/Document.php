<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUnicodeSlug;

class Document extends Model
{
    /** @use HasFactory<\Database\Factories\DocumentFactory> */
    use HasFactory, SoftDeletes, HasUnicodeSlug;

    public const DOCUMENT_TYPES = [
        'go'             => 'Government Order',
        'policy'         => 'Policy',
        'notice'         => 'Notice',
        'court_order'    => 'Court Order',
        'service_code'   => 'Service Code',
        'rule'           => 'Rule',
        'rule_amendment' => 'Amendment to Rule',
        'other'          => 'Other',
    ];

    public const VISIBILITY = [
        'public'        => 'Public',
        'authenticated' => 'Authenticated Only',
    ];

    public const LANGUAGES = [
        'english' => 'English',
        'hindi'   => 'Hindi',
    ];

    public const STATUSES = [
        'pending_approval' => ['label' => 'Pending Approval', 'color' => 'amber'],
        'uploaded'         => ['label' => 'Uploaded',         'color' => 'slate'],
        'processing'       => ['label' => 'Processing',       'color' => 'blue'],
        'ocr_pending'      => ['label' => 'OCR Pending',      'color' => 'amber'],
        'review'           => ['label' => 'Review',           'color' => 'indigo'],
        'verified'         => ['label' => 'Verified',         'color' => 'green'],
        'failed'           => ['label' => 'Failed',           'color' => 'red'],
        'rejected'         => ['label' => 'Rejected',         'color' => 'red'],
    ];

    /** Exclude pending_approval and rejected docs from all regular public/browse queries. */
    public function scopePublishable(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['pending_approval', 'rejected']);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** Slug unique within a section (section-based documents). */
    public static function uniqueSlugForSection(string $title, int $sectionId, ?int $exceptId = null): string
    {
        $base = static::makeSlug($title);
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
        $base = static::makeSlug($title);
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

    /** Slug unique within a division (division-based documents). */
    public static function uniqueSlugForDivision(string $title, int $divisionId, ?int $exceptId = null): string
    {
        $base = static::makeSlug($title);
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

    /** Slug unique within a folder (both section-folder and division-folder documents). */
    public static function uniqueSlugForFolder(string $title, int $folderId, ?int $exceptId = null): string
    {
        $base = static::makeSlug($title);
        $slug = $base;
        $i    = 2;

        while (
            static::where('folder_id', $folderId)
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
        'division_id',
        'rule_set_id',
        'folder_id',
        'parent_id',
        'user_id',
        'title',
        'slug',
        'document_type',
        'language',
        'sibling_document_id',
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

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function ruleSet(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(DocumentStatusHistory::class);
    }

    public function parentDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_id');
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(Document::class, 'parent_id')->orderBy('created_at', 'asc');
    }

    /** The other-language version of this same document, if one was uploaded alongside it. */
    public function siblingDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'sibling_document_id');
    }
}

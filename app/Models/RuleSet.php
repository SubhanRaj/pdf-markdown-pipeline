<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\HasUnicodeSlug;

class RuleSet extends Model
{
    /** @use HasFactory<\Database\Factories\RuleSetFactory> */
    use HasFactory, SoftDeletes, HasUnicodeSlug;

    /**
     * Controlled vocabulary for kind=policy's policy_type column. Only the government's actual
     * named policies belong here (UP Excise Policy, UP Cane Policy, ...) — subject-specific rules
     * extracted for standalone browsing (Bar, Beer, Bottling, Distillery, Vending, ...) are Rules
     * (kind=rules), not Policies. See POLICY_TAXONOMY_PLAN.md for the full reasoning.
     */
    public const POLICY_TYPES = [
        'excise_policy' => 'Excise Policy',
        'cane_policy'   => 'Cane Policy',
        'sugar_policy'  => 'Sugar Policy',
        'import_policy' => 'Import Policy',
        'export_policy' => 'Export Policy',
        'other'         => 'Other',
    ];

    /** States + union territories of India. "Uttar Pradesh" is the default in the primary upload flow. */
    public const STATES = [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat',
        'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh',
        'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab',
        'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand',
        'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh', 'Dadra and Nagar Haveli and Daman and Diu',
        'Delhi', 'Jammu and Kashmir', 'Ladakh', 'Lakshadweep', 'Puducherry',
    ];

    public const DEFAULT_STATE = 'Uttar Pradesh';

    protected $fillable = [
        'department_id', 'name', 'slug', 'description', 'metadata', 'requires_approval',
        'kind', 'state', 'policy_type', 'effective_start_date', 'effective_end_date',
        'policy_status', 'previous_policy_id', 'container_id',
    ];

    protected $casts = [
        'metadata'              => 'array',
        'effective_start_date'  => 'date',
        'effective_end_date'    => 'date',
    ];

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

    /** The policy period this one was superseded by, if any (reverse of previous_policy_id). */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(RuleSet::class, 'previous_policy_id');
    }

    /** The policy period this one supersedes, if any. */
    public function previousPolicy(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class, 'previous_policy_id');
    }

    /** The policy container (state + policy_type, created once) this period lives under. */
    public function container(): BelongsTo
    {
        return $this->belongsTo(RuleSet::class, 'container_id');
    }

    /** All periods (e.g. one per year) created under this policy container. */
    public function periods(): HasMany
    {
        return $this->hasMany(RuleSet::class, 'container_id')->orderByDesc('effective_start_date');
    }

    public function scopeRules($query)
    {
        return $query->where('kind', 'rules');
    }

    public function scopePolicy($query)
    {
        return $query->where('kind', 'policy');
    }

    /** Policy containers — state + policy_type "lines", created once, holding many periods. */
    public function scopePolicyContainers($query)
    {
        return $query->where('kind', 'policy')->whereNull('container_id');
    }

    /** Current (non-superseded) policy periods — excludes containers themselves. */
    public function scopeCurrentPolicy($query)
    {
        return $query->where('kind', 'policy')->whereNotNull('container_id')->where('policy_status', 'current');
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

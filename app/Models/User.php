<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Canonical privilege whitelist. Any string not in this list is rejected
     * by StoreUserRequest/UpdateUserRequest to prevent privilege escalation.
     */
    public const PRIVILEGES = [
        'documents.upload',
        'documents.edit',
        'documents.delete',
        'documents.restore',      // restore from archive
        'documents.force-delete', // permanent delete from archive (requires letter)
        'documents.verify',
        'organization.head',      // upload/delete anywhere across all departments
        'department.head',        // scoped to assigned department
        'section.head',           // scoped to assigned section
    ];

    protected $fillable = [
        'name',
        'username',
        'email',
        'mobile',
        'landline',
        'password',
        'post',
        'role',
        'privileges',
        'department_id',
        'section_id',
        'division_id',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'privileges'        => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

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
        return $this->hasMany(Document::class);
    }

    // ── Role helpers ─────────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    public function hasPrivilege(string $privilege): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $privileges = $this->privileges ?? [];

        return in_array('*', $privileges, true) || in_array($privilege, $privileges, true);
    }

    // ── Upload / delete scope helpers ────────────────────────────────────────

    /**
     * Returns the effective upload scope for this user.
     * 'global'     — organization.head privilege or admin
     * 'department' — department.head privilege + department_id, or department_id only
     * 'section'    — section.head privilege + section_id, or section_id only
     * 'division'   — division_id set
     * 'none'       — no scope (viewer, or operator with no assignment and no upload privilege)
     */
    public function uploadScope(): string
    {
        if ($this->isAdmin() || $this->hasPrivilege('organization.head')) {
            return 'global';
        }

        if ($this->hasPrivilege('department.head') && $this->department_id) {
            return 'department';
        }

        if ($this->division_id) {
            return 'division';
        }

        if ($this->section_id) {
            return 'section';
        }

        if ($this->department_id) {
            return 'department';
        }

        // Operator with documents.upload but no organisational assignment — legacy global access
        if ($this->hasPrivilege('documents.upload')) {
            return 'global';
        }

        return 'none';
    }

    /**
     * Whether this user may upload documents to the given context.
     * $context must be a Section, Division, or RuleSet model instance.
     */
    public function canUploadTo(object $context): bool
    {
        $scope = $this->uploadScope();

        if ($scope === 'global') {
            return true;
        }

        if ($scope === 'none') {
            return false;
        }

        // Resolve the context's department_id, section_id, division_id
        [$ctxDept, $ctxSection, $ctxDivision] = $this->resolveContextIds($context);

        return match ($scope) {
            'department' => $ctxDept === $this->department_id,
            'section'    => $ctxSection === $this->section_id,
            'division'   => $ctxDivision === $this->division_id,
            default      => false,
        };
    }

    /**
     * Whether this user may archive (soft-delete) documents from the given context.
     * Uses identical scope logic to canUploadTo().
     */
    public function canDeleteFrom(object $context): bool
    {
        return $this->canUploadTo($context);
    }

    /**
     * Resolve [department_id, section_id, division_id] from a Section, Division, or RuleSet.
     * @return array{int|null, int|null, int|null}
     */
    private function resolveContextIds(object $context): array
    {
        if ($context instanceof Division) {
            return [
                $context->section->department_id ?? null,
                $context->section_id,
                $context->id,
            ];
        }

        if ($context instanceof Section) {
            return [
                $context->department_id,
                $context->id,
                null,
            ];
        }

        if ($context instanceof RuleSet) {
            return [
                $context->department_id,
                null,
                null,
            ];
        }

        return [null, null, null];
    }
}

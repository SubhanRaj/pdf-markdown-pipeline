<?php

namespace App\Models;

use App\Mail\ResetPassword;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public function getRouteKeyName(): string
    {
        return 'username';
    }

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
        'documents.approve',      // approve / reject / reclassify pending uploads
        'documents.move',         // move / copy a document to another section, division, or folder
        'folders.delete',         // delete folders (and their subfolders + documents)
        'organization.head',      // upload/delete anywhere across all departments
        'department.head',        // scoped to assigned department
        'section.head',           // scoped to assigned section
    ];

    /**
     * Document-action privileges auto-granted to role=admin (org-scoped officer admin),
     * regardless of their individual privileges JSON or Designation preset. Deliberately
     * excludes organization.head/department.head/section.head — those still come from the
     * user's actual assignment, since they determine *which* org unit the admin acts in, not
     * *whether* they can act at all. See M74/DESIGNATIONS_PLAN.md — this exists because
     * hand-curated per-Designation privilege lists kept under-granting (e.g. "Deputy
     * Commissioner (P&E)" only ever granted documents.verify, not upload/edit), which is what
     * pushed real accounts toward role=system_admin as a workaround.
     */
    public const ORG_ADMIN_PRIVILEGES = [
        'documents.upload',
        'documents.edit',
        'documents.delete',
        'documents.restore',
        'documents.verify',
        'documents.approve',
        'documents.move',
        'folders.delete',
    ];

    protected $fillable = [
        'name',
        'username',
        'email',
        'mobile',
        'landline',
        'password',
        'post',
        'designation_id',
        'role',
        'privileges',
        'department_id',
        'section_id',
        'division_id',
        'uploads_require_approval',
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

    /**
     * Overrides Notifiable's default reset notification (a queued Notification + Markdown mail)
     * with our own branded Mailable, matching the LoginOtp/AccountOnboarding pattern already used
     * elsewhere in this app instead of introducing a second, differently-styled mail mechanism.
     */
    public function sendPasswordResetNotification($token): void
    {
        $url = url(route('password.reset', ['token' => $token, 'email' => $this->email], false));

        Mail::to($this->email)->send(new ResetPassword($this, $url));
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class)->withTrashed();
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

    /**
     * True site/technical administration — user management, designations, activity logs,
     * pipeline health, and an unconditional bypass of every privilege/scope check. Reserved for
     * IT/dev accounts only (role=system_admin). Departmental officers, however senior, get
     * role=admin instead (see isOrgAdmin()) — real document authority, scoped to their own
     * department/section, with no access to the site console.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'system_admin';
    }

    /**
     * Org-scoped officer admin (Excise Commissioner, DEC, etc.) — full document authority
     * within their assigned department/section/division, but never a site-console bypass.
     * See ORG_ADMIN_PRIVILEGES.
     */
    public function isOrgAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isOperator(): bool
    {
        return $this->role === 'operator';
    }

    /** Human-readable role label for display — 'system_admin' otherwise reads as "System_admin". */
    public function roleLabel(): string
    {
        return match ($this->role) {
            'system_admin' => 'System Admin',
            default         => ucfirst((string) $this->role),
        };
    }

    /** Generate a username from full name + post, unique among all users (incl. soft-deleted). */
    public static function uniqueUsername(string $name, ?string $post = null, ?int $exceptId = null): string
    {
        $base = Str::slug(trim($name . ' ' . ($post ?? '')), '_');
        $base = substr($base, 0, 26) ?: 'user';

        $username = $base;
        $i        = 2;

        while (
            static::where('username', $username)
                ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
                ->withTrashed()
                ->exists()
        ) {
            $username = "{$base}_{$i}";
            $i++;
        }

        return $username;
    }

    public function hasPrivilege(string $privilege): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isOrgAdmin() && in_array($privilege, self::ORG_ADMIN_PRIVILEGES, true)) {
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
     * Shared tier-matching logic for canUploadTo()/canView() — same org-unit tiers
     * (global > department > section > division), differing only in what an unscoped user
     * ('none') resolves to: upload/delete must default-deny (no assignment = no mutation
     * rights), but viewing defaults to "sees everything," matching the pre-existing "legacy
     * operator anywhere" decision — an unscoped viewer isn't a narrower case than a scoped one,
     * it's simply not opted into scoping at all.
     */
    private function matchesOrgScope(object $context, bool $noneResult): bool
    {
        if ($context instanceof Folder) {
            $context = $context->division ?? $context->section;
        }

        $scope = $this->uploadScope();

        if ($scope === 'global') {
            return true;
        }

        if ($scope === 'none') {
            return $noneResult;
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
     * Whether this user may upload documents to the given context.
     * $context must be a Section, Division, RuleSet, or Folder model instance.
     * A Folder resolves to its owning division (if any) or section.
     */
    public function canUploadTo(object $context): bool
    {
        return $this->matchesOrgScope($context, false);
    }

    /**
     * Whether this user may view (browse to) a Section, Division, or Folder's own page and
     * documents. Same org-unit tiers as canUploadTo() — a department-scoped user (e.g. Excise
     * Commissioner) sees the whole department, a section-scoped user sees only their own
     * section. Deliberately NOT applied to RuleSet (Acts/Rules/Policies) — those are
     * department-wide reference material with no section-level owner, so department-tier
     * scoping already covers them with nothing further to narrow. Unscoped users ('none' tier)
     * see everything, same as today — this only adds a ceiling for users who already have an
     * assigned department/section/division.
     */
    public function canView(object $context): bool
    {
        return $this->matchesOrgScope($context, true);
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
     * Whether this user may delete a Folder (and, with it, its subfolders and every document
     * inside them). Gated on the 'folders.delete' privilege, not just upload scope — deleting a
     * folder is destructive in a way uploading to it isn't.
     */
    public function canDeleteFolder(object $context): bool
    {
        if (! ($this->isAdmin() || $this->hasPrivilege('folders.delete'))) {
            return false;
        }

        return $this->canUploadTo($context);
    }

    /**
     * Whether this user may move/copy a document to another section, division, or folder.
     * Gated on the 'documents.move' privilege, not documents.edit — relocating a document's
     * file is a bigger action than editing its title or visibility.
     */
    public function canMoveDocument(object $context): bool
    {
        if (! ($this->isAdmin() || $this->hasPrivilege('documents.move'))) {
            return false;
        }

        return $this->canUploadTo($context);
    }

    /**
     * Whether this user may approve/reject/reclassify documents from the given context.
     * Uses identical scope logic to canUploadTo() — approval boundary matches upload boundary.
     */
    public function canApprove(object $context): bool
    {
        if (! ($this->isAdmin() || $this->hasPrivilege('documents.approve'))) {
            return false;
        }

        return $this->canUploadTo($context);
    }

    /**
     * Whether a document uploaded by this user to the given context should be held
     * for approval before becoming visible in regular document lists.
     */
    public function shouldRequireApproval(object $context): bool
    {
        if ($this->uploads_require_approval) {
            return true;
        }

        if ($context instanceof Section && $context->requires_approval) {
            return true;
        }

        if ($context instanceof Division && $context->requires_approval) {
            return true;
        }

        if ($context instanceof RuleSet && $context->requires_approval) {
            return true;
        }

        if ($context instanceof Folder && $context->requires_approval) {
            return true;
        }

        return false;
    }

    /**
     * Whether this user may create/manage (upload, edit, convert, verify, start a new policy
     * document for) the given policy container. Deliberately stricter than canUploadTo()'s generic
     * department scope — a bare department_id match is not enough for policy, the user must
     * hold the department.head privilege (or be admin).
     */
    public function canManagePolicy(RuleSet $policySet): bool
    {
        return $this->isAdmin()
            || ($this->hasPrivilege('department.head') && $this->department_id === $policySet->department_id);
    }

    /**
     * Same check as canManagePolicy(), but for the moment before a policy RuleSet exists yet
     * (the create/store screen only has a Department to check against).
     */
    public function canManagePolicyForDepartment(Department $department): bool
    {
        return $this->isAdmin()
            || ($this->hasPrivilege('department.head') && $this->department_id === $department->id);
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

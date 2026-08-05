# Multi-Department Scope — Letting One User Cover More Than One Department

## Context

`users.department_id` is a single nullable FK — a user has exactly one department, full stop.
`User::uploadScope()`/`canUploadTo()`/`canManagePolicy()`/`canManagePolicyForDepartment()`
(`app/Models/User.php:144-282`) all resolve "does this user have department-wide authority here"
down to one equality check: `$this->department_id === $ctxDept`. The same raw pattern is repeated,
uncentralized, in six more places: `SectionController.php:40`, `DivisionController.php:30`,
`StoreSectionRequest.php:25`, `UpdateSectionRequest.php:24`, `StoreDivisionRequest.php:28`,
`UpdateDivisionRequest.php:26` (all "can this department.head create/edit a section/division under
their own department" checks), plus `Concerns/BuildsUploadScopeTree.php:27` and
`ApprovalController.php:366` (both filter a tree/queue down to `where('department_id',
$user->department_id)` for the upload-destination picker and the approval queue).

Raised while reviewing the new Designations feature: an Additional Chief Secretary or similar
officer can genuinely be responsible for **two or more specific departments** (not all of them —
that's what `organization.head` already covers, and it's too broad; not just one — that's today's
default). There is currently no way to express "this user has department-wide authority over
Excise **and** Sugarcane, but not Cane Federation or Sugar Mill Corporation." The only existing
workarounds are `organization.head` (too broad — grants literally every department) or leaving them
scoped to a single department and manually reassigning `department_id` whenever they need to act on
the other one (not real dual-department access, just a manual switch).

This is a genuinely separate gap from Designations (M74) — Designations map a post to a *default*
scope/privilege bundle, they don't change what scope values are representable. This plan is about
making "more than one department" a representable scope value in the first place.

## Design

### 1. New table: `department_user` (many-to-many)

Migration `create_department_user_table`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users | `cascadeOnDelete` |
| `department_id` | FK → departments | `cascadeOnDelete` |
| timestamps | | |

Unique constraint on `(user_id, department_id)`. Same migration backfills: for every existing user
with `department_id IS NOT NULL`, insert one `department_user` row — so every current
single-department user's existing access is represented in the pivot from day one, no separate
seeder or manual step needed.

### 2. `users.department_id` is kept, becomes "primary department"

Not dropped, not deprecated. It keeps doing exactly what it does today: driving the Section/Division
cascading `<select>`s on the user form (which are still single-value — this plan does **not**
extend multi-department to multi-section/division, see "Out of scope" below), and feeding
`User::uniqueUsername()`. Whenever `department_id` is set or changed via the user create/edit form,
`UserManagementController::store()`/`update()` syncs it into the `department_user` pivot too (add
if missing — never removes a department the admin didn't explicitly uncheck), so the primary
department and the pivot never disagree for the common single-department case. Nothing about the
existing single-department create/edit flow changes from an admin's perspective — same select,
same behavior.

### 3. `User::departments()` relation + one centralized authorization helper

```php
public function departments(): BelongsToMany
{
    return $this->belongsToMany(Department::class);
}

/** Does this user have department-wide (department.head-tier) access to this specific department? */
public function hasDepartmentAccess(int $departmentId): bool
{
    return $this->departments->contains('id', $departmentId);
}
```

Every one of the nine call sites listed in "Context" that currently does `$user->department_id ===
$x->id` (or `where('department_id', $user->department_id)`) switches to
`$user->hasDepartmentAccess($x->id)` (or `whereIn('department_id',
$user->departments->pluck('id'))` for the two list-filtering call sites,
`BuildsUploadScopeTree`/`ApprovalController`). This was already effectively duplicated
authorization logic across seven files before this plan — centralizing it into one method is a
requirement of correctly supporting multiple departments, not a separate refactor tacked on.

`uploadScope()`'s return value (`'global'|'department'|'section'|'division'|'none'`) is unchanged —
it's a coarse label consumed elsewhere for UI branching, not itself a department-membership check.
Only the *specific-department* comparisons switch to the new helper.

### 4. User create/edit form: additive "Additional Departments" block

The existing single `<select name="department_id">` stays exactly as-is (primary department,
drives the Section/Division cascade, unchanged UX for the common case). Below it, a new checkbox
list — `departments[]`, same visual pattern as the Granular Privileges panel — lets the admin grant
the same department-wide authority to additional departments beyond the primary one. Both the
primary `department_id` and everything checked in `departments[]` get synced into the
`department_user` pivot on save.

Backend: `StoreUserRequest`/`UpdateUserRequest` gain `departments` (`nullable, array`),
`departments.*` (`integer, exists:departments,id`). `UserManagementController::store()`/`update()`
call `$user->departments()->sync(array_unique(array_filter([$request->department_id,
...($request->departments ?? [])])))` after saving the user row.

### 5. Designations (M74) are not touched

A `Designation`'s own `department_id` (single, nullable — null means generic/any department) stays
exactly as it is. Picking a department-locked Designation still just sets the user form's single
primary `department_id` select, same as today. If an admin needs to grant a designation-holder
access to a second department too, they check it in the new "Additional Departments" block
afterward — same "preset, not a synced rule" ethos already established for Designations.

## Explicitly out of scope for this pass

- **No multi-section or multi-division.** `section_id`/`division_id` remain single-value. The
  reported real-world need was specifically department-level (an ACS-tier officer spanning two
  named departments), not section-level. If a genuine multi-section need shows up later, it would
  follow the same `section_user` pivot pattern — not building it speculatively now.
- **No change to `organization.head`** — it still means "every department," unconditionally. This
  plan is for the specific-named-subset case in between "one department" and "all of them."
  Multi-department support doesn't replace it.
- **No retroactive privilege changes** — this only changes which departments a `department.head`
  check matches against, not what `department.head` itself grants.

## Files touched (planned)

- `database/migrations/xxxx_create_department_user_table.php` (new, includes backfill)
- `app/Models/User.php` — `departments()` relation, `hasDepartmentAccess()` helper; update
  `uploadScope()`'s department branch, `canUploadTo()`'s `resolveContextIds()` comparison,
  `canManagePolicy()`, `canManagePolicyForDepartment()`
- `app/Http/Controllers/SectionController.php`, `DivisionController.php`, `ApprovalController.php`
  (two call sites)
- `app/Http/Requests/StoreSectionRequest.php`, `UpdateSectionRequest.php`,
  `StoreDivisionRequest.php`, `UpdateDivisionRequest.php`
- `app/Http/Controllers/Concerns/BuildsUploadScopeTree.php`
- `app/Http/Controllers/Admin/UserManagementController.php` — sync `department_user` pivot on
  store/update
- `app/Http/Requests/Admin/StoreUserRequest.php`, `UpdateUserRequest.php` — `departments`/
  `departments.*` validation
- `resources/views/admin/users/create.blade.php`, `edit.blade.php` — new "Additional Departments"
  checkbox block

## Verification

1. Migrate — confirm `department_user` exists and every existing user with a `department_id` has a
   matching pivot row.
2. Edit an existing single-department user without touching the new checkbox block — confirm
   behavior is byte-identical to today (Section/Division cascade, upload scope, policy management).
3. Grant a user a second department via the new checkbox block — confirm they can upload/verify/
   approve documents in both departments, and still cannot touch a third, unassigned department.
4. Confirm `organization.head` users are unaffected (still every department, via the existing
   `uploadScope() === 'global'` branch, untouched by this plan).
5. Confirm a `department.head` user's Section/Division create/edit authority (via
   `StoreSectionRequest`/`UpdateSectionRequest`/`StoreDivisionRequest`/`UpdateDivisionRequest`) now
   also works correctly for their second department, not just the primary one.
6. Confirm the approval queue (`ApprovalController`) and the upload-destination tree
   (`BuildsUploadScopeTree`) both show/filter to *all* of a multi-department user's departments, not
   just the primary one.
7. Uncheck a previously-granted additional department and save — confirm the pivot row is removed
   and access to that department is revoked, while the primary department is untouched.

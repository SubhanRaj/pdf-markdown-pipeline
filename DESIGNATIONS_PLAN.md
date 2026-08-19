# Designations — Decoupling Site Administration from Departmental Authority

## Context

Real users have started hitting a design gap in `role`/`privileges`. `role` currently conflates
two unrelated things:

1. **Site management** — creating/editing users, granting privileges, viewing `/admin/activity-logs`.
   This should only ever belong to the developer/IT-department account(s) running the portal.
2. **Departmental/organisational authority over documents** — a Commissioner, an ACS, a Section
   Officer each need real upload/verify/approve authority scoped to their department, secretariat,
   or section.

`User::uploadScope()`/`canUploadTo()`/`canDeleteFrom()`/`canApprove()` (`app/Models/User.php:144-226`)
already implement #2 correctly, via the `department.head`/`section.head`/`organization.head`
privilege strings combined with `department_id`/`section_id`/`division_id` — a user can already get
department-wide document authority *without* being `role = admin`. But this path is invisible in
practice: the create/edit user form (`resources/views/admin/users/create.blade.php`) only exposes
raw checkboxes (`documents.upload`, `department.head`, …) with no mapping to real-world designations,
so there is no way for whoever is creating accounts to know that "Additional Excise Commissioner"
should mean "check `department.head` + `documents.verify` + `documents.approve`, set `department_id`
to Excise." Confronted with that gap, the workaround was to set `role = admin` directly — which
worked for document authority, but also handed that person the full site-management console
(`/admin/users`, `/admin/activity-logs`, the privilege-editing UI itself) that only the site
manager/dev/IT department should ever see. This happened in practice: an Additional Excise
Commissioner was created as `role = admin` and could see "Add User" and "Granular Privileges,"
which is not appropriate for that role in the real organisation.

The fix is not a new access-control primitive — the scope/privilege machinery already works. What's
missing is a **named preset layer** ("Designation") that maps a real government post to the correct
privilege/scope combination, so an admin picks "Excise Commissioner" from a dropdown instead of
reverse-engineering checkboxes, and never needs `role = admin` as a shortcut again.

**Confirmed decisions (asked and answered before drafting this plan):**
- Designations are a full admin-manageable CRUD screen (`/admin/designations`), not a hardcoded/seeded
  list requiring a code change to extend — new posts get added as the org chart changes, without a deploy.
- A designation's default privileges/scope are a **one-time preset**, applied when picked, not a
  permanently-synced source of truth — the admin can still hand-tune an individual user's privileges
  afterward (same "descriptive default, not a hard rule" pattern already used for Policy effective
  dates and the Policy Type "Other" dropdown elsewhere in this app).

**Designation examples gathered from the real org chart** (seed data, not exhaustive — the CRUD
screen is exactly how more get added later):

*Generic (usable under any department, `department_id = null`):* Officer, Section Officer, HoD,
Chief Secretary, Additional Chief Secretary, Principal Secretary, Secretary, Special Secretary,
Deputy Secretary, Joint Secretary, Review Officer, Additional Review Officer, Clerk, Finance
Controller, Auditor, Accounting Officer.

*Department-specific (`department_id` locked to one department):* Excise Commissioner, Additional
Excise Commissioner, Cane Commissioner, Additional Cane Commissioner, Deputy Commissioner (P&E),
Deputy Excise Commissioner (P).

Scope varies by designation, not by a fixed rule — Additional Chief Secretary is cross-department
(secretariat-wide), a Commissioner is department-wide, a Deputy Commissioner or Finance Controller
can be scoped down to a single section, a Clerk or Auditor may need no head-authority at all (just
a document privilege or two). Each designation's `default_scope` records which of the existing
scope tiers (global/department/section/division/none) it typically implies — see schema below.

---

## Design

### 1. New table + model: `Designation`

Migration `create_designations_table`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | e.g. "Additional Excise Commissioner" |
| `slug` | string | URL-safe, auto-generated from name |
| `department_id` | FK → departments, nullable | `nullOnDelete`. Null = generic, selectable for any department. Non-null = locked to that one department (e.g. Excise Commissioner only appears/selectable when the user form's Department is Excise) |
| `default_scope` | enum | `global` \| `department` \| `section` \| `division` \| `none` — informs which `*.head` privilege and which of Department/Section/Division selects get pre-filled when this designation is picked |
| `default_privileges` | json | subset of `User::PRIVILEGES`, pre-checked in the Granular Privileges panel when this designation is selected |
| `sort_order` | integer, default 0 | display ordering in the dropdown |
| timestamps + softDeletes | | soft-delete so historical users retain a readable designation name even if a post is retired |

Unique constraint: `(department_id, slug)` — mirrors the existing `rule_sets` uniqueness pattern
(same slug allowed once per department, plus once globally for `department_id IS NULL`).

`App\Models\Designation` — plain Eloquent model, `belongsTo(Department::class)`,
`hasMany(User::class)`, fillable list, `default_privileges` cast to `array`. A small helper,
`appliesToDepartment(?int $departmentId): bool` (`department_id === null || department_id ===
$departmentId`), used by the user-form dropdown to filter which designations are selectable once a
Department is chosen.

### 2. `users.designation_id`

New nullable FK column (`nullOnDelete`) on `users`, added via its own migration. Becomes the
primary "what is this person's title" field going forward. **`post` (free-text) is kept, not
removed** — same "Other" escape hatch already used for Policy Type/State: if no Designation fits
yet, an admin can still type a post directly, and add a proper `Designation` row later once the
title is confirmed to recur. Display logic everywhere falls back `designation->name ?? post`.

`role` itself is **unchanged** — no schema change, no new enum value. The only behavioral rule
going forward: `role = admin` is reserved for the developer/IT site-manager account(s). Every real
designation-holder gets `role = operator` (or `viewer` for read-only accounts) plus a `Designation`
and whatever privileges it implies.

### 3. `/admin/designations` — CRUD screen

New `Admin\DesignationController`, routes prefixed `/admin/designations`, `admin.designations.*`
names, gated by the same `IsAdmin` middleware already protecting `/admin/users` — this screen is
explicitly part of the site-management console, not something a department head should reach.

- `index()` — list, grouped by department-specific vs. generic, with each row's `default_scope`
  and privilege count shown.
- `create()`/`store()` — Name, Department (optional — a select with "— Generic, any department —"
  as the null option), Default Scope, Default Privileges (same checkbox panel styling as the user
  form, reused — see below), Sort Order.
- `edit()`/`update()` — same fields. Editing a designation's defaults does **not** retroactively
  touch any existing user (confirmed: one-time preset, not synced) — it only changes what gets
  pre-filled the *next* time that designation is picked.
- `destroy()` — soft-delete only (existing users keep their `designation_id`, name still resolves
  via `withTrashed()` on the relation for display purposes).

A `DesignationSeeder` ships the generic + department-specific list above as a starting point (Excise
and Sugarcane & Sugar Industries departments both get their real-world variants where given; the
purely generic ones are seeded with `department_id = null`). Nothing about this list is hardcoded
into application logic — it's just seed data the CRUD screen can then extend.

### 4. Privilege-checkbox extraction, so the same panel renders in two places

The Granular Privileges checkbox markup currently lives inline in
`resources/views/admin/users/create.blade.php:212-252` (and duplicated in `edit.blade.php`).
Extract it into a shared partial, `resources/views/admin/_privilege_checkboxes.blade.php`,
parameterized by the currently-checked array and an input name prefix — used by:
- the user create/edit forms (existing behavior, unchanged rendering)
- the new Designation create/edit form (`default_privileges` instead of a user's `privileges`)

This is a pure extraction — same markup, same classes, no new `$privilegeLabels` duplication.

### 5. User create/edit form changes

Replace the free-text "Post / Designation" input (`create.blade.php:112-123`) with:
- A Designation `<select>`, options grouped by department (options with a `data-dept` attribute,
  same cascade-filter convention already used for the Section/Division selects at
  `create.blade.php:398-419` — a designation whose `department_id` doesn't match the currently
  selected Department is hidden/disabled, exactly like the existing `filterSections()`/
  `filterDivisions()` pattern). The "— Generic, any department —" designations are always visible
  regardless of Department selection.
- Below it, a small always-visible free-text "Other post (if not listed above)" input, mapped to
  the existing `post` column — only used when no Designation fits.

On `change` of the Designation select (new small JS function, `applyDesignationPreset()`,
colocated with the existing cascade-filter script block):
- If the designation has a non-null `department_id`, set the Department select to that value and
  re-run the existing `filterSections()`/`filterDivisions()` cascade.
- Check the privilege checkboxes listed in that designation's `default_privileges` (data attribute
  on each `<option>`, `data-privileges="[...]"`, parsed via `JSON.parse`).
- Nothing is locked — the admin can immediately uncheck/adjust anything the preset applied. This
  fires once on selection; it does not re-run or re-sync later.

Backend: `StoreUserRequest`/`UpdateUserRequest` gain `designation_id` (`nullable, integer,
exists:designations,id`) validation. `UserManagementController::store()`/`update()` persist it
alongside the existing fields — this is additive, no existing validation rule changes.

### 6. Display updates

Anywhere `$user->post` is currently rendered (user index/show, document uploader byline if any,
activity log user column), switch to `$user->designation?->name ?? $user->post`. Confirmed
locations to update: `resources/views/admin/users/index.blade.php`, `show.blade.php` (if it prints
post), `edit.blade.php`'s current value pre-fill.

### 7. Cleanup of the existing workaround

One-off, not code — an actual data fix once this ships: identify any current user whose `role =
admin` was set only as a scope workaround (the Additional Excise Commissioner case that surfaced
this whole problem) and reassign them `role = operator` + the correct new Designation + whatever
privileges/department that designation implies, so they stop seeing `/admin/users`/
`/admin/designations`/`/admin/activity-logs` while keeping identical document authority. This is a
manual admin-panel action post-deploy, not a migration — re-running `role` logic automatically
would require guessing which admins are "real" IT accounts vs. workarounds, which isn't safe to
infer.

---

## Explicitly out of scope for this pass

- No change to the `role` enum itself (still `admin`/`operator`/`viewer`) — designations layer on
  top, they don't replace it.
- No retroactive/synced privilege updates when a Designation's defaults are edited later (see
  confirmed decision above).
- No change to `canManagePolicy()`/`canManagePolicyForDepartment()` — these already correctly check
  `department.head` + matching `department_id`, which a Commissioner-tier Designation's preset will
  now populate correctly; the policy authorization logic itself doesn't need to know Designations exist.
- No UI restriction preventing a department-specific Designation from being picked for the "wrong"
  department at the raw HTTP level — same trust model as the existing Section/Division selects
  (client-side cascade filter for usability; if this needs a server-side guard too, add
  `designation.appliesToDepartment($request->department_id)` to the Form Request `rules()`/a
  `withValidator()` closure — flagged here to decide during implementation, not decided yet).

## Files touched (planned)

- `database/migrations/xxxx_create_designations_table.php` (new)
- `database/migrations/xxxx_add_designation_id_to_users_table.php` (new)
- `app/Models/Designation.php` (new)
- `app/Models/User.php` — add `designation()` relationship
- `database/seeders/DesignationSeeder.php` (new)
- `app/Http/Controllers/Admin/DesignationController.php` (new)
- `app/Http/Requests/Admin/StoreDesignationRequest.php`, `UpdateDesignationRequest.php` (new)
- `app/Http/Requests/Admin/StoreUserRequest.php`, `UpdateUserRequest.php` — add `designation_id` rule
- `app/Http/Controllers/Admin/UserManagementController.php` — persist `designation_id`
- `routes/web.php` — new `admin.designations.*` group
- `resources/views/admin/designations/index.blade.php`, `create.blade.php`, `edit.blade.php` (new)
- `resources/views/admin/_privilege_checkboxes.blade.php` (new, extracted from `admin/users/create.blade.php`)
- `resources/views/admin/users/create.blade.php`, `edit.blade.php` — Designation select + preset JS,
  use the extracted privilege-checkbox partial
- `resources/views/admin/users/index.blade.php` (and any other view printing `$user->post`) —
  fall back to `designation->name`
- `resources/views/components/sidebar.blade.php` — new "Designations" link under the existing
  admin-only nav group (alongside Users/Activity Log)

## Verification

1. `php artisan migrate --seed` (or a scoped `--class=DesignationSeeder`) — confirm `designations`
   table created and seeded with the generic + Excise/Cane-specific rows.
2. As admin, visit `/admin/designations` — confirm the seeded list renders, grouped generic vs.
   department-specific.
3. Create a new designation from the UI (e.g. a title not in the seed list) with a specific
   Department, a `default_scope`, and a couple of default privileges — confirm it saves and
   immediately appears in the user-creation dropdown filtered to that department.
4. On `/admin/users/create`, select that department, then select the new designation — confirm
   the Department field locks/matches, and the expected privilege checkboxes pre-check.
5. Uncheck one of the pre-checked privileges and save — confirm the user is created with the
   hand-adjusted set, not silently reset to the designation's defaults (proves "preset, not synced").
6. Edit the Designation's `default_privileges` afterward — confirm the previously-created user's
   privileges are unaffected (proves the same thing from the other direction).
7. Create a user with a generic (department_id = null) designation, e.g. "Clerk" — confirm it's
   selectable regardless of which Department is chosen.
8. Confirm a user created this way with a department-scoped designation (e.g. "Additional Excise
   Commissioner" → `department.head` + `documents.verify`/`documents.approve` + `department_id` =
   Excise) can actually verify/approve documents within Excise through the normal document UI —
   and cannot reach `/admin/users`, `/admin/designations`, or `/admin/activity-logs` (302/403).
9. Confirm an existing `role = admin` account (the real IT/dev one) is completely unaffected —
   still sees every admin screen, still bypasses all privilege checks.
10. Reassign the known workaround account (if still `role = admin` at implementation time) to
    `role = operator` + its correct Designation, and confirm no loss of document-side capability
    (spot-check upload/verify/approve on a document within their department) while `/admin/*`
    access is now correctly denied.

---

## Follow-up (2026-08-19, M79) — the workaround recurred, and needed a deeper fix

This plan correctly diagnosed the problem and shipped the Designation preset layer, but did not
close the door on the recurrence: six months later, a live audit found **7 accounts with
`role = admin`**, only one of which should have had it — Designations existed, but Designation
presets kept under-granting real capability (e.g. "Deputy Commissioner (P&E)" only ever granted
`documents.verify`, never `upload`/`edit`), so whoever was onboarding these accounts hit a real
wall and reached for `role = admin` again, because it was still the fastest thing that reliably
worked. Naming this plainly: **a preset that can under-grant is not a fix for a bypass role that
still exists and still works** — it only makes the bypass less *necessary* on average, not
impossible to reach for under time pressure.

**What changed, on top of this plan (not instead of it):**
- `role` itself was split into a real fourth tier: `system_admin` (the true bypass this plan
  always intended to reserve for IT/dev — `User::isAdmin()` now checks this value) and `admin`
  (an org-scoped officer tier — `User::isOrgAdmin()` — that auto-grants a fixed, generous document
  privilege bundle, `ORG_ADMIN_PRIVILEGES`, via `hasPrivilege()`, regardless of what a Designation
  preset did or didn't include). This means an under-granting Designation can no longer strand an
  officer without real capability — the *role* itself now guarantees the baseline document verbs
  (upload/edit/delete/restore/verify/approve), and the Designation preset stays exactly what this
  plan designed it to be: a scope/department-prefill convenience, not the sole source of privilege.
- The actual root cause of the original wall — `DocumentController::canManageDocument()` only ever
  allowing `isAdmin()` or a policy document's `department.head`, so a normal document had **no**
  scoped conversion/verify path at all — is fixed directly (and four more independently-duplicated
  copies of the same check found and fixed alongside it; see `SECURITY.md` Pass 7 H-07). This plan
  never diagnosed this half of the problem; it only built the preset layer on the assumption that
  scope + a privilege in `User::PRIVILEGES` would be enough, which turned out not to be true for
  the document-lifecycle actions specifically.
- Viewing (browsing) was also scoped for the first time (`User::canView()`,
  `Document::scopeViewableBy()`) — unrelated to Designations directly, but discovered in the same
  incident (a section-scoped officer could browse into a sibling section regardless of role).

Full design and verification: `SECURITY.md` Pass 7 (H-06/H-07/M-05/L-05), `summary.md`'s M79 entry,
`claude.md`'s "View-scoping" and updated "users"/"designations" schema sections. This plan's own
seed data was also touched in the same pass — the two per-posting Deputy Excise Commissioner
Designations ("(P&E)" and "(P)") were merged into one generic "Deputy Excise Commissioner", with
the specific posting moved to the user's own `post` field instead — see `POLICY_PERIODS.md`-style
per-user free text, not a new Designation per posting.

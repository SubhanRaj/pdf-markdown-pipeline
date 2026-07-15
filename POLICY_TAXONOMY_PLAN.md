# Policy Taxonomy Feature — Implementation Plan (not yet started)

**Status:** Revised 2026-07-15 (second pass) — all open questions from the first revision have
been answered by the user; this plan is now believed final pending your read-through. Nothing in
this plan has been coded yet — no migration, no model changes, no routes. This file is the durable
record of the plan; delete or archive once implemented and folded into
`claude.md`/`README.md`/`ROADMAP.md`/`summary.md` properly.

## Context

The Odisha/Chandigarh/other-state excise policy documents currently live under the existing
`RuleSet` container (meant for Acts/Rules), reached via `/documents/dept/excise/rules/...`. The
user wants a first-class **Policy** taxonomy, distinct from Rule Sets in the UI/URL, that models
how government policy actually behaves — not just a relabeled Rule Set:

- Uses `/policy` instead of `/rules` in the URL.
- **UP brings a new policy every year; other states may run a policy for one year or span several
  years (and UP could do the same in the future)** — so a policy needs an explicit validity
  period, not just a single "year" tag.
- **A policy can be amended mid-season** (same as Rule Set amendments today — a correction/addendum
  document chained via `parent_id`), but **the policy itself stays valid until a new policy period
  is uploaded to replace it** — validity is not date-driven, it's supersession-driven.
- **When a new policy period is uploaded, the previous one becomes dormant** — not deleted, not
  hidden, fully browsable and citable for old references/pending cases — but no longer the
  default "current" policy that new citations point to.
- Effective period needs a start and end date (calendar input), shown alongside the policy.
- **Upload defaults to Uttar Pradesh** — each department's "Add Policy" flow assumes UP by
  default (Excise → UP Excise Policy, Cane → UP Sugarcane Policy, etc.) with a separate,
  explicit action to add another state's policy (state as a dropdown, plus year/type).
- **`policy_type` must be a constrained dropdown, not free text** — so "Excise Policy" /
  "excise policy" / "Excise policy" never fragment search, filters, or listings. Same reasoning
  extended to `state` (dropdown, not free text) for the same typo/consistency reason.
- Works for every department that has its own policies, **including departments created in the
  future, with no per-department allowlist** — confirmed by the user: any department, existing or
  future, automatically gets a "Add Policy" capability. Policy is a department-level concept only,
  never scoped to a Section or Division (this falls out for free from reusing `RuleSet`, which
  already only ever belongs to a `department_id`).
- **Amendments can be uploaded to any policy, current or superseded** — confirmed by the user.
  Dormant just means "not the default citation," not "frozen." A superseded policy can still
  receive a correction, a court-order attachment, or any other amendment document.
- **A policy can also be withdrawn/scrapped outright by the government mid-year**, not just
  superseded at year-end — this doesn't need a new status. It's the same "archive a document" flow
  that already exists everywhere else in this app: `RuleSet` already has `SoftDeletes`, so a
  department head/admin scrapping a policy uses the existing archive action, same as archiving any
  other container. No new column, no new lifecycle state for this case.
- Is locked down: only a department's `department.head` or a global `admin` may create/manage a
  policy container or mutate any policy document (upload, edit, delete, convert, OCR, verify,
  discard, revert, or start a new policy period). Everyone else is **view-only**. Confirmed with
  the user: `isAdmin()` = "superadmin", existing `department.head` privilege (already scoped to
  the user's own `department_id`) = "departmental head" — no new roles invented.

## Design decision: extend `RuleSet` with a `kind` discriminator, not a new model

Unchanged from the first pass — still reusing `RuleSet` rather than a parallel model/controller.
See the original reasoning below; still holds after the new requirements, because everything new
(period dates, supersession, controlled vocabularies) is just more columns and one extra branch of
logic on the same container, not a structurally different entity.

- `RuleSet` (`app/Models/RuleSet.php`) is already structurally what a policy *period* needs: single
  `department_id` scope, `metadata` json, `requires_approval`, slug-per-department uniqueness.
- Amendment chains are *not* a RuleSet-level concept at all — they live entirely on `Document`
  (`parent_id`/`amendments()`, `app/Models/Document.php:199-207`) and are shared by every
  container type already. Nothing to build here; policies get mid-season amendments for free,
  exactly like Rule Set amendments today.
- `RuleSetController` and the five `DocumentController` rule-set-document methods
  (`showRuleSetDoc`, `pdfRuleSetDoc`, `editRuleSetDoc`, `updateRuleSetDoc`, `destroyRuleSetDoc`)
  don't reference "rules" semantics anywhere in their logic — a parallel `/policy` route pointing
  at the *same* controller methods works unmodified, except for the new kind-aware branches below.
- A full duplicate model/controller/views would just be copy-pasted RuleSet code with almost no
  behavioral difference — not justified.

**What's new in this revision, beyond the original plan:** a policy *period* (one year, or a
multi-year span) is one `RuleSet` row — same as before. What's new is that **uploading a new
policy period for a line that already has a current one is a supersession event**, not just
"create another container." The previous period's row is flagged dormant, the new one records
which period it replaces, and the department's default "current policy" views only ever surface
the non-dormant one.

## Data model changes

**New migration** `add_kind_state_policy_type_period_to_rule_sets_table`:
```php
$table->enum('kind', ['rules', 'policy'])->default('rules')->after('slug');
$table->string('state')->nullable()->after('kind');            // dropdown-selected, e.g. "Uttar Pradesh", "Odisha" — only meaningful when kind=policy
$table->string('policy_type')->nullable()->after('state');     // dropdown-selected against RuleSet::POLICY_TYPES — only meaningful when kind=policy
$table->date('effective_start_date')->nullable()->after('policy_type');
$table->date('effective_end_date')->nullable()->after('effective_start_date');
$table->enum('policy_status', ['current', 'superseded'])->default('current')->after('effective_end_date');
$table->foreignId('previous_policy_id')->nullable()->after('policy_status')
    ->constrained('rule_sets')->nullOnDelete();
```

Why `policy_status` is separate from `effective_end_date`: the user was explicit that a policy
"will be valid till new policy is not in place" — validity is a *supersession* fact, not a
calendar fact. A policy's stated end date and the date it actually gets replaced rarely line up
exactly (a policy period can run past its nominal end date while awaiting the next one). So
`effective_start_date`/`effective_end_date` stay purely descriptive (what the document itself
says), and `policy_status` is the one field the app actually trusts to answer "is this the policy
to cite right now."

No changes to `documents` table — policy documents still use the existing `rule_set_id` FK,
`parent_id` amendment chain, and `metadata.effective_year`/`amendment_number` fields exactly as
Rule Set documents do today (those describe an individual amendment document's own date, not the
container's period).

**Exact migration file** — `database/migrations/{YYYY_MM_DD}_add_policy_fields_to_rule_sets_table.php`
(naming follows the existing `2026_06_26_000001_add_requires_approval_to_sections_divisions_rule_sets.php`
convention — alter-in-place, not a new table):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->enum('kind', ['rules', 'policy'])->default('rules')->after('slug');
            $table->string('state')->nullable()->after('kind');
            $table->string('policy_type')->nullable()->after('state');
            $table->date('effective_start_date')->nullable()->after('policy_type');
            $table->date('effective_end_date')->nullable()->after('effective_start_date');
            $table->enum('policy_status', ['current', 'superseded'])->default('current')->after('effective_end_date');
            $table->foreignId('previous_policy_id')->nullable()->after('policy_status')
                ->constrained('rule_sets')->nullOnDelete();

            $table->index(['department_id', 'kind', 'state', 'policy_type', 'policy_status'], 'rule_sets_policy_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->dropForeign(['previous_policy_id']);
            $table->dropIndex('rule_sets_policy_lookup_idx');
            $table->dropColumn(['kind', 'state', 'policy_type', 'effective_start_date', 'effective_end_date', 'policy_status', 'previous_policy_id']);
        });
    }
};
```
The composite index backs the supersession lookup in `store()` (department + state + policy_type +
current status) — without it, that query is a full scan of `rule_sets` on every policy creation.
Cheap to add now, this table will never be huge, but it's the correct index regardless of size.

**`RuleSet` model** (`app/Models/RuleSet.php`):
- Add `kind`, `state`, `policy_type`, `effective_start_date`, `effective_end_date`,
  `policy_status`, `previous_policy_id` to `$fillable`; cast the two date columns.
- Query scopes: `scopeRules($q)` (`where('kind', 'rules')`), `scopePolicy($q)`
  (`where('kind', 'policy')`), `scopeCurrentPolicy($q)` (`where('kind', 'policy')->where
  ('policy_status', 'current')`).
- `supersededBy(): HasOne` — `RuleSet::where('previous_policy_id', $this->id)` — used to render
  "superseded by →" banners on a dormant policy's show page without a denormalized forward pointer.
- `previousPolicy(): BelongsTo` — the existing `previous_policy_id` FK.
- **New constant `RuleSet::POLICY_TYPES`** — controlled vocabulary, shared across all departments
  (a department's create-policy form isn't restricted to a subset — any department can pick any
  type). **Corrected 2026-07-15 (was wrong in the prior revision)**: only the state/government's
  *actual named policies* belong here — UP Excise Policy, UP Cane Policy, UP Sugar Policy, UP
  Export Policy, UP Import Policy, etc. Bar, Beer, Vending, Bottling, and Distillery are **not**
  policies — they're **rules** (subject-specific rules — how a brewery must operate, how molasses
  must be stored/transported, how a bar must run) that live *inside* the main policy document but
  get pulled out into their own standalone document too, purely so a reader interested in just
  Bar rules doesn't have to read the entire policy PDF to find them. That extraction-for-browsing
  concept already exists in this app — it's exactly what the pre-existing `RuleSet` (`kind =
  'rules'`) taxonomy is for. It does **not** belong in `POLICY_TYPES`, and Policy and Rules stay
  two separate, independently browsable taxonomies (`/policy` vs `/rules`) even though a given
  rule set (e.g. "UP Bar Rules") is derived from content that also appears inside a policy
  document (e.g. "UP Excise Policy 2026-27") — the two containers are related in subject matter
  only, not linked by any FK.
  ```php
  public const POLICY_TYPES = [
      'excise_policy' => 'Excise Policy',
      'cane_policy'   => 'Cane Policy',
      'sugar_policy'  => 'Sugar Policy',
      'import_policy' => 'Import Policy',
      'export_policy' => 'Export Policy',
      'other'         => 'Other',
  ];
  ```
  This list is a flat, closed vocabulary — one named policy per government, per subject area. Each
  policy container picks exactly one `policy_type`.
- **New constant `RuleSet::STATES`** — the 28 states + 8 union territories of India, hardcoded (a
  real, closed, essentially-static list). "Uttar Pradesh" is the conventional default value
  pre-selected in the primary upload flow.
- **`'other'` fallback on both `policy_type` and `state`** — confirmed by the user: a dropdown
  entry `Other` is available on both, revealing a free-text input when selected (the underlying
  columns are already plain `string`, so no schema change — just extra validation branching).
  Since this text lands in fields that drive search, filtering, and page headings, it's held to
  the same standard as every other free-text field in this codebase:
  - `prepareForValidation()`: `strip_tags()` + `trim()`.
  - Validation regex: the same Unicode-safe pattern already used for `name`/`title` fields
    project-wide — `/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u` (`\p{L}`/`\p{M}` so a state or policy
    name in Devanagari isn't rejected) — `max:100`.
  - The `<select>`'s `other` option and the adjacent `<input>` are validated together server-side:
    `state`/`policy_type` is `required|in:` the constant's keys **plus** `'other'`; when the
    submitted value is `'other'`, a sibling `state_other`/`policy_type_other` field becomes
    `required` under the above rules and its sanitized value is what actually gets stored in the
    `state`/`policy_type` column (never the literal string `"other"`).
  - Caveat carried over into search/filter UX: an `Other` entry is inherently less clean than a
    canonical dropdown value (two different free-text entries can still mean the same real-world
    state/type) — accepted tradeoff for the escape hatch, not a bug to fix later.

## Supersession logic (new policy period replaces the old one)

No new route or controller method — reusing the existing `RuleSetController::store()` call for
both "first policy ever for this line" and "new year replacing the current one," distinguished
only by whether a matching row already exists. This is the smaller diff than a parallel
"new-period" endpoint, and the two cases share every field.

Inside `store()`, when `kind === 'policy'`, after creating the new `RuleSet` row inside the
existing `DB::transaction()`:

```php
$previousCurrent = RuleSet::currentPolicy()
    ->where('department_id', $department->id)
    ->where('state', $validated['state'])
    ->where('policy_type', $validated['policy_type'])
    ->where('id', '!=', $newPolicy->id)
    ->first();

if ($previousCurrent) {
    $previousCurrent->update(['policy_status' => 'superseded']);
    $newPolicy->update(['previous_policy_id' => $previousCurrent->id]);
}
```

**UX safety net:** even though this reuses the plain "Create Policy" form/route, the create form
detects (via an AJAX existence check or a server-rendered flag) that a current policy already
exists for the selected department + state + policy_type, and shows a non-blocking warning before
submit: *"This will supersede the currently active [state] [policy_type] policy for [department]
— the old one will be marked historical, not deleted."* A SweetAlert2 confirmation on submit in
that case, consistent with how the rest of this app confirms consequential actions. This avoids a
department head accidentally superseding a still-relevant policy by picking the same
state/type combination without realizing one already exists.

**Mid-season amendment vs new policy period — same UI split as Rule Sets today:** a policy
container's show page gets the same two-modal pattern already built for Rule Sets
(`#modal-rule`/`#modal-amendment` → `#modal-policy-doc`/`#modal-policy-amendment`): "Upload Policy
Document" (root doc for this period) and "Upload Amendment" (mid-season correction, chained via
`parent_id`, same as today). Starting a **new policy period** is a different action entirely — not
a document upload — it's a new "Add Policy" flow at the department level (see below), not a
button on an existing container's show page.

## Permission changes

**New `User` method** (`app/Models/User.php`, near `canUploadTo()`):
```php
public function canManagePolicy(RuleSet $policySet): bool
{
    return $this->isAdmin()
        || ($this->hasPrivilege('department.head') && $this->department_id === $policySet->department_id);
}
```
Unchanged from the original plan — deliberately not `canUploadTo()`, since that method's generic
`department` scope lets any user with a matching bare `department_id` upload without holding the
`department.head` privilege; policy needs the stricter check the user asked for.

**Gates that switch to `canManagePolicy()` when the target `RuleSet.kind === 'policy'`:**
- `StoreRuleSetRequest`/`UpdateRuleSetRequest` — creating/editing the policy container itself
  (including the supersession path, since it's the same `store()` call).
- `StoreDocumentRequest::authorize()`, `UpdateDocumentRequest`, `DeleteDocumentRequest` — when
  `rule_set_id` resolves to a policy-kind RuleSet.
- `DocumentController::convert`, `convertOcr`, `revertOcr`, `discardMarkdown`, and
  `UpdateDocumentMarkdownRequest::authorize()` — each gets an added
  `|| ($document->ruleSet?->kind === 'policy' && auth()->user()->canManagePolicy($document->ruleSet))`
  branch.

**Pitfall to avoid** (flagged by `SECURITY.md` H-03, an existing open bug in
`UpdateFolderRequest`): never rely on an empty/missing validation rule to "hide" a field from being
persisted — always explicitly control what `$request->validated()` contains. This applies directly
to `policy_status`/`previous_policy_id` on `UpdateRuleSetRequest` — these must never be
user-settable via a raw PATCH; only the supersession logic in `store()` may set them.

## Routing changes

Confirmed against the actual current `routes/web.php` (not the abstract description from the
first pass) — four existing `/rules` blocks get a sibling `/policy` block each, same controller
methods, `->defaults('kind', 'policy')`. Existing `/rules` blocks each get `->defaults('kind',
'rules')` added too, for symmetry (today's default is implicit via the column default only).

**1. Public rule-set document show/pdf** — inside the `documents` prefix group, immediately after
the existing block at line 43-46:
```php
// existing (gets ->defaults('kind', 'rules') added):
Route::prefix('/{level}/{department}/rules/{rule_set}')->name('rules.')->group(function () {
    Route::get('/{document}',     [DocumentController::class, 'showRuleSetDoc'])->name('show');
    Route::get('/{document}/pdf', [DocumentController::class, 'pdfRuleSetDoc'])->name('pdf');
})->defaults('kind', 'rules');

// new:
Route::prefix('/{level}/{department}/policy/{rule_set}')->name('policy.')->group(function () {
    Route::get('/{document}',     [DocumentController::class, 'showRuleSetDoc'])->name('show');
    Route::get('/{document}/pdf', [DocumentController::class, 'pdfRuleSetDoc'])->name('pdf');
})->defaults('kind', 'policy');
```

**2. Public rule-set create/show** — inside the `departments` prefix group, at line 88-91:
```php
// existing (gets ->defaults('kind', 'rules') added):
Route::prefix('/{level}/{department}/rules')->name('rules.')->group(function () {
    Route::get('/create',     [RuleSetController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
    Route::get('/{rule_set}', [RuleSetController::class, 'show'])->name('show');
})->defaults('kind', 'rules');

// new:
Route::prefix('/{level}/{department}/policy')->name('policy.')->group(function () {
    Route::get('/create',     [RuleSetController::class, 'create'])->name('create')->middleware(['auth', 'throttle:mutations']);
    Route::get('/{rule_set}', [RuleSetController::class, 'show'])->name('show');
})->defaults('kind', 'policy');
```

**3. Rule-set document mutations** — inside the auth-protected `documents` prefix group, at line
122-126:
```php
// existing (gets ->defaults('kind', 'rules') added):
Route::prefix('/{level}/{department}/rules/{rule_set}')->name('rules.')->group(function () {
    Route::get('/{document}/review', [DocumentController::class, 'editRuleSetDoc'])->name('edit');
    Route::patch('/{document}',      [DocumentController::class, 'updateRuleSetDoc'])->name('update');
    Route::delete('/{document}',     [DocumentController::class, 'destroyRuleSetDoc'])->name('destroy');
})->defaults('kind', 'rules');

// new:
Route::prefix('/{level}/{department}/policy/{rule_set}')->name('policy.')->group(function () {
    Route::get('/{document}/review', [DocumentController::class, 'editRuleSetDoc'])->name('edit');
    Route::patch('/{document}',      [DocumentController::class, 'updateRuleSetDoc'])->name('update');
    Route::delete('/{document}',     [DocumentController::class, 'destroyRuleSetDoc'])->name('destroy');
})->defaults('kind', 'policy');
```

**4. Rule set mutations** — inside the auth-protected `departments` prefix group, at line 187-192:
```php
// existing (gets ->defaults('kind', 'rules') added):
Route::prefix('/{level}/{department}/rules')->name('rules.')->group(function () {
    Route::post('/',               [RuleSetController::class, 'store'])->name('store');
    Route::get('/{rule_set}/edit', [RuleSetController::class, 'edit'])->name('edit');
    Route::patch('/{rule_set}',    [RuleSetController::class, 'update'])->name('update');
    Route::delete('/{rule_set}',   [RuleSetController::class, 'destroy'])->name('destroy');
})->defaults('kind', 'rules');

// new:
Route::prefix('/{level}/{department}/policy')->name('policy.')->group(function () {
    Route::post('/',               [RuleSetController::class, 'store'])->name('store');
    Route::get('/{rule_set}/edit', [RuleSetController::class, 'edit'])->name('edit');
    Route::patch('/{rule_set}',    [RuleSetController::class, 'update'])->name('update');
    Route::delete('/{rule_set}',   [RuleSetController::class, 'destroy'])->name('destroy');
})->defaults('kind', 'policy');
```

**Controller signature change required by `->defaults('kind', ...)`:** every `RuleSetController`
method currently bound to these routes must accept `string $kind = 'rules'` as a parameter (Laravel
injects route defaults the same as route parameters). Current signatures (confirmed against
`app/Http/Controllers/RuleSetController.php`) and the exact change needed:

| Method | Current signature | New signature |
|---|---|---|
| `create` | `create(string $level, Department $department): View` | add `string $kind = 'rules'` |
| `store` | `store(StoreRuleSetRequest $request, string $level, Department $department): RedirectResponse` | add `string $kind = 'rules'` |
| `show` | `show(Request $request, string $level, Department $department, RuleSet $ruleSet): View` | **no change** — `$ruleSet->kind` already tells the view everything; `kind` route default isn't needed here since `{rule_set}` is already bound |
| `edit` | `edit(string $level, Department $department, RuleSet $ruleSet): View` | **no change**, same reasoning |
| `update` | `update(UpdateRuleSetRequest $request, string $level, Department $department, RuleSet $ruleSet): RedirectResponse` | **no change** |
| `destroy` | `destroy(string $level, Department $department, RuleSet $ruleSet): RedirectResponse` | **no change** |

Only `create()` and `store()` need the new parameter — every other method already receives a bound
`RuleSet` model whose own `kind` column is authoritative.

**No `Route::bind()` change needed** — the existing `Route::bind('rule_set', ...)` in
`AppServiceProvider::configureRouteBindings()` already scopes lookups to `WHERE slug = ? AND
department_id = ?`, which is kind-agnostic and correct as-is; a policy and a rule set in the same
department simply can't collide on slug (enforced by the existing `uniqueSlugForDepartment` check,
unchanged).

Result: `/documents/dept/excise/policy/up-excise-policy-2025-27/...` and (after supersession, still
resolvable) `/documents/dept/excise/policy/up-excise-policy-2023-25/...` both keep working forever
— URL stability for old case citations is a hard requirement here, not just a nice-to-have,
because the whole point of "dormant, not deleted" is that pending cases can still cite the old URL.

## Controller changes

`RuleSetController`:
- `create(string $level, Department $department, string $kind = 'rules')` — passes `$kind` to the
  view. For `kind === 'policy'`, also passes `RuleSet::STATES`, `RuleSet::POLICY_TYPES`, and a
  default-selected `policy_type` guessed from the department slug (UI nicety only — e.g. Excise
  department pre-selects "Excise Policy", Cane pre-selects "Cane Price Policy" — user can change
  it; not enforced).
- `store(StoreRuleSetRequest $request, string $level, Department $department, string $kind =
  'rules')` — sets `kind`, and for `policy`: `state` (defaults to `'Uttar Pradesh'` server-side if
  the request omits it — the primary "Add UP Policy" button doesn't even render a state field, it
  submits the default directly; the secondary "Add Other State Policy" button reveals the state
  dropdown), `policy_type`, `effective_start_date`, `effective_end_date`. Runs the supersession
  check described above inside the same transaction.
- `show()`/`edit()`/`update()` — no signature change; `$ruleSet->kind`/`policy_status` drive the
  view directly. `show()` additionally passes `$supersededBy` (via the new relation) when
  `policy_status === 'superseded'`, so the view can render the "superseded by →" banner.

`DocumentController`'s five rule-set-document methods — still no changes needed, kind-agnostic.

`DepartmentController::show()` — split `$ruleSets` (`->rules()` scope) from `$policies`
(`->policy()->currentPolicy()` scope, i.e. only non-dormant by default) plus a separate,
collapsed-by-default `$historicalPolicies` (`->policy()->where('policy_status', 'superseded')`)
section so old periods stay reachable from the department page without cluttering the default view.

## View changes

- `resources/views/rule_sets/{create,show,edit}.blade.php` — reused for both kinds, conditional
  copy driven by `$kind`/`$ruleSet->kind`. For `kind === 'policy'` the create/edit form additionally
  shows:
  - Two buttons at the top: **"Add [Department]'s UP Policy"** (state hidden, defaults to Uttar
    Pradesh) and **"Add Other State's Policy"** (reveals the state `<select>`, defaulted list from
    `RuleSet::STATES`).
  - `policy_type` — `<select>` from `RuleSet::POLICY_TYPES` plus the `Other` free-text escape hatch
    described above.
  - `state` — `<select>` from `RuleSet::STATES` plus the same `Other` free-text escape hatch.
  - `effective_start_date` / `effective_end_date` — user asked for **Cleave.js** here instead of
    native `<input type="date">` (this codebase has already hit native-control dark-mode/styling
    friction once, in the OCR engine `<select>` — see the 2026-07-14 dark-mode-contrast commit —
    so a consistently-styled masked text input is the safer choice for a date pair too). Loaded
    via jsDelivr (`cleave.js`), page-scoped `@push('scripts')` on the create/edit policy view only
    — same CDN-and-page-scoped pattern already used for `marked.js` and SweetAlert2, no Node/build
    step introduced. Two `<input type="text" inputmode="numeric">` fields masked to `DD-MM-YYYY`
    (the conventional Indian government document date format), each paired with a hidden
    `<input type="hidden" name="effective_start_date">`/`..._end_date` that Cleave's `onValueChanged`
    callback fills with the normalized `YYYY-MM-DD` string actually submitted — the visible masked
    input is never itself the field Laravel validates as `date`.
  - If the department+state+policy_type combination already has a current policy, a warning banner
    appears before submit (see Supersession logic above), and the SweetAlert2 confirmation fires
    on submit instead of the plain success flow.
- `resources/views/rule_sets/show.blade.php` (policy mode) — amber "Superseded — kept for
  historical reference only. Current policy: [link]" banner when `policy_status === 'superseded'`.
  Upload modals become "Upload Policy Document" / "Upload Amendment" (same disabled-until-root-doc
  gating already built for Rule Sets).
- `resources/views/department/show.blade.php` — new "Policies" section (current only) alongside
  the existing "Rule Sets" section, plus a collapsed "Historical Policies" disclosure for
  superseded ones. Same list-item partial style as the existing Rule Sets block.
- Upload pickers — `buildUploadScopeTree()`, bulk-upload page, inline upload modals: add "Policy"
  as a selectable context, listing **both current and superseded** policy containers (confirmed —
  amendments are allowed on any policy regardless of status), with a `(Superseded)` suffix on
  dormant entries in the picker so the uploader can tell them apart at a glance.
- **Default document type**: policy uploads default the `document_type` `<select>` to `policy`
  (already exists in `Document::DOCUMENT_TYPES`).
- View-only state (no edit/upload/convert buttons) for users who fail `canManagePolicy()` — same
  pattern as `$needsOcrReview`/`$hasMarkdown` button-gating already in `documents/show.blade.php`.

## Docs to update (after implementation, before considering this done)

- `claude.md` — new "Document Taxonomy" section documenting Section/Division/RuleSet(Rules &
  Policy)/Folder side by side, the `canManagePolicy()` permission model, and the supersession
  lifecycle (`policy_status`, `previous_policy_id`).
- `README.md` — feature bullet + changelog entry.
- `ROADMAP.md` — remove/mark-done if listed as a future item, or add a short note otherwise.
- `summary.md` — new milestone entry (`## M31 — Policy Taxonomy`) in the existing running-log style.
- This file can be deleted once the above docs cover everything in it.

## Verification

- `php artisan migrate` runs clean; existing Rule Set rows default to `kind='rules'`, all new
  policy columns null — no data migration needed for existing rows.
- Existing `/rules` URLs (e.g. the Odisha/Chandigarh policies currently under Excise's rule set)
  continue to work unchanged.
- Create "UP Excise Policy 2025-27" as a department.head for Excise → confirm it's reachable at
  `/departments/dept/excise/policy/up-excise-policy-2025-27` with `policy_status = 'current'`.
- Create "UP Excise Policy 2027-29" for the same department+state+policy_type → confirm the 2025-27
  container flips to `policy_status = 'superseded'`, the new one's `previous_policy_id` points at
  it, both URLs still resolve, and the department page's default "Policies" list shows only
  2027-29 while "Historical Policies" shows 2025-27 with a working "superseded by →" link.
- Create an Odisha policy via "Add Other State's Policy" → confirm it does not affect UP's current
  policy for the same department (different `state`, so no supersession triggered).
- Log in as a plain `operator`/`viewer` (no `department.head`) scoped to Excise → confirm no
  create/upload/edit/convert/OCR/verify/discard/new-period controls render for policy, and a
  direct POST to any of those routes for a policy document returns 403.
- Log in as `department.head` for Excise → confirm full CRUD + convert/OCR/verify/discard +
  starting a new policy period works for Excise's policies, and the same user gets 403 attempting
  the same actions against a Cane Federation policy.
- `policy_type` and `state` dropdowns never accept free text — confirm via direct POST with an
  out-of-list value → 422.

## Open questions — all resolved

Every open question from the first revision has been answered:

1. `POLICY_TYPES` list — confirmed (Excise, Cane, Sugar, Import, Export, Bar, Beer, Vending,
   Bottling, Distillery, Other) — see the constant above.
2. Amendments on superseded policies — allowed, confirmed.
3. `STATES` "Other" escape hatch — yes, on both `state` and `policy_type`, with sanitization as
   detailed above.
4. Department scope — every department, existing or future, no allowlist — confirmed.

One implementation detail decided unilaterally rather than re-asked, flagged here in case it's
wrong: the `DD-MM-YYYY` display format for the Cleave.js date fields. If a different display
format is wanted, say so before coding — trivial to change, not worth blocking on.

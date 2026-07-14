# Policy Taxonomy Feature — Implementation Plan (not yet started)

**Status:** Planned and approved in principle 2026-07-14, implementation **on hold** pending a
closer read-through by the user. Nothing in this plan has been coded yet — no migration, no
model changes, no routes. This file is the durable record of the plan; delete or archive once
implemented and folded into `claude.md`/`README.md`/`ROADMAP.md`/`summary.md` properly.

## Context

The Odisha/Chandigarh/other-state excise policy documents currently live under the existing
`RuleSet` container (meant for Acts/Rules), reached via `/documents/dept/excise/rules/...`. The
user wants a first-class **Policy** taxonomy, distinct from Rule Sets in the UI/URL, that:

- Uses `/policy` instead of `/rules` in the URL.
- Supports amendment chains (policies get revised, e.g. new years' editions).
- Is organized year-wise and type-wise (e.g. "Excise Policy 2026-27" vs "Bar Policy 2024-25").
- Works for every department that has its own policies, not just Excise (Cane Federation, Sugar
  Mill Corporation, Sugarcane & Sugar Industries all already exist as departments).
- Supports policies from **other Indian states** (reference/comparison material — the Odisha,
  Chandigarh, etc. PDFs currently being uploaded), tagged by state.
- Is locked down: only a department's `department.head` or a global `admin` may create a policy
  container, upload/edit/delete documents in it, or run any mutation (convert, OCR, verify,
  discard, revert) on a policy document. Everyone else (viewer, operator, even an unrelated
  department's head) is **view-only** for policies. Confirmed with the user: `isAdmin()` = the
  "superadmin" they mean, and the existing `department.head` privilege (already scoped to a
  user's own `department_id`) = "departmental head" — no new roles invented.

## Design decision: extend `RuleSet` with a `kind` discriminator, not a new model

Investigated whether Policy needs its own model/controller/migration (mirroring `RuleSet`
1:1) versus reusing `RuleSet` with a `kind` column. Went with **reuse**, because:

- `RuleSet` (`app/Models/RuleSet.php`) is already structurally exactly what Policy needs: single
  `department_id` scope, `metadata` json, `requires_approval`, slug-per-department uniqueness.
- Amendment chains are *not* a RuleSet-level concept at all — they live entirely on `Document`
  (`parent_id`/`amendments()`, `app/Models/Document.php:199-207`) and are shared by every
  container type already. Nothing to build here; policies get amendments for free.
- `RuleSetController` (`app/Http/Controllers/RuleSetController.php`) and the five
  `DocumentController` rule-set-document methods (`showRuleSetDoc`, `pdfRuleSetDoc`,
  `editRuleSetDoc`, `updateRuleSetDoc`, `destroyRuleSetDoc`, lines 716-802) don't reference
  "rules" semantics anywhere in their logic — they operate generically on whatever `RuleSet` they're
  bound to. A parallel `/policy` route pointing at the *same* controller methods works unmodified,
  except for the small `kind`-aware branches described below.
- A full duplicate model/controller/views (~500 lines + 4 Blade views) would just be copy-pasted
  RuleSet code with almost no behavioral difference — not justified given how much is shared.

The one deviation from "pure UI tweak": the **permission** requirement (dept-head+admin only,
not the generic `canUploadTo()` scope everyone else gets) is genuinely new logic, not just
labels — this is real backend work, described below.

## Data model changes

**New migration** `add_kind_state_policy_type_to_rule_sets_table`:
```php
$table->enum('kind', ['rules', 'policy'])->default('rules')->after('slug');
$table->string('state')->nullable()->after('kind');       // e.g. "Uttar Pradesh", "Odisha" — only meaningful when kind=policy
$table->string('policy_type')->nullable()->after('state'); // e.g. "Excise Policy", "Bar Policy", "Export Policy"
```
No changes to `documents` table — policy documents still use the existing `rule_set_id` FK,
`parent_id` amendment chain, and `metadata.effective_year`/`amendment_number` fields exactly as
Rule Set documents do today.

**`RuleSet` model** (`app/Models/RuleSet.php`): add `kind`, `state`, `policy_type` to `$fillable`.
Add two query scopes for clarity at call sites: `scopeRules($q)` (`where('kind', 'rules')`) and
`scopePolicy($q)` (`where('kind', 'policy')`).

## Permission changes

**New `User` method** (`app/Models/User.php`, near `canUploadTo()`):
```php
public function canManagePolicy(RuleSet $policySet): bool
{
    return $this->isAdmin()
        || ($this->hasPrivilege('department.head') && $this->department_id === $policySet->department_id);
}
```
This is deliberately *not* `canUploadTo()` — that method's generic `department` scope already
lets any user with a matching bare `department_id` (no `department.head` privilege needed)
upload to a RuleSet/Section/Division in their department. Policy needs the stricter check the
user asked for, so it gets its own method rather than changing `canUploadTo()`'s behavior for
every other container.

**Gates that switch to `canManagePolicy()` when the target `RuleSet.kind === 'policy'`:**
- `StoreRuleSetRequest`/`UpdateRuleSetRequest` — creating/editing the policy container itself.
  For `store()`, `kind` comes from the route (see below) before any RuleSet exists yet, so the
  check there is `isAdmin() || (hasPrivilege('department.head') && department_id === route
  department's id)` — same shape as `StoreSectionRequest`'s existing pattern
  (`app/Http/Requests/StoreSectionRequest.php:24-25`), just reused for policy specifically.
- `StoreDocumentRequest::authorize()` — when `rule_set_id` resolves to a policy-kind RuleSet, use
  `canManagePolicy()` instead of `canUploadTo()`.
- `UpdateDocumentRequest`, `DeleteDocumentRequest` — same branch, resolving the policy-kind
  RuleSet via the bound `{rule_set}` route parameter.
- `DocumentController::convert`, `convertOcr`, `revertOcr`, `discardMarkdown`, and
  `updateMarkdown`'s `UpdateDocumentMarkdownRequest::authorize()` — all currently flat
  `isAdmin()` checks; each gets an added `|| $document->ruleSet?->kind === 'policy' &&
  auth()->user()->canManagePolicy($document->ruleSet)`-style branch so a department head can
  manage their own department's policy documents through the full lifecycle, while staying
  blocked from every other admin-only action elsewhere in the app.

**Pitfall to avoid** (flagged by `SECURITY.md` H-03, an existing bug in `UpdateFolderRequest`):
never rely on an empty/missing validation rule to "hide" a field from being persisted — always
explicitly control what `$request->validated()` contains, so a locked-down field can't be
smuggled in via a raw PATCH from a user who fails `authorize()` for the intended action but still
passes some other route.

## Routing changes

Mirror all four existing `/rules` route blocks in `routes/web.php` (lines 43, 88, 122, 187) with
a `/policy` prefix, `policy.` route name, pointing at the **same controller methods**, with
`->defaults('kind', 'policy')` so `RuleSetController::create()`/`store()` know which kind to
create without a URL segment for it. Existing `/rules` routes get `->defaults('kind', 'rules')`
too, for symmetry (defaults today implicitly via the column default).

Example (public show/pdf block, mirroring line 43):
```php
Route::prefix('/{level}/{department}/policy/{rule_set}')->name('policy.')->group(function () {
    Route::get('/{document}',     [DocumentController::class, 'showRuleSetDoc'])->name('show');
    Route::get('/{document}/pdf', [DocumentController::class, 'pdfRuleSetDoc'])->name('pdf');
})->defaults('kind', 'policy');
```
Result: `/documents/dept/excise/policy/excise-policy-odisha/odisha-excise-policy-2026-29` becomes
a real, working URL alongside (not replacing) the existing `/rules` one — no existing links break.

## Controller changes

`RuleSetController` (`app/Http/Controllers/RuleSetController.php`):
- `create(string $level, Department $department, string $kind = 'rules')` — passes `$kind` to
  the view.
- `store(StoreRuleSetRequest $request, string $level, Department $department, string $kind =
  'rules')` — sets `'kind' => $kind` (plus `state`/`policy_type` from validated input when
  `kind === 'policy'`) on create.
- `show()`/`edit()`/`update()` — no signature change needed; they already receive the bound
  `RuleSet $ruleSet` instance, so `$ruleSet->kind` drives the view's labels directly.

`DocumentController`'s five rule-set-document methods — **no changes needed**, they're kind-agnostic.

`DepartmentController::show()` (`app/Http/Controllers/DepartmentController.php:51-71`) — split
the existing `$ruleSets = $department->ruleSets()...` query into `$ruleSets` (`->rules()`
scope) and `$policySets` (`->policy()` scope), pass both to the view.

## View changes

- `resources/views/rule_sets/{create,show,edit}.blade.php` — reused as-is for both kinds, with
  conditional copy driven by `$kind`/`$ruleSet->kind` (e.g. `{{ $kind === 'policy' ? 'Policy' :
  'Rule Set' }}` in headings/buttons). `create`/`edit` forms show `state` (text input, default
  "Uttar Pradesh") and `policy_type` (text input) fields only when `$kind === 'policy'`.
- `resources/views/department/show.blade.php` — new "Policies" listing section alongside the
  existing "Rule Sets" section, same list-item partial style, using `$policySets`.
- Upload pickers — `buildUploadScopeTree()` (`DocumentController.php:75-157`), bulk-upload page,
  and inline per-container upload modals: add "Policy" as a selectable context (filtered by
  `kind`), alongside Section/Division/RuleSet/Folder.
- **Default document type**: when the upload context resolves to a policy-kind RuleSet, the
  `document_type` `<select>` (already has a `'policy'` option — `Document::DOCUMENT_TYPES`,
  `app/Models/Document.php:18-27` — already wired everywhere) defaults to `policy` selected,
  instead of whatever the current default is.
- Anywhere a "view only" state needs surfacing (no edit/upload/convert buttons rendered) for
  users who fail `canManagePolicy()` — mirrors how `$needsOcrReview`/`$hasMarkdown` etc. already
  gate button visibility in `documents/show.blade.php`.

## Docs to update (after implementation, before considering this done)

- `claude.md` — new "Document Taxonomy" section documenting Section/Division/RuleSet(Rules &
  Policy)/Folder side by side (none existed before now), plus the new `canManagePolicy()`
  permission model next to the existing "Scope-Based Upload & Delete Permissions" section.
- `README.md` — feature bullet + changelog entry.
- `ROADMAP.md` — remove/mark-done if this was listed as a future item (check first), or add a
  short note if it's genuinely new scope beyond what's there.
- `summary.md` — new milestone entry (`## M31 — Policy Taxonomy` or next free number) in the
  existing running-log style.
- This file (`POLICY_TAXONOMY_PLAN.md`) can be deleted once the above docs cover everything in
  it — it's a working plan record, not meant to be permanent.

## Verification

- `php artisan migrate` runs clean; existing Rule Set rows default to `kind='rules'`,
  `state`/`policy_type` null — no data migration needed for existing rows.
- Existing `/rules` URLs (e.g. the Odisha/Chandigarh policies currently under Excise's rule set)
  continue to work unchanged.
- Create a new Policy container as an `admin` user under Excise → confirm it's reachable at
  `/departments/dept/excise/policy/{slug}` and `/documents/dept/excise/policy/{slug}`.
- Log in as a plain `operator`/`viewer` (no `department.head` privilege) scoped to Excise →
  confirm no create/upload/edit/convert/OCR/verify/discard controls render for policy documents,
  and a direct POST to any of those routes for a policy document returns 403.
- Log in as a user with `department.head` privilege + `department_id` matching Excise → confirm
  full CRUD + convert/OCR/verify/discard works for Excise's policies, and separately confirm the
  *same* user gets 403 attempting the same actions against a Cane Federation policy.
- Upload flow: confirm the "Policy" option appears in the taxonomy picker for a department that
  has policy sets, and that choosing it defaults the Document Type dropdown to "Policy".

## Open questions worth re-checking before coding starts

- Should `state` default to "Uttar Pradesh" at the DB level, or should it be required/explicit on
  every policy container (forcing the uploader to always pick, rather than silently assuming UP)?
- Is `policy_type` (free-text string) enough, or should it be a constrained enum/dropdown (like
  `Document::DOCUMENT_TYPES`) so policy listings can group/filter reliably without typos
  ("Excise Policy" vs "excise policy" vs "Excise policy" all meaning the same thing)?
- Confirm the four existing departments (Excise, Cane Federation, Sugar Mill Corporation,
  Sugarcane & Sugar Industries) are the complete initial set of departments that need Policy
  enabled — or should it be available to every department by default from day one?

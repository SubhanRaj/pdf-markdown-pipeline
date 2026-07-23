# Policy Periods, Bilingual Documents & Clickable Pills

Shipped 2026-07-23. Three related changes requested together:

1. Split a state's Policy into a **container** (created once) with year-by-year **periods**
   underneath it, instead of re-creating a whole policy row every year.
2. **Bilingual uploads** — a policy document can be uploaded as English only, Hindi only, or both.
3. The `document_type`/state **pills** on a document's show page are now clickable, jumping to an
   exact-filtered search rather than being static labels.

## 1. Policy containers + periods

### The problem

Before this change, a "Policy" was a single `rule_sets` row per `(department_id, state,
policy_type)`. Adding a new year's policy meant re-submitting the full "Add Policy" form (name,
state, policy type, dates) again; the controller detected the existing `current` row for that same
state+type and flipped it to `superseded`. That worked, but state/policy type had to be re-picked
every single year, and the department's Policy index page showed one flat list mixing every state
together with no year/period structure visible.

### The fix — one more self-referencing FK, not a new table

`rule_sets` already had `previous_policy_id` (self-FK, for supersession). We added **one more**,
`container_id` (self-FK, `restrictOnDelete`):

- `container_id IS NULL` → this row **is** a container: state + policy_type, created once via
  `RuleSetController` (`kind=policy`).
- `container_id = <id>` → this row is a **period** (e.g. "2025-26") under that container, created
  via the new `PolicyPeriodController` (`app/Http/Controllers/PolicyPeriodController.php`).

A period is still just a `RuleSet` row — it holds its own root document + amendments exactly like a
Rule Set always has, via the same query/sort/year-filter logic. That logic was extracted from
`RuleSetController::show()` into a trait, `App\Http\Controllers\Concerns\ListsRuleSetDocuments`
(`loadRuleSetDocuments()`), used by both `RuleSetController` (for `kind=rules`) and
`PolicyPeriodController` (for periods). Nothing about `Document`, `documents.rule_set_id`,
slug generation (`uniqueSlugForRuleSet()`), or document URLs changed — a document's `rule_set_id`
always pointed at *some* `RuleSet` row; it's just that row is now a period instead of a
one-and-only container.

**Migration & backfill** (`database/migrations/2026_07_23_162100_add_container_id_to_rule_sets_table.php`):
adds `container_id`, then for every existing `kind=policy` row (grouped by
`department_id`+`state`+`policy_type`), inserts one new container row and points the existing
row(s) at it as their first period. No document ever moved — `documents.rule_set_id` still points at
the exact same row it always did (now a period). Ran against production on 2026-07-23; verified all
13 existing policies backfilled correctly (one container + one period each, documents intact).

### Supersession, now scoped to the container

`PolicyPeriodController::store()` — creating a new period under a container auto-flips the
previous `current` period (scoped by `container_id` instead of the old `department_id`+`state`+
`policy_type` triple) to `superseded`, and sets the new period's `previous_policy_id`. Containers
themselves are never superseded and can't be deleted while periods exist under them
(`container_id`'s `restrictOnDelete` FK).

### Routes

Nested under the existing `/policy` routes:

```
GET    /departments/{level}/{dept}/policy/{policy}/periods/create   departments.policy.periods.create
POST   /departments/{level}/{dept}/policy/{policy}/periods          departments.policy.periods.store
GET    /departments/{level}/{dept}/policy/{policy}/periods/{period} departments.policy.periods.show
GET    .../periods/{period}/edit                                    departments.policy.periods.edit
PATCH  .../periods/{period}                                         departments.policy.periods.update
DELETE .../periods/{period}                                         departments.policy.periods.destroy
```

`{period}` belonging to `{policy}` is checked explicitly in the controller
(`assertBelongsTo()` → 404 if not), rather than relying on Laravel's implicit scoped-binding magic
— kept consistent with this codebase's preference for explicit checks over framework nesting magic.

New FormRequests: `StorePolicyPeriodRequest`/`UpdatePolicyPeriodRequest` — much smaller than
`StoreRuleSetRequest`/`UpdateRuleSetRequest`, since a period only ever needs `name` +
`effective_start_date`/`effective_end_date` (+`requires_approval` on update); state/policy_type are
copied server-side from the container and never re-entered.

### Views

- `rule_sets/index.blade.php` (policy branch) — containers grouped by state, each row showing
  period count + current period's name/doc count.
- `rule_sets/policy_container.blade.php` (new) — a container's page: lists its periods with
  current/superseded badges and doc counts, "Add Period" button.
- `rule_sets/periods/create.blade.php` / `edit.blade.php` (new) — the slim period form.
- `rule_sets/show.blade.php` — unchanged for `kind=rules`; for a period, route helpers
  (`$showRoute`/`$editRoute`/`$destroyRoute`) now point at `departments.policy.periods.*` instead of
  the old `departments.policy.*`.
- `rule_sets/create.blade.php`/`edit.blade.php` (policy branch) — dropped the
  `effective_start_date`/`effective_end_date` fields and their Cleave.js date-mask script (dates now
  live on periods, not containers); relabeled copy from "policy period" to "policy".

## 2. Bilingual documents

`documents` gained two columns (`database/migrations/2026_07_23_162101_add_language_fields_to_documents_table.php`):
- `language` enum (`english` default | `hindi`)
- `sibling_document_id` — nullable self-FK, mirrors the existing `parent_id` pattern.

The upload modals on `rule_sets/show.blade.php` (policy branch only, `@if($isPolicy)`) gained a
Language radio group: **English only / Hindi only / Both**. "Both" is never stored as a `language`
value — it's an upload-time instruction. `DocumentController::store()` handles it by creating **two**
`Document` rows (one per language) instead of one, each with its own physically-copied PDF file, so
later conversion/OCR/discard on one language never touches the other. The two rows are linked via
`sibling_document_id` (set on both, pointing at each other). `Document::siblingDocument()` is the new
relation; `documents/show.blade.php` shows a small "Hindi/English version available →" banner when a
sibling exists.

Applies to any upload context (rule_set_id/section/division/folder), not just policies — the
language field defaults to `english` and is harmless/unused for non-policy uploads.

## 3. Clickable pills → exact search filters

The `document_type` pill (and, for policy documents, a `state` pill) on `documents/show.blade.php`'s
header are now links to `route('search.index', ['document_type' => ...])` /
`['state' => ...]`, instead of static `<span>`s.

`SearchController::index()` gained `document_type`/`state` as **exact** filters (`where()`, not
`LIKE`), independent of and combinable with the existing free-text `q` search — clicking a pill with
no `q` still works (shows all documents of that type/state, no title/name matching needed).
`search/index.blade.php` shows a "Filtered by: ... [clear]" banner when either is present.

This reuses plain URL query parameters rather than AJAX/JSON, consistent with the `?sort=&year=`
convention already used on a rule set/period's show page (`RuleSetController`/
`PolicyPeriodController::show()`) — this codebase already leans on query-string filters for this
exact kind of "filter a list" UI, so no new client-side request pattern was introduced.

## What's deliberately not built (this round)

- No per-state authorization scoping — `canManagePolicy()`/`canManagePolicyForDepartment()` stay
  department-scoped. A department's `department.head` manages every state under their department
  (unchanged from before).
- No language selector on non-policy uploads' UI (rules/GOs/notices) — the DB column supports it,
  but the radio group is only rendered when `$isPolicy`, since that's the only confirmed need.
- No merge/reconciliation UI between an English and Hindi version of the same document — they're
  fully independent documents, linked only by `sibling_document_id` for cross-navigation.

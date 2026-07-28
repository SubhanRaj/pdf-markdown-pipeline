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

### Update (2026-07-25) — wording, date picker, supersession bug, one-step upload

Four follow-up fixes after real usage surfaced problems (see `summary.md` M39 for the full
write-up):

- The container page's "Add Period" button and the period form's "Create Period" button are now
  both **"Add Policy"** — a period is how the department uploads a policy (including old,
  backfilled ones), not a bookkeeping record a user would think to "add" on its own.
- `PolicyPeriodController::store()`'s auto-supersession (§ "Supersession, now scoped to the
  container" above) only promotes the new period to `current` when its `effective_start_date` is
  chronologically on/after the previous current period's — a backfilled older period (e.g. adding
  "2021-22" after "2024-25" is already current) no longer steals `current` status. An edit-form
  "Set as the current policy period" checkbox (`mark_as_current`) covers the case where dates are
  omitted or a manual override is needed.
- `periods/create.blade.php` is no longer just name + dates — it also has an optional file input
  (+ language/visibility) so the period and its root policy document are created in one submit
  instead of two; `PolicyPeriodController@store` creates the `Document` row(s) inline (mirrors
  `DocumentController@store`'s `rule_set_id` branch).
- The Cleave.js masked-text date fields were replaced with **Air Datepicker** (CDN) — a real
  calendar popup locked to `dd-MM-yyyy` display, immune to browser/OS locale (native
  `<input type="date">` was tried in between and dropped for exactly that reason), with built-in
  day → month → year view navigation for jumping to old policy years.

## 2. Bilingual documents

`documents` gained two columns (`database/migrations/2026_07_23_162101_add_language_fields_to_documents_table.php`):
- `language` enum — originally `english` default | `hindi`, widened in §7 to add `both`.
- `sibling_document_id` — nullable self-FK, mirrors the existing `parent_id` pattern. No longer
  auto-populated (see §7) — kept for the existing `documents/show.blade.php` "version available"
  banner, dormant until something sets it again.

The upload modals on `rule_sets/show.blade.php` (policy branch only, `@if($isPolicy)`) have a
Language radio group: **English only / Hindi only / Both**. See §7 for what "Both" means and how
it's gated — this section otherwise still describes the original per-language storage model.

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

## 4. Browsing redesign: UP vs Other States grid (2026-07-26)

The `/policy` index used to be a single flat, state-grouped **list** of every container in the
department — functional, but "technical" (a raw list of container rows) rather than something
that matched how an officer actually thinks about this: "our own state's policy" vs "every other
state's policy," browsed as cards, not scanned as rows.

**No data model change** — `state`/`policy_type`/the container-period split above were already
exactly what this needed. This was a pure view/routing restructure:

- `/policy` is now a 2-card landing page ("Uttar Pradesh Policy" / "Other States' Policy"),
  reusing the exact card markup already established on `department/show.blade.php`'s
  Sections/Rules/Policies cards.
- New `GET /policy/state/{state}` (`departments.policy.state`, `RuleSetController::policyState()`)
  — one page, filtered by state, serving **both** the UP card's destination and every other-state
  card's destination (no UP-specific code path). Shows each of that state's containers (usually
  one; loops if more — Uttar Pradesh itself has two: Excise Policy and Export Policy) with its
  current period featured and previous years in a grid below.
- New `GET /policy/other-states` (`departments.policy.other-states`) — a grid of every
  `RuleSet::STATES` entry except Uttar Pradesh, each showing a current-policy count (0 for states
  with nothing uploaded yet — still clickable, so an admin has somewhere to land before adding
  the first container for that state via the existing, unchanged create flow).
- `{state}` in the URL is a slug (`RuleSet::stateSlug()`/`stateFromSlug()`, new static helpers —
  plain `Str::slug()` + reverse lookup against the `STATES` constant, no new column), not the
  raw name, since state names contain spaces.
- **Route order matters**: `/policy/state/{state}` and `/policy/other-states` are registered
  *before* `/policy/{rule_set}` in `routes/web.php` — otherwise Laravel's route-model-binding
  would try to resolve `other-states` as a policy container slug and 404.
- The old container-show page (`departments.policy.show`, `rule_sets/policy_container.blade.php`)
  is untouched at the route/controller level (still reachable directly, e.g. from Edit flows) —
  only its periods list was extracted into a new shared partial,
  `rule_sets/_policy_periods_grid.blade.php` (current period featured, previous years as a grid),
  which the new state page also includes. One visual source of truth for "periods under a
  container," reused by both the direct container page and the new state page.
- `kind=rules` (Rules & Regulations) is untouched — this redesign is policy-only.

Nothing changed in `PolicyPeriodController`, period create/edit, the document/amendment view, or
container create/edit/store/destroy — this only changed how a user *browses down* to a period.

## 5. Per-state shape icons (2026-07-26, same day follow-up)

The generic map-pin icon on every state card (§4) wasn't distinctive enough — asked for real
per-state icons/shapes. Found `@svg-maps/india` (npm package, CC-BY-4.0, maintained by Victor
Cazanave) — one SVG file with all 36 states/UTs as separate `<path id aria-label>` elements,
accurate boundaries. Explicitly **not** installed via npm (this app has no Node/build step and
that's staying true) — loaded client-side via a pinned-version jsDelivr CDN URL instead, same
pattern already used for Alpine.js/Grid.js/Air Datepicker.

**How it works** (`resources/views/rule_sets/_state_icon_loader.blade.php`): one `fetch()` of the
map SVG, parsed client-side, then for every `[data-state-icon="State Name"]` element on the page
(or `data-state-icon="__all__"` for the landing page's whole-India icon) — find the matching
`<path>` by its `aria-label`, clone it into a temporary off-screen `<svg>`, call `getBBox()` to
get its real bounding box, and build a small cropped `<svg viewBox="...">` from that box. Purely
decorative and non-blocking: the `ti-map-pin` fallback icon already in the markup is left alone
if the fetch fails (flaky office network) or a state has no matching path — unlike the app's
global icon font (see claude.md's Tabler subsetting note), a missing icon here never breaks
anything else on the page.

**CSP had to be extended.** `connect-src 'self'` blocked the `fetch()` outright (script-src/
style-src/font-src already trusted jsDelivr, but connect-src didn't) — silently failed with no
visible error until checked via a real headless-browser console capture. Added
`https://cdn.jsdelivr.net` to `connect-src` in `SecurityHeaders.php` — same origin already
trusted for three other directives, just extended to `fetch()`.

**Two boundary mismatches, handled gracefully, not bugs:** the map's data predates two changes
`RuleSet::STATES` already reflects — Ladakh (split from Jammu and Kashmir, 2019) has no path at
all in the source data, so it always shows the plain fallback icon. "Dadra and Nagar Haveli and
Daman and Diu" (the two UTs merged in 2020) is reconstructed by combining the map's two separate
old-UT paths into one icon.

**Investigated as a possible bug, turned out to be correct:** Lakshadweep and Puducherry's icons
initially looked like they'd fallen back to the plain pin too. Traced with a live headless-Chrome
console capture (confirmed via `google-chrome --headless --dump-dom` — no CDP/Puppeteer install
needed, Node 24's build already covers what was needed) all the way through matching → bbox →
DOM injection, and the real `<path>` data genuinely was there in the final DOM. Both territories
are real archipelagos/scattered enclaves (Lakshadweep = coral atolls; Puducherry = several
non-contiguous enclaves across South India) — their *accurate* shape is a small cluster of dots,
which just visually resembles the pin icon at small size. Correct behavior, not a defect.

## 6. States vs Union Territories, split into two sections (2026-07-26, same day)

The "Other States" grid (§4) listed all 35 non-UP entries in one flat grid. Split into two
labeled sections — "States" (27) and "Union Territories" (8) — since `RuleSet::STATES` already
listed them in exactly that order (28 states, then 8 UTs) but with no way to distinguish them
programmatically. Added `RuleSet::UNION_TERRITORIES` (the 8 UT names, explicit list rather than
relying on array position — safer if `STATES`'s order ever changes) and split
`RuleSetController::policyOtherStates()`'s single collection into `$states`/`$unionTerritories`.
Extracted the card markup into `rule_sets/_state_card.blade.php` so both sections render from
one source instead of duplicating the card markup twice.

## 7. Breadcrumb consistency across every policy page (2026-07-26, same day)

Each policy page built its `<x-breadcrumb>` items array independently, hand-written per view.
After §2–§6 added several new pages (state page, other-states page, the shared periods grid),
these had drifted into three different, sometimes-broken chains reported live: one page correctly
showed `Policies > Uttar Pradesh`, another skipped straight from the department to the container
name with no `Policies`/state hop at all, and the document page showed the period name twice in a
row (`Excise Policy Uttar Pradesh 2025-26 > Excise Policy Uttar Pradesh 2025-26`) because it
appended the document title after the context name without checking whether they were the same
string — true for every seeded policy document, whose title always equals its period's name.

**Fix — one shared prefix, reused everywhere.** Added `RuleSet::policyBreadcrumb(Department
$department, string $state): array`, returning the `Policies > {state}` pair (`Policies` links to
`departments.policy.index`, `{state}` links to `departments.policy.state` via `stateSlug()`).
Every policy view now spreads this into its breadcrumb array instead of re-deriving its own
partial chain:

- `rule_sets/policy_container.blade.php` (container show)
- `rule_sets/create.blade.php` / `edit.blade.php` (container create/edit — `edit` only, since
  `create` doesn't have a state yet; both also gained the missing `Policies`/`Rules & Regulations`
  index hop that neither had before, for parity with `rules`)
- `rule_sets/periods/create.blade.php` / `edit.blade.php`
- `rule_sets/show.blade.php` (a period's own document-list page, reached via
  `PolicyPeriodController::show()`)
- `documents/show.blade.php` (the actual document page)

The full chain everywhere is now:
`Home > Departments > {level} > {department} > Policies > {state} > {container name} > {period name} > {document title, only if different}`.

**A second, unrelated bug was found and fixed along the way.** `documents/show.blade.php`'s
"context" link for a policy document used `route('departments.policy.show', [..., $ruleSet])` —
correct for a `rules`-kind document, but wrong for policy: `$ruleSet` there is the *period*
(`container_id` set), not a container, and `departments.policy.show` renders
`policy_container.blade.php`, which calls `$ruleSet->periods()` — empty for a period object, since
only containers hold periods. The link silently led to an empty-looking page. Fixed to route to
`departments.policy.periods.show` (with `$ruleSet->container` as the parent) whenever the rule set
is a period, leaving the `rules`-kind and container-document cases untouched.

**Duplicate trailing crumb fix:** the context crumb and the document-title crumb are now compared
before both are added — if `$document->title === $contextName` (true for every root policy
document today), only one crumb is rendered, as plain (non-link) text.

## 8. Three live bugs fixed + "period" renamed to "policy document" throughout (2026-07-26)

### Bug: viewing an old year showed the wrong "current" policy

`RuleSet::supersededBy()` (`hasOne(RuleSet::class, 'previous_policy_id')`) only walks **one hop**
of the `previous_policy_id` chain — it returns whoever superseded *this specific* row, not the
container's actual current one. Viewing 2021-22 showed "Current period: 2022-23" (its immediate
successor) instead of the real current year (e.g. 2026-27), because nothing walked the chain to
the end. Fixed in `ListsRuleSetDocuments::loadRuleSetDocuments()` — instead of
`$ruleSet->supersededBy`, it now looks up the container's current row directly:
`RuleSet::currentPolicy()->where('container_id', $ruleSet->container_id)->first()`. One query,
no chain-walking, always correct regardless of how many superseded years sit in between.

### Bug: `edit.blade.php` 500'd with a Blade/PHP parse error

Four spots in `rule_sets/edit.blade.php` had stray backslash-escaped quotes inside `{{ }}` —
`route(\"departments.{$ruleSet->kind}.show\", ...)` — left over from an edit that generated the
string as if it were already inside an outer PHP string literal. `\"` is not valid syntax on its
own inside a Blade echo, and threw `ParseError: syntax error, unexpected token "\"` on the compiled
view, 500-ing the whole edit page. Fixed by removing the stray backslashes; `php artisan
view:clear` needed afterward since the broken compiled view was cached.

### Bug: delete-container guard text printed a stray number

`{{ $periodsCount = $ruleSet->periods()->count() }}` (now `{{ $policyDocsCount = ... }}` before the
rename) is a Blade echo, and `{{ }}` always prints its expression's result — including a plain PHP
assignment's value. So the line silently rendered "6" on the page before the "Cannot be deleted…"
sentence even started, producing "6 Cannot be deleted while it still has 6 policies…". Fixed by
switching to `@php($policyDocsCount = ...)`, which assigns without echoing.

### Terminology fix: "period" no longer names the entity, only its timeframe

Everywhere above (§1–§7), the yearly `RuleSet` row (e.g. "Excise Policy 2025-26") was called "a
period" — in class names, variables, comments, log keys, and validation/flash messages. That
was backwards: for this department, "policy" is the record itself (a specific dated document,
e.g. "Excise Policy 2026-27"); "period" should only ever describe its **timeframe** ("2026-27"),
never be the name of the entity. Renamed throughout, in one pass:

| Old | New |
|---|---|
| `PolicyPeriodController` | `PolicyDocumentController` |
| `StorePolicyPeriodRequest` / `UpdatePolicyPeriodRequest` | `StorePolicyDocumentRequest` / `UpdatePolicyDocumentRequest` |
| `SeedUpExcisePolicyPeriods` | `SeedUpExcisePolicyDocuments` (signature: `policies:seed-up-policy-documents`) |
| `RuleSet::periods()` relation | `RuleSet::policyDocuments()` |
| `rule_sets/periods/{create,edit}.blade.php` | `rule_sets/policy_documents/{create,edit}.blade.php` |
| `rule_sets/_policy_periods_grid.blade.php` | `rule_sets/_policy_documents_grid.blade.php` |
| `$period`/`$newPeriod`/`$currentPeriod`/`$previousPeriods`/`$periodsCount` | `$policyDoc`/`$newPolicyDoc`/`$currentPolicyDoc`/`$previousPolicyDocs`/`$policyDocsCount` |
| Log context `period_id` | `policy_document_id` |
| UI copy: "Period Details", "Add Period", "Current period", "Delete Period?", "N periods" | "Policy Details", "Add Policy", "Current policy", "Delete Policy?", "N policies" |

**Deliberately left unchanged** (confirmed with the user before doing the rename, given this is a
production deployment with real users on `docsrepo.exciseup.in`):

- **The `/periods/` URL path segment and the `policy.periods.*` route names.** Changing either
  breaks every bookmarked/shared link to an existing policy document's page. "Periods" is a
  defensible name for this route group regardless of the entity-naming fix — it's naming the
  timeframe-scoped sub-resource path, not asserting the row itself is "a period."
- **`ActivityLogController::ACTION_LABELS`** for the `policy.periods.*` routes now say "Create/
  Update/Delete Policy Document" (not just "Policy") — deliberately more specific than the rest of
  the UI, since the container's own routes already claim "Create/Update/Delete Policy" and an audit
  trail needs the two distinguishable.
- **`database/migrations/2026_07_23_162100_add_container_id_to_rule_sets_table.php`** — already run
  against production; its docblock still says "period," left as a historical record rather than
  edited after the fact.

## 9. Bilingual upload gating fix + "Both" becomes a real third document (2026-07-27)

**Bug found:** the "Upload Document" modal on `rule_sets/show.blade.php` blocked the root `policy`
document type the moment **either** language had been uploaded — the label flipped to "already
uploaded" and the option disabled, even if only Hindi existed and the officer wanted to add the
English copy of the same policy document. Root cause was `$hasRuleDoc` checking only
`document_type`, blind to the `language` column added in §2 — a front-end-only bug; confirmed
nothing in `StoreDocumentRequest`/`DocumentController::store()` enforced one-root-document-per-
`rule_set_id` server-side (no `unique` rule, no duplicate guard before `Document::create()`).

**Along the way, "Both" was redefined.** Originally (§2) "Both" was upload-time shorthand that
silently expanded into *two* `Document` rows (english + hindi) from one submitted file — never
itself a stored `language` value. Clarified with the user that this doesn't match the real need:
a bilingual PDF (one file, both languages together) is its own **third, independent document**,
meant to coexist alongside separately-uploaded English-only and Hindi-only versions of the same
policy — not a shorthand that creates the other two.

- **`database/migrations/2026_07_27_154629_add_both_to_documents_language_enum.php`** — widens
  `documents.language` from `enum('english','hindi')` to `enum('english','hindi','both')` via raw
  `ALTER TABLE ... MODIFY COLUMN` (no Doctrine/DBAL dependency needed). Run against production.
- `Document::LANGUAGES` gained `'both' => 'Bilingual (English + Hindi)'`.
- `DocumentController::store()` and `PolicyDocumentController::storePolicyDocument()` simplified:
  each submission now creates exactly **one** `Document` row, with `language` stored as whatever
  the form sent (`english`/`hindi`/`both`) — the old per-submission loop that duplicated the file
  into two rows and cross-linked them via `sibling_document_id` is gone. `sibling_document_id`
  itself is untouched (still a column, still a relation, still renders the "version available"
  banner on `documents/show.blade.php` if set) — it just no longer auto-populates for new
  uploads, since there's no longer a paired creation event to link. Dormant, not removed.
- Gating in `rule_sets/show.blade.php`, scoped to `$isPolicy` only (rules/GOs keep the original
  one-and-done gating): `$hasEnglishDoc`/`$hasHindiDoc`/`$hasBothDoc` are each computed
  independently from real root-document rows per language value. The "Policy" document-type
  option in the upload dropdown only disables once **all three** exist. In the Language radio
  group, each of the three options disables independently the moment its own language already has
  a row (labeled "already uploaded") — uploading English doesn't touch whether Hindi or Both are
  still available, and vice versa — and the default-checked radio picks the first still-open one,
  in English → Hindi → Both order.

Same language-blind `hasRuleDoc` pattern also exists in `DocumentController`'s bulk-upload JSON
builder (~line 300, feeds `documents/bulk-upload.blade.php`) — left as-is, since it only drives an
advisory hint sentence there ("should typically use Amendment"), never disables or blocks
anything, so the inaccuracy has no functional effect.

## 10. Language badge + language upload extended to Rules documents (2026-07-27, same day)

§9's Language radio group (English only / Hindi only / Both) and its per-language gating
(`$hasEnglishDoc`/`$hasHindiDoc`/`$hasBothDoc`/`$canUploadRule`) were scoped to `@if($isPolicy)` —
rules/GOs could only ever be uploaded as a single, implicit `english` row, with the root
document-type option locked out after the very first upload regardless of language. Rules
documents can be genuinely bilingual too (e.g. a Hindi-only GO with an English translation added
later), so this was an artificial restriction, not a real one-rule-per-set constraint — removed
the `$isPolicy` guard on both the gating computation and the modal's Language radio group; rules
now get the exact same English/Hindi/Both flow as policy documents.

A small language badge (`ti-language` icon, color-coded: sky = English, orange = Hindi, violet =
Both) was added next to the existing document-type badge in two shared places so the language is
visible at a glance, for both policy and rules documents:
- `rule_sets/_doc_row.blade.php` — the document-list row partial shared by both `rules` and
  `policy` kinds.
- `documents/show.blade.php` — the single-document page's pill row (plain `<span>`, not a link —
  unlike the `document_type`/`state` pills next to it, there's no `language` search filter to
  link to).

## 11. Whole document row clickable + upload size wording corrected (2026-07-28)

`rule_sets/_doc_row.blade.php` only made the small eye icon clickable to open a document — every
other page in the app (department cards, state cards, etc.) makes the whole card clickable, so
this row was an inconsistent outlier. Added a "stretched link" (`<a class="absolute inset-0
z-0">` covering the row, title/icon/badges included) so clicking anywhere on the row opens the
document, while the action buttons (eye, delete) sit in a `relative z-10` wrapper above it and
stay independently clickable — no route or behavior change, just click-target size.

Separately, the upload forms' helper text ("PDF · Word · Excel · Images · max 50 MB each") was
stale — the actual validation limit (`StoreDocumentRequest`/`StorePolicyDocumentRequest`,
`max:307200`) has been 300 MB for a while. Corrected the text to "max 300 MB each" across all six
upload forms (`rule_sets/show.blade.php` ×2, `documents/bulk-upload.blade.php`,
`sections/show.blade.php`, `folders/show.blade.php`, `divisions/show.blade.php`) so it matches
what the server actually accepts. (The 300 MB app-level limit is separate from — and smaller
than — the zone's Cloudflare edge cap; see DEPLOY.md's "Cloudflare's own edge upload cap" section
for the full upload-path story, including the 100 MB/200 MB Free/Business ceiling that sits in
front of Apache entirely.)

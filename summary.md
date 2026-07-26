# Project Summary

Running log of major milestones and architectural decisions. Minor tweaks are not recorded here — check git history for those.

---

## Environment notes

**Web server:** Apache only — no Nginx. Apache has no `client_max_body_size` directive; upload size is controlled entirely by PHP ini.

**Frontend toolchain:** Tailwind CSS via Play CDN, Tabler Icons via jsDelivr, Chart.js via jsDelivr. No Node, no npm, no build step — all JS/CSS loaded from CDN at runtime.

**PHP upload limit fix (applied 2026-06-22):**

Root cause: 13 MB PDF upload returned HTTP 413 before reaching Laravel — PHP rejected the request body at the SAPI level before `StoreDocumentRequest` was ever evaluated. Default `upload_max_filesize = 2M`, `post_max_size = 8M`.

Three options documented (see README ⚙️ PHP Configuration Requirements):

1. **`public/.htaccess`** — `php_value` directives inside `<IfModule mod_php.c>`. Already in repo. Works immediately for Apache + mod_php, no restart. Requires `AllowOverride All` in the vhost.
2. **`public/.user.ini`** — flat ini file, works for mod_php and php-fpm, ~5 min TTL.
3. **System `php.ini`** — global fix for dedicated servers, requires Apache/fpm restart.

Values set across all three on local dev machine:

| Directive | Default | Set to |
|---|---|---|
| `upload_max_filesize` | `2M` | `64M` |
| `post_max_size` | `8M` | `64M` |
| `max_execution_time` | `30` | `120` |
| `max_input_time` | `60` | `120` |

Apache on this machine runs on port 80. `php_value` in `.htaccess` is authoritative for mod_php and overrides php.ini per-request. Verified via probe: Apache reports `64M / 64M` live.

---

## M1 — Project Initialization
Laravel 13 skeleton initialized. Architecture, vault structure, and tech stack documented in `CLAUDE.md` and `README.md`. Core decisions locked in: MariaDB, database queue driver, no Redis, no cloud APIs, single local filesystem disk, path-convention vault silos.

Key packages present at init: `subhanraj/laravel-db-provisioner`, `symfony/process` (transitive), Tailwind CSS v4.

---

## M2 — Domain Models, Schema & Routes

**Models created** (all via `make:model --all` except `DocumentStatusHistory`):
- `Department` — name, slug, level (`secretariat_level` | `department_level`). Unique on `(slug, level)` so the same department body can exist at both bureaucratic levels.
- `Section` — belongs to Department, nullable `wing` (e.g. `headquarter`, `joint_secretary_wing`), name, slug. Unique on `(department_id, wing, slug)`.
- `Document` — belongs to Department, Section, and User (nullable until auth lands). Tracks `original_pdf_path`, `markdown_path`, `vault_path`, `status`, and a JSON `metadata` column for evolving fields (GO number, subject, dates, language, etc.).
- `DocumentStatusHistory` — append-only audit log (`created_at` only, no `updated_at`, no soft deletes). Records every status transition with `from_status`, `to_status`, nullable actor.

**Traits on all models except `DocumentStatusHistory`:** `SoftDeletes`, `HasFactory`. `DocumentStatusHistory` intentionally excluded from soft deletes — deleting audit rows defeats the audit trail.

**Routes** — initially under `/vault` prefix, later removed (see M3). Sections nested under departments (`/departments/{department}/sections/{section}`). Documents flat under `/documents/` with semantic URL aliases (`/upload` for create, `/review` for edit).

---

## M3 — Controllers, Views & Route Refactor

**Controllers scaffolded** with full CRUD (index/show/create/store/edit/update/destroy):
- `DocumentController` — document lifecycle, vault access
- `DepartmentController` — department management with slug validation
- `SectionController` — section management, wing-aware, nested under department
- `Admin\UserManagementController` — admin-only; account creation, role toggle, self-delete guard

All mutations protected by `middleware('auth')`. Admin routes additionally gated by `isAdmin()` check in Form Request `authorize()`.

**Form Request classes** created for every POST/PATCH endpoint. All include `prepareForValidation()` for sanitisation (`strip_tags`, `trim`, slug normalisation). Frontend JS validation mirrors server-side rules (real-time on `blur`/`input`, submission gated, scrolls to first error).

**Blade views** built for all CRUD actions across Departments, Sections, Documents, and Admin Users. All views use the `<x-layout>` anonymous component — no `@extends` inheritance anywhere.

**Route refactor** — `vault` URL prefix and `vault.` name prefix removed entirely. Resources now sit at the root (`/documents`, `/departments`, `/departments/{department}/sections`). Route names: `documents.index`, `departments.sections.show`, `admin.users.create`, etc. Public read-only routes and auth-protected mutations are separate groups; public routes carry no middleware.

---

## M4 — Document Upload UI & File Browser

**Schema additions** — `title` (string) and `document_type` (string enum: `go | policy | notice | court_order | service_code | other`) added directly to the `documents` migration. Both promoted to real columns (not metadata) as they are structurally stable and used for display/filtering.

**`Document` model** — `DOCUMENT_TYPES` and `STATUSES` constants added; both used across views and Form Requests without duplication.

**`StoreDocumentRequest`** — full validation: `section_id` (exists check), `title` (regex-guarded, strip_tags in prepareForValidation), `document_type` (in-list against constant keys), `file` (mimetypes: magic-byte checked, max 50 MB). Server messages mapped per field.

**`DocumentController@store`** — vault directory resolved from department level + slug + section wing + section slug via `array_filter(implode(...))`. PDF stored to `local` disk before the DB transaction (file I/O is not transactional). On DB failure, uploaded file is deleted as best-effort cleanup. Status history row written inside same transaction.

**`SectionController@show`** — paginates documents (20/page, `withQueryString`). Public guests see only `status = verified` docs; authenticated users see all statuses.

**Section show page as dual-purpose hub** — public file browser and authenticated upload point in one view.

---

## M5 — Security Hardening: Rate Limiting & File Upload

**Rate limiting (`AppServiceProvider` + `routes/web.php`)**

Four named limiters defined in `AppServiceProvider::boot()` via `RateLimiter::for(...)`:

| Limiter | Limit | Key | Applied to |
|---|---|---|---|
| `login` | 5/min per email+IP AND 10/min per IP | email+IP / IP | Fortify login route |
| `two-factor` | 5/min | session login.id + IP | Fortify 2FA route |
| `mutations` | 60/min | user ID or IP | All auth-protected POST/PATCH/DELETE route groups |
| `uploads` | 20/min | user ID or IP | `POST /documents` only — applied on top of `mutations` |

**Strict file upload validation (`StoreDocumentRequest`)**

Replaced `mimes:pdf` with `mimetypes:` (magic-byte check via PHP Fileinfo). Accepted MIME types defined as `ACCEPTED_MIMETYPES` public constant:
- **Documents:** `application/pdf`, `.doc`/`.docx`, `.xls`/`.xlsx`, `.ppt`/`.pptx`, `.odt`/`.ods`/`.odp`, `application/rtf`, `text/plain`, `text/csv`
- **Images:** `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `image/tiff`, `image/bmp`, `image/heic`, `image/heif` — SVG (`image/svg+xml`) is permanently excluded; see M24.

---

## M6 — Dashboard Auth-Aware Feed & Department Links

- Recent-documents query applies conditional scope: guests see `verified` only; authenticated users see all statuses.
- Dashboard department cards now resolve `departments.show` from the already-loaded collection — no placeholder `href="#"` links.
- Recent-document rows show `$doc->title` instead of `$doc->original_filename`.
- Empty-state CTA updated to "Browse Departments" → `departments.index`.

---

## M7 — Slug-Based URLs, Section Module, Document Views & Upload Fix

### Slug-based routing (all models)

`Department`, `Section`, and `Document` override `getRouteKeyName()` to return `'slug'`. IDs no longer appear in any public URL.

### Route ordering — static before wildcard

Static path segments (e.g. `/create`) that sit beside a `/{wildcard}` route must be registered before the wildcard or Laravel matches the string `"create"` as a slug and 404s. Applied to `/departments/create` and `/departments/{department}/sections/create`.

### Document slug column

`slug` column added to `documents` table. Unique on `(section_id, slug)`. `Document::uniqueSlugForSection($title, $sectionId)` queries `withTrashed()` to avoid reusing soft-deleted slugs, appends `-2`/`-3` on collision.

### Hierarchical document URLs

`/documents/{department}/{section}/{document}` — all three segments are slug-bound.

### Document views

- **`documents/show`** — hierarchical breadcrumb, inline PDF embed (iframe, 75vh) via controller-streamed route, extracted Markdown rendered below once available, metadata + status history sidebar (auth only), admin Review/Delete.
- **`documents/index`** — tabbed by department with count badges.

### Upload AJAX fix

`POST /documents` is AJAX-only, always returns JSON. `StoreDocumentRequest::failedValidation()` overridden to throw `HttpResponseException` with 422 JSON. File input outside the `<form>` element appended explicitly via `formData.append('file', ...)`.

---

## M8 — Dynamic Sidebar Browse Vault

`sidebar.blade.php` now queries all `Department` records and renders each as a `departments.show` link. A `$deptMeta` map (slug → icon + color) provides distinct icons for known departments; unknown slugs fall back to a cycling palette. Active-link highlight checks `routeIs()` and the current `{department}` slug parameter. Slug keys use underscores to match DB slugs.

---

## M9 — Storage Consolidation: Vault-First, Public Disk, Slug-Named Files

Eliminated the `uploads/{uuid}/original.pdf` staging pattern. All document files go directly into the vault directory on the `public` disk.

**New file path convention:**
```
storage/app/public/document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/{slug}_{YmdHis}.pdf
storage/app/public/document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/{slug}_{YmdHis}.md
```

Key changes: disk `local` → `public`, filename `original.pdf` → `{slug}_{YmdHis}.pdf`, `original_pdf_path` is now the full relative vault path, PDF streaming updated to `Storage::disk('public')`.

---

## M10 — Level-Aware Department Routing

**Problem**: `departments` unique on `(slug, level)` — the same slug (e.g. `excise`) exists at both levels. Old binding queried by slug alone and always resolved `department_level` first.

**Fix**: Added `{level}` URL alias (`dept` = `department_level`, `sectt` = `secretariat_level`) before `{department}` in every department, section, and document route.

`Route::bind('department', ...)` in `AppServiceProvider::configureRouteBindings()` maps alias → DB level value and queries `WHERE slug = ? AND level = ?`. Controller methods must declare `string $level` as the first parameter before model arguments.

- `Department::levelAlias()` → `'dept'` or `'sectt'` — used in all `route()` helpers.
- `Department::levelLabel()` → `'Department'` or `'Secretariat'` — used in breadcrumbs.
- All route helpers updated to `route('...', [$dept->levelAlias(), $dept])` pattern.

---

## M11 — Rule Sets (Acts, Rules & Amendments)

Introduced a second document taxonomy alongside section-based documents: **Rule Sets** group Acts, Rules, and their amendments at the department level.

### New database table: `rule_sets`

| Column | Notes |
|---|---|
| `department_id` | FK → departments, `restrictOnDelete` |
| `name` | Full name of the Act or Rule (e.g. *U.P. Excise Act 1910*) |
| `slug` | Auto-generated from name; unique per department (checked against soft-deleted records) |
| `description` | Optional summary, max 500 chars |
| `metadata` | JSON — for category, origin year, etc. |
| timestamps + softDeletes | |

Unique constraint: `(department_id, slug)`.

### `documents` table changes

- `section_id` made **nullable** — null for rule-set documents.
- `rule_set_id` added — nullable FK → `rule_sets`, `nullOnDelete`.
- New document type constant: `rule_amendment`.
- New slug helper: `Document::uniqueSlugForRuleSet($title, $ruleSetId)`.

### New model: `RuleSet`

- `getRouteKeyName()` returns `'slug'`.
- `uniqueSlugForDepartment(name, departmentId, exceptId?)` — generates collision-safe slugs, checks `withTrashed()`.
- Relations: `belongsTo(Department)`, `hasMany(Document)`.

### Model updates

- `Department` — added `ruleSets()` hasMany relation.
- `Document` — added `ruleSet()` belongsTo relation, `rule_set_id` to `$fillable`, `rule_amendment` to `DOCUMENT_TYPES`.

### New controller: `RuleSetController`

Full CRUD — `create`, `store`, `show`, `edit`, `update`, `destroy`. All mutations wrap in `DB::transaction()` with try/catch and flash messages. Admin-only via `StoreRuleSetRequest::authorize()`.

### DocumentController updates

`store()` now handles both contexts:
- If `rule_set_id` provided: resolves `RuleSet`, computes vault path as `document_vault/{level}/{dept_slug}/rules/{rule_set_slug}/`, calls `uniqueSlugForRuleSet()`.
- If `section_id` provided: existing path unchanged.
- Redirect target switches accordingly: `departments.rules.show` vs `departments.sections.show`.

Five new methods added for rule-set document lifecycle: `showRuleSetDoc`, `pdfRuleSetDoc`, `editRuleSetDoc`, `updateRuleSetDoc`, `destroyRuleSetDoc`.

### Route binding

`Route::bind('rule_set', ...)` in `AppServiceProvider::configureRouteBindings()` scopes lookups to `WHERE slug = ? AND department_id = ?` using the already-resolved `{department}` from the same request.

### New routes

**Public:**
```
GET /departments/{level}/{department}/rules/create     → departments.rules.create
GET /departments/{level}/{department}/rules/{rule_set} → departments.rules.show
GET /documents/{level}/{department}/rules/{rule_set}/{document}     → documents.rules.show
GET /documents/{level}/{department}/rules/{rule_set}/{document}/pdf → documents.rules.pdf
```

**Auth-protected:**
```
POST   /departments/{level}/{department}/rules                             → departments.rules.store
GET    /departments/{level}/{department}/rules/{rule_set}/edit             → departments.rules.edit
PATCH  /departments/{level}/{department}/rules/{rule_set}                  → departments.rules.update
DELETE /departments/{level}/{department}/rules/{rule_set}                  → departments.rules.destroy
GET    /documents/{level}/{department}/rules/{rule_set}/{document}/review  → documents.rules.edit
PATCH  /documents/{level}/{department}/rules/{rule_set}/{document}         → documents.rules.update
DELETE /documents/{level}/{department}/rules/{rule_set}/{document}         → documents.rules.destroy
```

### New views

- `rule_sets/create.blade.php` — name + description form with JS validation; slug auto-generated server-side.
- `rule_sets/edit.blade.php` — same, pre-populated; slug read-only (set at creation, never changed).
- `rule_sets/show.blade.php` — header with description, upload amendment modal (pre-selects `rule_amendment` type, passes `rule_set_id`), amendment/document timeline list with status badges and auth-gated actions.

### Updated views

- `department/show.blade.php` — "Rules & Regulations" panel added below the Sections panel; lists rule sets with document count, description, and link to rule set show page. Admin sees "Add Rule Set" button.
- `documents/show.blade.php` — refactored to support both section and rule-set contexts. A `$isRuleSetDoc` flag (set when `$ruleSet` is passed) switches breadcrumbs, page subtitle, vault path display, and all route helpers (PDF, edit, destroy) without duplicating the template. The sidebar "Section/Rule Set" label also adapts dynamically.

### Vault path for rule-set documents

```
storage/app/public/document_vault/{level}/{dept_slug}/rules/{rule_set_slug}/{slug}_{YmdHis}.pdf
```

### Form requests

- `StoreRuleSetRequest` — name (regex-guarded, max 150 chars), description (nullable, max 500). `authorize()` requires `isAdmin()`.
- `UpdateRuleSetRequest` — same rules.
- `StoreDocumentRequest` — `section_id` and `rule_set_id` are now mutually required-without each other (`required_without:`). One must be provided; both at once is a logical error caught server-side.

---

## M12 — Global Search

Added public LIKE-based search across all three taxonomy types.

### New controller: `SearchController`

`GET /search?q=` → `SearchController@index`. Public route, no auth middleware. Empty `q` renders the prompt state; authenticated users see all document statuses; guests see only `status = verified` documents.

**Query strategy:**
- Documents: `title LIKE %q%` OR `whereHas('section', name LIKE)` OR `whereHas('ruleSet', name LIKE)`. Ordered by direct title match first (`CASE WHEN orderByRaw`), then `created_at DESC`. Capped at 50.
- Sections: `name LIKE %q%`, ordered by name. Capped at 20.
- Rule sets: `name LIKE %q%` OR `description LIKE %q%`, ordered by name. Capped at 20.

### Route

```
GET /search  →  search.index  →  SearchController@index  (public)
```

### Header integration

Existing search input in `header.blade.php` wrapped in `<form method="GET" action="{{ route('search.index') }}">`. Added `name="q"` and `value="{{ request('q') }}"` so the field stays populated on the results page.

### Sidebar

Search nav link (`ti-search` icon) added between All Documents and Browse Vault. Active on `routeIs('search.*')`. Collapses to icon with tooltip like all other nav links.

### New view: `search/index.blade.php`

Three states: empty prompt (no `q`), no-results, results.

Results layout:
1. Large search bar (autofocused, × clear button)
2. Summary strip — total result count, query term, guest disclaimer
3. Indigo callout — explains cross-taxonomy surfacing when sections/rule sets are matched
4. **Documents block** — reuses `documents/index` row design (status icon, title, dept · section/rule set · type badge · status badge · date; hover eye link)
5. **Sections block** — sky-accented rows; shows wing; links to `departments.sections.show`
6. **Rule Sets block** — violet-accented rows; shows description excerpt; links to `departments.rules.show`

---

## M13 — Two-Stage Document Deletion, Trash View & SweetAlert2

Replaced the native `window.confirm()` one-step delete pattern with a two-stage soft-delete → permanent-delete workflow backed by an audit trail.

### Delete with reason

- Delete button on `documents/show` triggers a SweetAlert2 modal requesting a mandatory deletion reason (5–500 chars).
- `DeleteDocumentRequest` validates the reason (`required|string|min:5|max:500`); `authorize()` enforces admin-only.
- Inside `DB::transaction`: inserts a `DocumentStatusHistory` row (`to_status = 'deleted'`, `note` = reason, `actor_id` = current user) **before** calling `$document->delete()` (soft-delete).
- Both `DocumentController@destroy` (section docs) and `@destroyRuleSetDoc` (rule-set docs) updated to accept `DeleteDocumentRequest`.

### Trash view (`GET /documents/trash` → `documents.trash`)

- Auth-only. Queries `Document::onlyTrashed()`, eager-loads `statusHistory` filtered to `to_status = 'deleted'` with the actor relationship.
- Each row: title, department, section/rule-set context, document type, deletion reason and timestamp, actor name.
- New view: `resources/views/documents/trash.blade.php`.

### Restore (`POST /documents/trash/{id}/restore` → `documents.restore`)

- Auth-only. Resolves via `Document::withTrashed()->findOrFail($id)` (numeric ID — slug binding doesn't cover soft-deleted records).
- Calls `$document->restore()`, then logs a history entry (`from_status = 'deleted'`, `to_status` = the pre-existing status column value, note = 'Restored from trash.').
- SweetAlert2 confirmation before submit.

### Permanent delete (`DELETE /documents/trash/{id}` → `documents.force-destroy`)

- Admin-only (controller-level `isAdmin()` gate). Resolves via `withTrashed()->findOrFail()`.
- Inside transaction: `Storage::disk('public')->delete($original_pdf_path)` and `->delete($markdown_path)` if set, then `$document->forceDelete()`.
- `document_status_histories` cascade-delete automatically.
- SweetAlert2 confirmation with red confirm button and explicit "cannot be undone" text.

### Sidebar update

- "Trash" nav link (`ti-trash` icon) added between Search and Browse Vault — visible to all authenticated users.
- "All Documents" active-state check updated to exclude `documents.trash`, `documents.restore`, `documents.force-destroy` so it doesn't highlight incorrectly when on the trash page.

### SweetAlert2 (`sweetalert2@11`)

Added to `head.blade.php` via jsDelivr — available globally on all pages. All destructive confirmations migrated from `window.confirm()` / `onsubmit` checks to `Swal.fire()`. Dark mode respected via `document.documentElement.classList.contains('dark')`. All JS init blocks wrapped in `try/catch`.

---

## M14 — Document Visibility Control (Public vs Authenticated-Only)

Decoupled access control from the processing-status workflow. The old pattern of `status = 'verified'` as the guest gate was a proxy and blocked public access to legitimately uploaded documents that hadn't been reviewed yet. Visibility is now an explicit, independent field.

### Design decision

The processing status (`uploaded → processing → ocr_pending → review → verified | failed`) tracks the **document conversion pipeline**. It has nothing to do with who should be able to read the document. A policy GO uploaded today should be publicly accessible immediately if the uploader marks it so — it doesn't need to go through OCR and human review before citizens can download the PDF.

Conversely, departmental proceedings, dandaadesh, trial documents, and internal circulars should only be visible to logged-in departmental users — regardless of their processing status.

### New column: `documents.visibility`

| Value | Meaning |
|---|---|
| `public` (default) | Visible to all visitors, including unauthenticated guests |
| `authenticated` | Restricted to logged-in users only |

Migration: `2026_06_22_065143_add_visibility_to_documents_table.php` — adds `string visibility default 'public'`. All existing rows default to `public`.

### Changes across the codebase

**Model** — `Document::VISIBILITY` constant added; `'visibility'` added to `$fillable`.

**`StoreDocumentRequest`** — `visibility` field added: `nullable|in:public,authenticated`, defaults to `'public'` in `prepareForValidation()`.

**`DocumentController@store`** — passes `$validated['visibility']` to `Document::create()`.

**Guest gate replaced in all five locations:**

| File | Old condition | New condition |
|---|---|---|
| `DocumentController@index` | `status = 'verified'` | `visibility = 'public'` |
| `DocumentController@show` + `@pdf` | `$doc->status !== 'verified'` | `$doc->visibility !== 'public'` |
| `DocumentController@showRuleSetDoc` + `@pdfRuleSetDoc` | same | same |
| `SectionController@show` | `status = 'verified'` | `visibility = 'public'` |
| `FrontendController@dashboard` | `status = 'verified'` | `visibility = 'public'` |
| `SearchController@index` | `status = 'verified'` | `visibility = 'public'` |

**Upload modals** (`sections/show.blade.php`, `rule_sets/show.blade.php`) — visibility radio group added below the document type selector. Default: Public. Options: 🌐 Public / 🔒 Authenticated Only. Helper text explains the distinction.

**`documents/show.blade.php`** — visibility badge in document header: green globe for Public, amber lock for Authenticated Only.

---

## M15 — Rule Set Upload Flow Overhaul & Cascade Delete

### Two separate upload modals replacing the combined mode-toggle modal

The single "Upload Document" modal with a Rule Document / Amendment tab toggle has been replaced with two fully independent modals: **`#modal-rule`** (Upload Rule Document) and **`#modal-amendment`** (Upload Amendment). Each has its own file queue, form, and JS IIFE — sharing only a generic `makeQueue()` factory function.

**`#modal-rule`** — indigo accent. Type dropdown shows all document types except `rule_amendment`; defaults to `rule` (pre-selected via `@selected`). No parent field. Triggered by the "Upload Rule" header button.

**`#modal-amendment`** — amber accent. Document type is a fixed hidden input (`rule_amendment`) shown as a read-only badge. Requires a parent document selection from a dropdown pre-populated from `$parentOptions` (root docs only); auto-selects if exactly one root rule doc exists. Triggered by the "Upload Amendment" header button.

### State-aware header buttons

```php
$hasRuleDoc     = $rootDocuments->where('document_type', 'rule')->isNotEmpty();
$canUploadRule  = ! $hasRuleDoc;
$canUploadAmend = $hasRuleDoc;
```

| Condition | Upload Rule button | Upload Amendment button |
|---|---|---|
| No rule doc yet | Active (indigo) | Disabled (greyed, `cursor-not-allowed`) |
| Rule doc exists | Disabled — tooltip: "delete it first" | Active (indigo) |

Disabled buttons use `disabled` HTML attribute; onclick is omitted entirely (not guarded by JS) so no keyboard bypass is possible.

### Edit button locked on rule docs with amendments

`documents/show.blade.php` — when `$document->document_type === 'rule'` and `$document->amendments->isNotEmpty()`, the Edit `<a>` tag is replaced with a `<span>` styled identically but greyed out and `cursor-not-allowed`. The delete button is unaffected.

### Amendments eager-load fix

`DocumentController@show` and `@showRuleSetDoc` — the constrained `amendments:` select now includes `status` and `visibility` columns. Previously only `id,parent_id,title,slug,created_at` were selected, causing the amber "has amendments" banner on `documents/show` and the Amendments list section to fail (missing `status` for badge rendering).

### Rule set cascade delete

`RuleSetController@destroy` — before soft-deleting the rule set, iterates all its documents via `$ruleSet->documents()->each(...)`, writes a `DocumentStatusHistory` entry per document (`to_status = 'deleted'`, note = "Deleted with parent rule set.", `actor_id` = current user), then soft-deletes the document. The rule set is deleted last within the same `DB::transaction()`. Users no longer need to delete each document individually before a rule set can be removed.

### JS refactor

The single large upload IIFE was replaced by a shared `makeQueue(ids)` factory that takes an object of element IDs and an optional `validate()` callback. Both modals call `makeQueue()` independently with their own element IDs. Escape key closes both modals. Parent dropdown in the amendment modal is pre-populated from the `$parentOptions` server-side data island.

---

## M16 — Internal Divisions Module

### What it is

Adds an **Internal Division** (पटल / desk / cell) as a sub-entity of sections. A section can have zero or more divisions, each with its own document stream and amendment hierarchy. Divisions model the organisational reality that different internal desks within a section handle different subjects, while every letter is formally issued by the section (not the desk).

### Schema changes

**New `divisions` table:** `id`, `section_id` FK (restrictOnDelete), `name`, `slug`, `description` nullable, `timestamps`, `softDeletes`. Unique on `(section_id, slug)`. Slug immutable after creation — vault paths depend on it.

**`documents` table:** Added nullable `division_id` FK (nullOnDelete). The old `(section_id, slug)` unique index was replaced with `(section_id, division_id, slug)` — MariaDB treats NULLs as distinct in multi-column unique indexes, so direct section docs and division docs can share a slug without conflicting.

### Document FK layout (three-way taxonomy)

| Context | `section_id` | `division_id` | `rule_set_id` |
|---|---|---|---|
| Direct section doc | non-null | null | null |
| Division doc | non-null | non-null | null |
| Rule-set doc | null | null | non-null |

### Vault paths

Division docs: `document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/divisions/{division_slug}/{slug}_{YmdHis}.pdf`

### Amendments across divisions

Parent options for the division upload modal lists all root documents in the **section** (not just the division). Cross-division amendments are permitted — a doc in the Pension Desk can amend a doc in the Revenue Branch, or a direct section doc.

### New files

- `app/Models/Division.php` — belongs to Section, has many Documents, slug helper `uniqueSlugForSection`
- `app/Http/Controllers/DivisionController.php` — full CRUD + show hub
- `app/Http/Requests/StoreDivisionRequest.php` / `UpdateDivisionRequest.php` — admin-only
- `resources/views/divisions/create.blade.php`, `edit.blade.php`, `show.blade.php`, `_doc_row.blade.php`

### Updated files

- `Document` model: `division_id` in fillable, `division()` BelongsTo, `uniqueSlugForDivision()` helper
- `Section` model: `divisions()` HasMany relationship
- `DocumentController@store`: third branch for `division_id`; redirect to division show page
- `DocumentController`: new `showDivisionDoc`, `pdfDivisionDoc`, `editDivisionDoc`, `updateDivisionDoc`, `destroyDivisionDoc` methods
- `SectionController@show`: passes `$divisions` (with doc count) and filters `$documents` to `whereNull('division_id')`
- `StoreDocumentRequest`: `division_id` nullable field added
- `AppServiceProvider`: `Route::bind('division', ...)` scoped to section_id
- `routes/web.php`: 11 new division routes
- `sections/show.blade.php`: division cards grid above direct documents; "Add Division" button in header for admins
- `documents/show.blade.php`: `$isDivisionDoc` flag for routes, breadcrumb, and subtitle
- `documents/index.blade.php`: division-aware routing and display name
- `search/index.blade.php`: division results block; division-aware document routing
- `SearchController`: eager loads `division`, searches division name/description, passes `$divisions` to view

---

## M17 — Amendment Metadata, Date-Based Sort/Filter, Breadcrumb Normalisation

### Breadcrumb normalisation

All views were using `<x-slot:breadcrumb>` with inline Tabler icon HTML. Migrated to `<x-breadcrumb :items="[...]" />` array-style component across all affected views (`documents/trash.blade.php` was the last remaining offender).

### Document amendment metadata (`metadata` JSON column)

No new migration. Four new keys stored inside the existing `metadata` JSON column on `documents`:

| Key | Type | Meaning |
|---|---|---|
| `amendment_number` | integer 1–999 | Ordinal number of the amendment (e.g. 5 = "5th amendment") |
| `effective_year` | integer 1900–2099 | Year the amendment / rule came into force |
| `effective_month` | integer 1–12 | Month (optional) |
| `effective_day` | integer 1–31 | Day (optional) |

`StoreDocumentRequest` and `UpdateDocumentRequest` both validated with `nullable|integer` rules. `DocumentController` uses two private helpers:
- `extractMetadata(array $validated)` — builds a clean metadata array from the four keys for new documents.
- `mergeMetadata(array $validated, Document $document)` — merges into existing metadata on update, supporting individual key deletion by setting null.

Upload modals (sections, divisions, rule sets) all include a 2×2 field grid for these values. JS `FormData` captures them and appends conditionally.

### Sort and filter

**Rule sets and divisions** (tree/hierarchy structure) — PHP-side collection sort/filter applied after eager-loading. `setRelation('amendments', $sorted)` replaces the lazy-loaded collection without extra queries.

**Sections** (flat paginated list) — SQL-level sort/filter using `JSON_EXTRACT(metadata, '$.effective_year')` and `JSON_UNQUOTE(...)` for the WHERE clause.

Sort options available:
- Amendment # ↓↑ (default for rule sets/divisions)
- Effective year ↓↑
- Uploaded date ↓↑ (default for sections)

Year filter dropdown appears only when at least one document in context has `effective_year` set. Filter cleared via `×` link.

### Display

- Row badges: `#N` amber badge for amendment number; effective date shown as `15 Jan 2019` / `Jan 2019` / `2019` beside the upload date.
- `documents/show` sidebar: replaces raw `metadata` key-value dump with a structured "Amendment No." (`#N` bold) and "Effective Date" (full month names) display.
- `documents/edit.blade.php`: "Amendment Details" section appears for `rule` and `rule_amendment` document types only; all four fields pre-populated from `$document->metadata`.

---

## M18 — Full Unicode / Devanagari (Rajbhasha) Support for All CRUD Fields

### Problem

Document titles containing Hindi text with Devanagari combining marks (matras: `ु`, `ि`; halant: `्`) were rejected by both PHP-side regex validation and JS-side frontend patterns. Root cause: `\p{L}` in both PCRE (PHP) and ECMAScript `u`-flag regexes matches Unicode *letters* (base characters) but not Unicode *combining marks* (category `\p{M}`). A word like `शुद्धिपत्र` contains base letters and combining matras — the matras failed the allowlist.

The same issue affected all `name` fields (sections, departments, rule sets, divisions) — any Hindi name would be rejected at the frontend before the request even reached Laravel.

### Fix: Unicode category classes

All non-user text fields now use:

```
/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u
```

| Category | Covers |
|---|---|
| `\p{L}` | All Unicode letters — Latin, Devanagari base chars, Arabic, etc. |
| `\p{M}` | Combining marks — Devanagari matras, halant, Arabic diacritics, etc. |
| `\p{N}` | All Unicode numbers |
| `\p{P}` | All Unicode punctuation — `।`, `॥`, `-`, `.`, `(`, `)`, `'`, `:`, `;` etc. |
| `\p{Z}` | Unicode separators |
| `\s` | Standard ASCII whitespace |

This is a single, script-agnostic pattern that handles entirely Devanagari titles, mixed Hindi-English titles, and English-only titles without any script-specific hardcoding.

### Scope: non-user fields only

The **user model is deliberately excluded**. `name`, `username`, `email`, `mobile`, `post`, and `designation` fields on `users` remain Latin-only:

- `name` and `post` keep `\p{L}\s'\-\.` — person names and designations in this system are in English (standard government nomenclature).
- `username` keeps `[a-zA-Z0-9_]` — system identifiers must be ASCII.
- `email` and `mobile` are format-constrained by their own rules.

Allowing Unicode in user fields introduces ambiguity in authentication flows (homoglyph attacks, normalisation mismatches between login and stored value) and is not needed since all staff names and designations are recorded in English.

### Files changed

**PHP Form Requests (backend validation):**
- `StoreDocumentRequest` — `title` field
- `UpdateDocumentRequest` — `title` field
- `StoreSectionRequest` / `UpdateSectionRequest` — `name` field
- `StoreDepartmentRequest` / `UpdateDepartmentRequest` — `name` field
- `StoreRuleSetRequest` / `UpdateRuleSetRequest` — `name` field
- `StoreDivisionRequest` / `UpdateDivisionRequest` — `name` field

**Blade views (JS frontend validation):**
- `sections/create.blade.php` / `sections/edit.blade.php` — `RULES.name.pattern`
- `department/create.blade.php` / `department/edit.blade.php` — `RULES.name.pattern`
- `rule_sets/create.blade.php` / `rule_sets/edit.blade.php` — `NAME_PATTERN`
- `divisions/create.blade.php` / `divisions/edit.blade.php` — `NAME_PATTERN`

---

## M19 — Unicode-Preserving Slug Generation

### Problem

`Str::slug()` internally calls PHP's ICU transliterator, which maps every Devanagari character to a Latin approximation. `शुद्धिपत्र` became `shathathhapatara` — not useful to Hindi readers and lossy (multiple distinct Devanagari strings could map to the same Latin output, risking slug collisions). Additionally, Devanagari matras (`\p{M}` combining marks) were stripped entirely before transliteration, compounding the garbling.

### Fix

New trait `app/Models/Concerns/HasUnicodeSlug.php` with `makeSlug(string $text): string`:

```
mb_strtolower → preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-') → trim('-')
```

Keeps Unicode letters and combining marks intact; collapses spaces, brackets, punctuation to hyphens. `Str::slug()` is never called on user-supplied text again.

Result for `FL Bottling Rules 2011 (16th amendment) (शुद्धिपत्र)`:
- Before: `fl-bottling-rules-2011-16th-amendment-shathathhapatara`
- After:  `fl-bottling-rules-2011-16th-amendment-शुद्धिपत्र`

Modern browsers display percent-decoded Unicode in the address bar, so the URL reads naturally. The slug is still URL-safe (percent-encoded on the wire).

### Files changed

- `app/Models/Concerns/HasUnicodeSlug.php` — new trait
- `app/Models/Document.php` — uses `HasUnicodeSlug`; all three `uniqueSlugFor*` methods use `static::makeSlug()`
- `app/Models/RuleSet.php` — uses `HasUnicodeSlug`; `uniqueSlugForDepartment()` updated
- `app/Models/Division.php` — uses `HasUnicodeSlug`; `uniqueSlugForSection()` updated

### Existing slugs

Slugs already stored in the DB are unaffected — they were written once at upload and are never regenerated. Only new uploads going forward use the improved format.

---

## M20 — Fix: Division 404 due to implicit/explicit route binding order (2026-06-23)

### Problem

Navigating to a division show page (e.g. `/departments/sectt/excise/sections/deputy_secretary_wing/divisions/section-1`) returned 404 despite the records existing in the DB.

Root cause: the `{division}` custom `Route::bind()` callback called `request()->route('section')` expecting a resolved `Section` model instance. But `{section}` was using Laravel's **implicit** route model binding (no corresponding `Route::bind()` entry), and implicit bindings resolve *after* explicit ones. So when the division binding fired, `section` was still a raw slug string — the `instanceof Section` guard caught this and called `abort(404)`.

### Fix

Added an explicit `Route::bind('section', ...)` in `AppServiceProvider::configureRouteBindings()`, declared immediately before the `division` binding. It scopes the lookup to the already-resolved `{department}`:

```php
Route::bind('section', function (string $slug) {
    $dept = request()->route('department');
    $query = Section::where('slug', $slug);
    if ($dept instanceof Department) {
        $query->where('department_id', $dept->id);
    }
    return $query->firstOrFail();
});
```

This also closes a latent edge case: previously a section slug shared across two departments (possible if different wings used the same slug) could resolve to the wrong section. The department scope prevents that.

### Files changed

- `app/Providers/AppServiceProvider.php` — added `Route::bind('section', ...)` before the `division` binding; removed the now-unnecessary comment about late resolution

---

## M21 — Demo seeder: multi-role user accounts (2026-06-23)

Added demo accounts to `database/seeders/UserSeeder.php` covering all three roles and multiple privilege combinations, for use during demos and onboarding.

Seeder is idempotent — uses `firstOrCreate` on email; re-running never duplicates or overwrites existing records.

### Accounts

| Role | Email | Password | Privileges |
|---|---|---|---|
| Admin | `redacted-personal-email@example.com` | `REDACTED-PASSWORD` | `['*']` — primary dev account |
| Admin (demo) | `admin.demo@excise.up.gov.in` | `REDACTED-PASSWORD` | `['*']` — Deputy Commissioner persona |
| Operator (full) | `operator.full@excise.up.gov.in` | `REDACTED-PASSWORD` | upload + edit + delete + restore + verify |
| Operator (upload-only) | `operator.upload@excise.up.gov.in` | `REDACTED-PASSWORD` | `['documents.upload']` — junior clerk |
| Operator (review/verify) | `operator.review@excise.up.gov.in` | `REDACTED-PASSWORD` | edit + verify — QA reviewer persona |
| Viewer | `viewer@excise.up.gov.in` | `REDACTED-PASSWORD` | `[]` — read-only authenticated access |

### Role behaviour summary

- **Admin** — full system access including user management (`/admin/users`); `IsAdmin` middleware gates all admin routes; `isAdmin()` returns true regardless of `privileges` array.
- **Operator** — authenticated mutations (upload, edit, delete, restore, verify) gated by individual `privileges` entries; no access to user management.
- **Viewer** — authenticated but no mutation privileges; can access `authenticated`-visibility documents that guests cannot see.

### Files changed

- `database/seeders/UserSeeder.php` — replaced single-account seeder with the six-account loop above

---

## M22 — Mobile validation fix + landline field (2026-06-23)

### Mobile regex relaxed

Removed the `[6-9]` first-digit constraint — numbers starting with 8 (e.g. `8090114114`, `8423123202`) are valid Indian mobiles. New rule: `digits:10` only. `+91` / `+91-` prefix stripped server-side via `StoreUserRequest::sanitizeMobile()` (static helper reused by `UpdateUserRequest` and `UpdateProfileRequest`) and mirrored in JS custom validators on all three user forms. No cosmetic `+91-` prefix added to display or storage.

### Landline field added

New `landline VARCHAR(20) NULL` column added to `users` table (migration updated; column added live via `ALTER TABLE`). Stores STD code + subscriber number free-form as the user types it (e.g. `0522-223456` or `0522 223456`). Validated as 7–20 chars of digits/spaces/hyphens/parentheses in all three form requests and matching JS rules. Shown in user index alongside mobile.

### Files changed

- `database/migrations/0001_01_01_000000_create_users_table.php` — added `landline` column
- `app/Models/User.php` — `landline` added to `$fillable`
- `app/Http/Requests/Admin/StoreUserRequest.php` — relaxed mobile regex; added `sanitizeMobile()` static helper; added landline validation
- `app/Http/Requests/Admin/UpdateUserRequest.php` — same; reuses `StoreUserRequest::sanitizeMobile()`
- `app/Http/Requests/UpdateProfileRequest.php` — same
- `resources/views/admin/users/create.blade.php` — landline field + JS rule
- `resources/views/admin/users/edit.blade.php` — landline field + JS rule
- `resources/views/profile/edit.blade.php` — landline field + JS rule
- `resources/views/admin/users/index.blade.php` — landline shown in contact cell

---

## M23 — Archive Module + Scope-Based Upload Permissions (COMPLETED 2026-06-24)

Two interlocking features fully implemented in a single session.

---

### Feature A: Archive Module (UI rename + access control overhaul)

**What did NOT change (backend kept intact):**
- Route names: `documents.trash`, `documents.restore`, `documents.force-destroy`, `documents.trashed.pdf`, `documents.trash.bulk-restore`, `documents.trash.bulk-force-destroy`
- Controller method names: `trash()`, `restore()`, `forceDestroy()`, `trashedPdf()`
- Soft-delete mechanism: `SoftDeletes`, `onlyTrashed()`, `withTrashed()`, `deleted_at` — all unchanged
- `document_status_histories` structure: only a nullable `metadata JSON` column was added (stores letter path on permanent delete)

**What changed:**

| Area | Before | After |
|---|---|---|
| UI label | "Trash" / "Move to Trash" | "Archive" / "Archive Document" |
| Sidebar link label | "Trash" | "Archive" |
| Archive page visibility | Auth-only (any authenticated user) | Auth-only (unchanged — guests cannot see) |
| Document counts (dashboard + all views) | Total documents only | **Active** (non-deleted) + **Archived** (soft-deleted) shown separately |
| Restore permission | Any authenticated user | `documents.restore` privilege or admin |
| Permanent delete | Admin + reason text | Users with `documents.force-delete` privilege + reason text + **mandatory letter PDF upload** |

**Permanent delete letter storage:**
- Letter PDF stored to `local` (private) disk at `archive_letters/{document_id}_{YmdHis}.pdf` — not web-accessible (see M24 for security rationale)
- Path + reason stored as JSON in `document_status_histories.metadata` on the `to_status = 'force_deleted'` history row
- `DocumentController@forceDestroy` validates: `documents.force-delete` privilege, reason (5–500 chars), and a valid uploaded letter PDF
- `DeleteDocumentRequest::authorize()` handles both soft-delete scope check and force-delete privilege check

**Count changes:**
- `FrontendController@dashboard` — `active_count` = `Document::count()` (no deleted), `archived_count` = `Document::onlyTrashed()->count()`. Guests see only public docs for both counts.
- Dashboard stat cards updated: two separate cards or a split stat.
- All `withCount('documents')` calls in `DepartmentController`, `SectionController`, `RuleSetController` — active docs only (unchanged, since `withCount` already excludes soft-deleted via `SoftDeletes`).

---

### Feature B: Scope-Based Upload Permissions

**Schema changes (users table):**
- Added `division_id` FK → `divisions`, nullable, `nullOnDelete`
- `division_id` added to `User::$fillable`
- `User` model gains `division()` BelongsTo relation

**New privilege strings (defined as `User::PRIVILEGES` constant — whitelist enforced in `StoreUserRequest` and `UpdateUserRequest` to prevent escalation):**

```php
public const PRIVILEGES = [
    'documents.upload',
    'documents.edit',
    'documents.delete',
    'documents.restore',       // restore from archive — already existed in seeder, now enforced
    'documents.force-delete',  // permanent delete from archive (requires letter)
    'documents.verify',
    'organization.head',       // can upload/delete anywhere across all departments
    'department.head',         // scoped to their assigned department
    'section.head',            // scoped to their assigned section
];
```

No `division.head` — division is the smallest unit; operators are assigned via `division_id` directly.

**Upload scope logic (`User::canUploadTo($context)` helper — $context is Section|Division|RuleSet):**

| User assignment | Can upload to |
|---|---|
| `division_id` set | That division only |
| `section_id` set, no division | All divisions in that section + direct section docs |
| `department_id` set, no section | All sections + divisions in that department |
| Has `department.head` privilege + `department_id` | Entire that department |
| Has `organization.head` privilege | Anywhere |
| Admin | Anywhere |
| Operator with `documents.upload` + no dept/section/division assigned | Anywhere (legacy behaviour — for initial data entry phase; scope to be tightened once initial upload is done) |

**Delete scope:** Same as upload scope — `User::canDeleteFrom($context)` uses identical logic. Cross-section and cross-division deletes are blocked.

**View scope:** No restrictions — all authenticated users can view all documents regardless of their assignment.

**Creation privileges:**

| Action | Allowed for |
|---|---|
| Create Division | `section.head` for their section, `department.head` for their dept, admin |
| Create Section | `department.head` for their dept, admin |
| Create Department | `organization.head`, admin |

**Form Request authorize() changes:**
- `StoreDocumentRequest::authorize()` — calls `auth()->user()->canUploadTo($context)` where context is resolved from the validated `section_id`, `division_id`, or `rule_set_id`
- `DeleteDocumentRequest::authorize()` — calls `auth()->user()->canDeleteFrom($context)`
- `StoreDivisionRequest::authorize()` — `section.head` for parent section OR `department.head`/admin
- `UpdateDivisionRequest::authorize()` — same
- `StoreSectionRequest::authorize()` — `department.head` for parent dept OR admin
- `UpdateSectionRequest::authorize()` — same
- `StoreDepartmentRequest::authorize()` — `organization.head` OR admin
- `UpdateDepartmentRequest::authorize()` — same

**View changes (conditional UI):**
- Upload buttons on `sections/show`, `divisions/show`, `rule_sets/show` — hidden unless `auth()->user()->canUploadTo($context)`
- "Add Division" button on `sections/show` — hidden unless `section.head` or `department.head` or admin
- "Add Section" button on `departments/show` — hidden unless `department.head` or admin
- "Add Rule Set" button on `departments/show` — hidden unless `department.head` or admin
- "Add Department" button on `departments/index` — hidden unless `organization.head` or admin
- Restore button on archive page — hidden unless `documents.restore` or admin
- Permanent delete button on archive page — hidden unless `documents.force-delete` or admin

**User management form changes:**
- `admin/users/create.blade.php` + `edit.blade.php` — `division_id` dropdown (cascades: dept → section → division via JS), new privilege checkboxes for `organization.head`, `department.head`, `section.head`, `documents.force-delete`
- `admin/users/show.blade.php` — shows division assignment

**Safety notes (privilege escalation prevention):**
- `User::PRIVILEGES` constant is the canonical whitelist; `UpdateUserRequest` validates `privileges.*` as `in:` against this list
- Privileges are only settable via `admin.*` routes (gated by `IsAdmin` middleware + `authorize()` in Form Request)
- `UpdateProfileRequest` has no privilege fields — self-escalation impossible
- `User::hasPrivilege($key)` returns `true` for admins unconditionally (existing behaviour, unchanged)
- `canUploadTo()` and `canDeleteFrom()` never trust user-supplied IDs — context is always resolved from already-bound route models

---

### Files changed in M23

**Migrations (new):**
- Add `division_id` to `users` table
- Add `metadata JSON nullable` to `document_status_histories`

**Models:**
- `User.php` — `division_id` in fillable, `division()` relation, `PRIVILEGES` constant, `canUploadTo()`, `canDeleteFrom()`, `uploadScope()` helpers
- `DocumentStatusHistory.php` — `metadata` in fillable

**Form Requests:**
- `StoreDocumentRequest` — `authorize()` scope check
- `DeleteDocumentRequest` — scope check + `letter` field for force-delete
- `StoreDivisionRequest`, `UpdateDivisionRequest` — section.head/dept.head/admin
- `StoreSectionRequest`, `UpdateSectionRequest` — dept.head/admin
- `StoreDepartmentRequest`, `UpdateDepartmentRequest` — org.head/admin
- `Admin\StoreUserRequest`, `Admin\UpdateUserRequest` — privilege whitelist validation, `division_id` field

**Controllers:**
- `DocumentController@restore` — `documents.restore` privilege gate
- `DocumentController@forceDestroy` — `documents.force-delete` gate + letter upload + metadata storage
- `FrontendController@dashboard` — active + archived counts

**Views:**
- `sidebar.blade.php` — "Archive" label
- `documents/trash.blade.php` — full relabel to Archive; scope-gated restore/delete buttons; new permanent delete modal with letter upload
- Home/dashboard view — active + archived stat cards
- `sections/show.blade.php` — conditional upload button, conditional "Add Division"
- `divisions/show.blade.php` — conditional upload button
- `rule_sets/show.blade.php` — conditional upload button
- `departments/show.blade.php` — conditional "Add Section", "Add Rule Set"
- `departments/index.blade.php` — conditional "Add Department"
- `admin/users/create.blade.php` + `edit.blade.php` — `division_id` dropdown (cascades dept → section → division via JS), new privilege checkboxes for `organization.head`, `department.head`, `section.head`, `documents.force-delete`, `documents.restore`

---

## M24 — Security Hardening for NIC/SDC Pre-Deployment (COMPLETED 2026-06-24)

Full security audit and remediation pass targeting NIC / STQC compliance and the UP State Data Centre pre-deployment review.

---

### Audit Scope

Controllers, Form Requests, Models, Middleware, Blade views, route configuration, `.htaccess`, rate limiters.

---

### Findings and Fixes

**H-01 — Bulk force-delete had no audit trail (HIGH → FIXED)**
- `bulkForceDestroy()` permanently deleted up to 100 documents with zero evidence: no reason captured, no `DocumentStatusHistory` rows written, no authorisation letter.
- Fix: Added `reason` field to `BulkForceDestroyDocumentsRequest` (required, 5–500 chars, strip_tags sanitised). Controller now writes one `DocumentStatusHistory` row per document (`to_status = 'force_deleted'`, `note = $reason`) before `forceDelete()`. Two-step Swal2 flow in trash view: textarea prompt → final confirmation.

**H-02 — Bulk restore was scope-blind IDOR (HIGH → FIXED)**
- `bulkRestore()` had a privilege check but no per-document scope check. A division-scoped operator could restore documents from any department.
- Fix: Per-document scope check inside the loop — resolves `$document->division ?? $document->section ?? $document->ruleSet` and calls `canDeleteFrom()`. Out-of-scope documents are silently skipped; admins bypass unconditionally.

**M-01 — No security response headers (MEDIUM → FIXED)**
- Zero headers set. NIC/STQC mandate CSP, X-Frame-Options, X-Content-Type-Options, HSTS, Referrer-Policy, Permissions-Policy.
- Fix: New `App\Http\Middleware\SecurityHeaders` middleware registered globally in `bootstrap/app.php` via `$middleware->append(...)`. Sets all required headers on every response including error pages. HSTS only sent over HTTPS (not on local HTTP dev). `unsafe-inline` allowed in CSP for Tailwind Play CDN + inline Blade scripts.

**M-02a — Archive letters on public disk (MEDIUM → FIXED)**
- Letters were stored via `Storage::disk('public')->putFileAs(...)` → web-accessible at `/storage/archive_letters/...`.
- Fix: Changed to `Storage::disk('local')->putFileAs('archive_letters', ...)` (private disk at `storage/app/private/`, no symlink, no web access). `forceDestroy()` cleanup on failure updated to `Storage::disk('local')->delete(...)`.

**M-02b — Soft-deleted documents accessible via storage symlink (MEDIUM → FIXED)**
- Archived PDFs remained on the public disk. Anyone who guessed or found the path could access them directly at `/storage/document_vault/...`, bypassing auth and soft-delete.
- Fix: Added `mod_rewrite` rule to `public/.htaccess` returning HTTP 403 for any direct request to `/storage/document_vault/` or `/storage/archive_letters/`. All document access must now go through controller routes which enforce auth, visibility, and soft-delete checks.

**M-03 — Parsedown `javascript:` URI bypass — stored XSS (MEDIUM → FIXED)**
- `Parsedown::setSafeMode(true)` strips `<script>` tags but does not sanitize `javascript:`, `data:`, or `vbscript:` URI schemes in `href`/`src` attributes. The `{!! !!}` raw output bypassed Blade auto-escaping.
- Fix: Post-processing `preg_replace` strips any `href` or `src` attribute beginning with `javascript:`, `data:`, or `vbscript:`, replacing with `href="#"`. Applied in `documents/show.blade.php` after the Parsedown render.

**L-01 — SVG accepted despite web-accessible storage (LOW → FIXED)**
- `image/svg+xml` was in `ACCEPTED_MIMETYPES`. SVG is XML and can embed `<script>` tags. Enabled the M-03 XSS chain via markitdown extraction.
- Fix: Removed `image/svg+xml` from `StoreDocumentRequest::ACCEPTED_MIMETYPES`. Error message updated: "SVG files are not permitted." No government document workflow requires SVG uploads.

**L-02 — `original_filename` stored without sanitization (LOW → FIXED)**
- `getClientOriginalName()` was stored as-is and later used in `Content-Disposition` headers. Quotes and special characters could create RFC-noncompliant headers.
- Fix: Sanitized with `preg_replace('/[^\w\s\-\.\(\)]/', '_', ...)` before storage.

**L-03 — Department binding silently defaulted for unknown level aliases (LOW → FIXED)**
- `default => 'department_level'` in the `match` expression meant any unknown `{level}` alias resolved to `department_level` rather than 404, masking routing bugs.
- Fix: Changed to `default => abort(404)`.

**Upload rate limit capped (MEDIUM → FIXED)**
- Upload limiter was 60/min — worst-case 3 GB/min per user at 50 MB cap.
- Fix: Reduced to 20/min (1 GB/min worst-case). Tighten to 5–10/min after initial document backlog is loaded.

---

### Passing Checks (Pre-Existing)

14 security areas audited and confirmed correct before this pass: MIME magic-byte validation, slug traversal prevention, privilege escalation (self-edit), CSRF, SQL injection, mass assignment, login brute-force rate limiting, XSS auto-escape, self-delete guard, two-stage deletion, `prepareForValidation()` sanitation, password logging exclusion, admin double-gate, privilege whitelist enforcement.

---

### Files changed in M24

**New:**
- `app/Http/Middleware/SecurityHeaders.php` — all NIC-required response headers
- `SECURITY.md` — full audit report with findings, mitigations, and post-remediation recommendations

**Modified:**
- `app/Http/Requests/StoreDocumentRequest.php` — removed `image/svg+xml` from `ACCEPTED_MIMETYPES`
- `app/Http/Requests/BulkForceDestroyDocumentsRequest.php` — added `reason` field, `prepareForValidation()`, corrected `authorize()` to `hasPrivilege`
- `app/Http/Controllers/DocumentController.php` — `original_filename` sanitization; `bulkRestore()` scope check; `bulkForceDestroy()` reason + history rows; `forceDestroy()` letter moved to private disk
- `app/Providers/AppServiceProvider.php` — upload limiter 60→20/min; department binding `default→abort(404)`
- `bootstrap/app.php` — registered `SecurityHeaders` middleware globally
- `public/.htaccess` — 403 rule blocking direct `/storage/document_vault/` and `/storage/archive_letters/` requests
- `resources/views/documents/show.blade.php` — Parsedown post-processor stripping `javascript:`/`data:`/`vbscript:` URIs
- `resources/views/documents/trash.blade.php` — two-step Swal2 bulk force-delete flow with reason capture
- `CLAUDE.md` — stale references corrected; architecture decisions #19–24 added
- `README.md` — SVG removed from file types; rate cap corrected; M24 section added
- `summary.md` — SVG reference corrected in M5; M23 letter storage updated; M24 entry added

---

## M25 — Activity Log: Authenticated User Action Auditing (COMPLETED 2026-06-24)

Implements a tamper-evident, append-only audit trail for all authenticated mutations and logins, satisfying the NIC/SDC audit-trail requirement for government web applications.

---

### What is logged

| Event | Action field | Triggered by |
|---|---|---|
| Successful login | `auth.login` | `Illuminate\Auth\Events\Login` listener |
| Document upload | `documents.store` | `LogMutation` middleware |
| Document metadata edit | `documents.update` / `documents.rules.update` / `documents.divisions.update` | middleware |
| Archive (soft-delete) | `documents.destroy` / `documents.rules.destroy` / `documents.divisions.destroy` | middleware |
| Bulk archive | `documents.bulk-destroy` | middleware |
| Restore from archive | `documents.restore` | middleware |
| Bulk restore | `documents.trash.bulk-restore` | middleware |
| Permanent delete | `documents.force-destroy` | middleware |
| Bulk permanent delete | `documents.trash.bulk-force-destroy` | middleware |
| Create/edit/delete user | `admin.users.store` / `admin.users.update` / `admin.users.destroy` | middleware |
| Profile self-update | `profile.update` | middleware |
| Create/edit/delete org entities | `departments.*`, `departments.sections.*`, etc. | middleware |

Guests are **never** logged. Failed requests (4xx, 5xx) are logged along with their HTTP status — this allows detection of repeated failed attempts that bypass rate limiting.

---

### Architecture

**`activity_logs` table** — append-only; `created_at` only (no `updated_at`); `user_id` is `nullOnDelete` so log rows are preserved even after a user account is deleted (the row shows "Deleted user" in the view).

**`ActivityLog::record(string $action, Request $request, array $meta = []): void`** — static non-fatal helper. Any exception during write is caught and written to Laravel's application log, never propagated to the HTTP response.

**`LogMutation` middleware** — registered globally via `$middleware->append(...)` in `bootstrap/app.php`. Calls `$next($request)` first so it can capture the HTTP response status code. Skips all `GET`/`HEAD`/`OPTIONS` requests and all unauthenticated requests — zero overhead for public read-only traffic.

**Login listener** — `Event::listen(Login::class, ...)` in `AppServiceProvider::configureActivityLogging()`. Records guard name in metadata alongside IP and UA.

---

### Admin view

`GET /admin/activity-logs` → `Admin\ActivityLogController@index` → `admin/activity-logs/index.blade.php`

- Admin-only (gated by `is_admin` middleware on the entire `admin.*` route group)
- Filterable by user, action type, and IP address
- 50 rows per page, newest first
- Action badges color-coded by category (green = upload, red = delete/permanent, indigo = edit, sky = login, purple = user management)
- HTTP status shown with color (green 2xx, amber 4xx, red 5xx)
- Sidebar: "Activity Log" link (`ti-activity` icon) visible to admins only

---

### Security properties

- **Tamper-evident:** no application route can delete or update `activity_logs` rows. Physical deletion requires direct DB access.
- **Non-repudiation:** IP + user agent + timestamp + authenticated user ID are all captured per event.
- **Failure-safe:** `ActivityLog::record()` never throws; a DB write failure during logging does not affect the actual user operation.
- **No sensitive data:** request body is never stored; only method + URL + status. Passwords cannot appear in the URL (all auth forms use POST). `request()->fullUrl()` does not include the POST body.
- **Guest exclusion:** `auth()->check()` guard in middleware ensures zero rows for unauthenticated traffic.

---

### Files added/changed in M25

**New:**
- `database/migrations/2026_06_24_000003_create_activity_logs_table.php`
- `app/Models/ActivityLog.php`
- `app/Http/Middleware/LogMutation.php`
- `app/Http/Controllers/Admin/ActivityLogController.php`
- `resources/views/admin/activity-logs/index.blade.php`

**Modified:**
- `app/Providers/AppServiceProvider.php` — `configureActivityLogging()` method with Login listener
- `bootstrap/app.php` — `LogMutation` middleware registered globally
- `routes/web.php` — `GET /admin/activity-logs` route added to admin group
- `resources/views/components/sidebar.blade.php` — "Activity Log" link under admin section

---

## M26 — Auth/Fortify/Session Security Audit & Hardening (COMPLETED 2026-06-24)

Security audit pass targeting the login and authentication stack specifically for NIC/SDC deployment compliance.

---

### Findings & Fixes

**A-01 — FortifyServiceProvider overwrote dual-key login rate limiter (HIGH → FIXED)**
- `FortifyServiceProvider::boot()` redefined `RateLimiter::for('login', ...)` with a single-key (email+IP) limiter, running after `AppServiceProvider` and silently overwriting the dual-key version (email+IP AND IP-only 10/min). The per-IP brute-force cap was dead.
- Fix: Removed the duplicate `RateLimiter::for('login', ...)` from `FortifyServiceProvider`. `AppServiceProvider` is the sole, authoritative rate limiter definition.

**A-02 — `Password::defaults()` not configured (MEDIUM → FIXED)**
- `PasswordValidationRules::passwordRules()` uses `Password::default()`. Without `Password::defaults(fn...)` registered, this resolved to bare min-8 with no complexity. Fortify actions (password reset, profile update) would have accepted `12345678` if their features were re-enabled.
- Fix: Added `Password::defaults(fn () => Password::min(8)->mixedCase()->numbers()->symbols())` in `AppServiceProvider::boot()`. All uses of `Password::default()` across the codebase now inherit the strong policy.

**A-03 — SESSION_SECURE_COOKIE not set (MEDIUM → FIXED)**
- Key absent from both `.env` and `.env.example`, defaulting to `null` (falsy). Session cookie had no `Secure` flag.
- Fix: Added to both files. Local dev: `false`. `.env.example` comment specifies: **PRODUCTION must be `true`**.

**A-04 — "Remember me" bypasses 120-min session timeout (MEDIUM → FIXED)**
- Laravel's remember-me cookie is 5 years by default. On a shared government workstation, a checked "Keep me signed in" checkbox left the account accessible indefinitely.
- Fix: Removed the checkbox and label from `auth/login.blade.php` entirely. Sessions are now bounded only by `SESSION_LIFETIME` and `SESSION_EXPIRE_ON_CLOSE`.

**A-05 — SESSION_EXPIRE_ON_CLOSE=false (LOW → FIXED)**
**A-06 — SESSION_SAME_SITE=lax (LOW → FIXED)**
**A-07 — SESSION_ENCRYPT=false (LOW → FIXED)**
**A-08 — APP_DEBUG=true in .env.example without production warning (LOW → FIXED)**
- All four addressed via `.env` and `.env.example` — see SECURITY.md A-05 through A-08 for details.

---

### Files changed in M26

- `app/Providers/FortifyServiceProvider.php` — removed duplicate rate limiter
- `app/Providers/AppServiceProvider.php` — added `Password::defaults(...)` call
- `resources/views/auth/login.blade.php` — removed "Remember me" checkbox
- `.env` — added `SESSION_ENCRYPT`, `SESSION_SECURE_COOKIE`, `SESSION_EXPIRE_ON_CLOSE`, `SESSION_SAME_SITE`
- `.env.example` — same keys + production guidance comments on `APP_ENV` and `APP_DEBUG`
- `SECURITY.md` — Pass 2 status table + detailed A-01 through A-08 findings
- `summary.md` — M26 entry added

---

## M27 — Archived Document File Move: Physical Isolation on Soft-Delete (COMPLETED 2026-06-24)

Replaces the M24 blanket `.htaccess` 403 block on `/storage/document_vault/` with a proper file-layer solution that allows direct public URL access to active documents while keeping archived documents physically off the public disk.

---

### Motivation

The M24 `.htaccess` block prevented all direct storage URL access — including for active, public documents. The vault is designed to be used like a public government document repository: documents should be shareable by direct link and indexable by search engines. Blocking `/storage/document_vault/` wholesale broke that. The correct solution is not access control at the web server layer (which can't query the DB) but at the file layer: move the files when they are archived.

---

### How it works

**On soft-delete (archive):** `ManagesDocumentFiles::archiveFiles()` moves the document's PDF and Markdown files from the `public` disk (`storage/app/public/document_vault/…`) to the private `local` disk (`storage/app/private/archived_documents/{id}.pdf`, `…/{id}.md`). Called after the DB transaction commits.

**On restore:** `ManagesDocumentFiles::restoreFiles()` moves files back to their original vault path on the `public` disk. Called after the DB restore transaction commits.

**On permanent delete:** `ManagesDocumentFiles::deleteArchivedFiles()` deletes from the private disk. Called inside the force-delete transaction.

**`trashedPdf` route:** Now streams from the private `local` disk at `archived_documents/{id}.pdf`. Auth-gated.

---

### File access matrix

| Document state | File location | Direct URL accessible |
|---|---|---|
| Active, `visibility=public` | `public` disk → `/storage/document_vault/…` | ✓ Yes — by design |
| Active, `visibility=authenticated` | `public` disk | URL not published; controller enforces auth |
| Archived (soft-deleted) | `local` disk → `archived_documents/{id}.pdf` | ✗ Never — private disk |
| Permanently deleted | Deleted from `local` disk | ✗ N/A |

---

### `.htaccess` change

Removed the `/storage/document_vault/` 403 block. Retained `/storage/archive_letters/` as defence-in-depth only (letters are on the private disk; the block is a safeguard against symlink misconfiguration).

---

### Files added/changed in M27

**New:**
- `app/Http/Controllers/Concerns/ManagesDocumentFiles.php` — trait with `archiveFiles()`, `restoreFiles()`, `deleteArchivedFiles()`

**Modified:**
- `app/Http/Controllers/DocumentController.php` — all soft-delete, restore, and force-delete methods updated; `trashedPdf` serves from local disk
- `app/Http/Controllers/RuleSetController.php` — cascade soft-delete now calls `archiveFiles()` for each document
- `public/.htaccess` — `document_vault` 403 block removed; `archive_letters` block retained

---

## M28 — Maker-Checker Upload Approval Workflow (COMPLETED 2026-06-26)

Introduces a configurable two-stage approval layer between document upload and public visibility. Designed specifically for the bulk-operator onboarding model where junior staff upload large batches of legacy GOs, which must be reviewed by a senior officer before appearing in the public document vault.

---

### Design

**Two independent triggers hold a document in `pending_approval` on upload:**

1. `users.uploads_require_approval = true` — every upload by this user is held, regardless of where they upload. Intended for bulk operators during the initial digitisation phase.
2. `sections.requires_approval = true` / `divisions.requires_approval = true` / `rule_sets.requires_approval = true` — any upload to this context is held, regardless of who uploads. Intended for high-sensitivity sections (e.g. legal orders, confidential circulars).

Either condition is sufficient; both can apply simultaneously.

**If neither condition holds:** document is created with `status = 'uploaded'` and enters the normal extraction pipeline immediately (existing behaviour, unchanged).

---

### Status flow

```
Upload
  ↓
shouldRequireApproval()?
  YES → pending_approval ──→ approve → uploaded → processing → review → verified
                          ↘ reject  → rejected  → resubmit → pending_approval (loop)
  NO  → uploaded → (normal pipeline, unchanged)
```

`pending_approval` and `rejected` documents are excluded from all regular document lists via the `->publishable()` Eloquent scope (`whereNotIn('status', ['pending_approval', 'rejected'])`).

---

### New privilege: `documents.approve`

Added to `User::PRIVILEGES` constant. Holders can approve, reject, and reclassify pending documents within their existing org scope (same boundary as `canUploadTo`). Admins always pass.

Approval scope:
| User type | Can approve |
|---|---|
| Admin | All documents anywhere |
| `organization.head` privilege | All documents anywhere |
| `department.head` + `department_id` | Documents in their assigned department |
| `section.head` + `section_id` | Documents in their assigned section |
| `division_id` assigned | Documents in their assigned division |

---

### New: `ApprovalController`

Six actions at `/approvals/{id}/…` using numeric `{id}` (not slug — reclassification changes the document's context mid-flow, invalidating slug-based routes):

| Route | Method | Action |
|---|---|---|
| `GET /approvals` | `approvals.index` | Queue view — three tabs |
| `GET /approvals/{id}/pdf` | `approvals.pdf` | Stream pending/rejected PDF (from public disk) |
| `POST /approvals/{id}/approve` | `approvals.approve` | `pending_approval → uploaded`, optional note |
| `POST /approvals/{id}/reject` | `approvals.reject` | `pending_approval → rejected`, mandatory reason |
| `POST /approvals/{id}/reclassify` | `approvals.reclassify` | Move to new context, optional approve |
| `POST /approvals/{id}/resubmit` | `approvals.resubmit` | `rejected → pending_approval` (own doc only) |

**Reclassification:** Moves the document to the correct section/division/rule_set. The approver must have `canApprove()` for both the old and new context. Files are moved on the `public` disk via `Storage::disk('public')->move()` (same-disk atomic rename). A `DocumentStatusHistory` row records the move; optionally followed by an approval row in the same DB transaction.

**Resubmit:** Only the original uploader (or an admin) can resubmit a rejected document. No privilege required beyond ownership.

---

### Approval queue view (`GET /approvals`)

Three tabs:
- **Pending Approval** (amber) — `status = pending_approval` in the approver's scope. Non-approvers see nothing here.
- **Rejected** (red) — `status = rejected` in scope. Non-approvers see nothing here.
- **My Submissions** (slate) — own pending + rejected documents, regardless of approval privilege.

Features:
- Slide-over drawer with embedded PDF preview and metadata strip
- Bulk approve and bulk reject with shared Swal2 reason prompt (Pending tab only)
- Reclassify modal with cascading department → section/division OR rule_set selects, "Approve after reclassifying" checkbox, all options pre-loaded as JSON data islands (no AJAX round-trips during modal interaction)
- Sidebar amber badge: approvers see count of `pending_approval` in scope; non-approvers see own pending + rejected count; hidden when 0

---

### `->publishable()` scope applied everywhere

Added to all regular document list queries to exclude `pending_approval` and `rejected` documents from public view:
- `SectionController@show` — `$documentsQuery` and `$availableYears`
- `DivisionController@show` — `$rootDocuments`, amendments eager-load, `$totalCount`
- `RuleSetController@show` — `$rootDocuments`, amendments eager-load
- `SearchController@index` — `$documentsQuery`
- `FrontendController@dashboard` — `$baseQuery` and all status-specific stat sub-queries; dashboard also shows `pending_approval` count to admins/approvers

---

### `requires_approval` context toggle

Toggle added to section/division/rule_set edit forms. Gated by privilege:
- **Section edit** — visible to `section.head`, `department.head`, or admin
- **Division edit** — visible to admin, `department.head`, or `section.head`
- **Rule set edit** — visible to admin only

---

### `uploads_require_approval` user toggle

Added to user create/edit forms (admin-only). Labelled "Bulk Upload Mode — all uploads held for approval". When enabled, every document uploaded by that user starts in `pending_approval` regardless of target context.

Added to `StoreUserRequest` and `UpdateUserRequest` validation rules. Added to `UserManagementController@store` and `@update`.

---

### Files added in M28

- `database/migrations/2026_06_26_000001_add_requires_approval_to_sections_divisions_rule_sets.php`
- `database/migrations/2026_06_26_000002_add_uploads_require_approval_to_users.php`
- `app/Http/Controllers/ApprovalController.php`
- `app/Http/Requests/ApproveDocumentRequest.php`
- `app/Http/Requests/RejectDocumentRequest.php`
- `app/Http/Requests/ReclassifyDocumentRequest.php`
- `resources/views/approvals/index.blade.php`
- `resources/views/approvals/_table.blade.php`

### Files modified in M28

- `app/Models/Document.php` — `pending_approval`, `rejected` in `STATUSES`; `scopePublishable()` added
- `app/Models/User.php` — `documents.approve` in `PRIVILEGES`; `uploads_require_approval` in `$fillable`; `shouldRequireApproval()`, `canApprove()` helpers added
- `app/Models/Section.php`, `Division.php`, `RuleSet.php` — `requires_approval` in `$fillable`
- `app/Http/Requests/UpdateSectionRequest.php`, `UpdateDivisionRequest.php`, `UpdateRuleSetRequest.php` — `requires_approval` field added
- `app/Http/Requests/Admin/StoreUserRequest.php`, `UpdateUserRequest.php` — `uploads_require_approval` field added
- `app/Http/Controllers/DocumentController.php` — `store()` checks `shouldRequireApproval()`, sets `$initialStatus`, adapts flash message
- `app/Http/Controllers/SectionController.php` — `->publishable()` on document queries
- `app/Http/Controllers/DivisionController.php` — `->publishable()` on document queries
- `app/Http/Controllers/RuleSetController.php` — `->publishable()` on document queries
- `app/Http/Controllers/SearchController.php` — `->publishable()` on document query
- `app/Http/Controllers/FrontendController.php` — `->publishable()` base query; `pending_approval` stat added
- `app/Http/Controllers/Admin/UserManagementController.php` — `uploads_require_approval` in `store()` and `update()`
- `resources/views/components/sidebar.blade.php` — Approval Queue nav link with amber badge
- `resources/views/sections/edit.blade.php` — `requires_approval` toggle
- `resources/views/divisions/edit.blade.php` — `requires_approval` toggle
- `resources/views/rule_sets/edit.blade.php` — `requires_approval` toggle
- `resources/views/admin/users/create.blade.php` — `documents.approve` checkbox; `uploads_require_approval` toggle
- `resources/views/admin/users/edit.blade.php` — same
- `CLAUDE.md` — modules table, privileges, users schema, route map, upload flow, Maker-Checker section, architecture decisions 25–28
- `README.md` — Core Features, schema tables, route map, users table description
- `ROADMAP.md` — section 2.1 updated to ✅ Implemented with actual design

---

## M29 — Folders (Patravali / Case Files) Module (COMPLETED 2026-07-04)

Adds a **physical file / dossier** concept to the document vault. A `Folder` (Patravali in government parlance) is a named container grouping all correspondence and orders related to a specific matter — court case, license dispute, audit query, service matter, etc. Distinct from `Section` and `Division` (which are organisational units) — a folder is a filing concept.

---

### Design

**What a Folder is:**
- Named dossier under a Section (or optionally a Division)
- Has its own URL, show page, upload modal, and document list
- Visibility: `public` | `authenticated` — gates the folder page itself; contained docs keep their own visibility
- `requires_approval` toggle — same policy mechanism as section/division/rule_set
- Slug immutable after creation (vault paths depend on it)
- Not nested — folders do not contain sub-folders (YAGNI)

**Where folders live:**
- Section-level: `document_vault/{level}/{dept}/{wing?}/{section}/folders/{folder}/`
- Division-level: `document_vault/{level}/{dept}/{wing?}/{section}/divisions/{division}/folders/{folder}/`

**Document taxonomy extended from three-way to five-way:**

| Context | `section_id` | `division_id` | `rule_set_id` | `folder_id` |
|---|---|---|---|---|
| Direct section doc | NN | null | null | null |
| Division doc | NN | NN | null | null |
| Rule-set doc | null | null | NN | null |
| Section-folder doc | NN | null | null | NN |
| Division-folder doc | NN | NN | null | NN |

**Archive cascade:** `FolderController@destroy` soft-deletes all contained documents (with `DocumentStatusHistory` rows + `ManagesDocumentFiles::archiveFiles()` per doc) inside the same `DB::transaction()`, then soft-deletes the folder. Mirror of `RuleSetController@destroy`.

**Amendment chains within folders:** Existing `parent_id` on `documents` works unchanged. Upload modal parent-selection lists root docs within the same folder.

**Scope permissions:** `canUploadTo(Folder $folder)` resolves the folder's owning section or division and delegates to the existing section/division scope check. No new scope level added.

**Search:** New Folders block (teal-accented) added to `search/index.blade.php`. `SearchController` adds `Folder::where('name', 'LIKE', ...)->orWhere('description', 'LIKE', ...)` query; guests see only `visibility = 'public'` folders. Cap: 20 folders.

---

### New routes

**Folder pages:**
```
POST   /departments/{level}/{dept}/sections/{section}/folders                     → folders.store (section)
GET    /departments/{level}/{dept}/sections/{section}/folders/{folder}            → folders.show (section)
PATCH  /departments/{level}/{dept}/sections/{section}/folders/{folder}            → folders.update (section)
DELETE /departments/{level}/{dept}/sections/{section}/folders/{folder}            → folders.destroy (section)

POST   /departments/{level}/{dept}/sections/{section}/divisions/{division}/folders         → folders.divisions.store
GET    /departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder} → folders.divisions.show
PATCH  /departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder} → folders.divisions.update
DELETE /departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder} → folders.divisions.destroy
```

**Folder document routes (section folder):**
```
GET    /documents/{level}/{dept}/{section}/folders/{folder}/{doc}        → documents.folders.show
PATCH  /documents/{level}/{dept}/{section}/folders/{folder}/{doc}        → documents.folders.update
DELETE /documents/{level}/{dept}/{section}/folders/{folder}/{doc}        → documents.folders.destroy
GET    /documents/{level}/{dept}/{section}/folders/{folder}/{doc}/pdf    → documents.folders.pdf
GET    /documents/{level}/{dept}/{section}/folders/{folder}/{doc}/review → documents.folders.edit
```

**Folder document routes (division folder):**
```
GET    /documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}        → documents.divisions.folders.show
PATCH  /documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}        → documents.divisions.folders.update
DELETE /documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}        → documents.divisions.folders.destroy
GET    /documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}/pdf    → documents.divisions.folders.pdf
GET    /documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}/review → documents.divisions.folders.edit
```

---

### Implementation (2026-07-04)

Built end-to-end against the design above. Ran migrations against local MariaDB; verified via a rollback-wrapped transactional smoke test (model relations, slug scoping per context, `canUploadTo`/`shouldRequireApproval` resolution through a folder to its section/division, and a real HTTP round-trip through the router — folder show pages, both folder-doc show pages, `sections.show`/`divisions.show` with the new `$folders` data, and search — before rolling everything back).

**Added:**
- `database/migrations/2026_07_04_000001_create_folders_table.php`, `..._000002_add_folder_id_to_documents_table.php`
- `app/Models/Folder.php` — `HasUnicodeSlug`, `getRouteKeyName('slug')`, `uniqueSlugForSection()`, `uniqueSlugForDivision()`, relations
- `app/Http/Controllers/FolderController.php` — section + division variants (create/store/show/edit/update/destroy), shared `renderShow()`/`doUpdate()`/`doDestroy()` helpers, `ManagesDocumentFiles` archive cascade
- `app/Http/Requests/StoreFolderRequest.php` / `UpdateFolderRequest.php` — `authorize()` via `canUploadTo()`, matching upload scope rather than division's stricter section.head/department.head/admin gate
- `resources/views/folders/{show,create,edit,_doc_row}.blade.php`

**Modified:**
- `app/Providers/AppServiceProvider.php` — `Route::bind('folder', ...)` scoped to section + optional division
- `routes/web.php` — 8 folder page routes + 10 folder-doc routes, matching the CLAUDE.md route map exactly
- `app/Http/Controllers/DocumentController.php` — folder branch in `store()`; 10 new section/division-folder doc methods (show/pdf/edit/update/destroy × 2)
- `app/Http/Requests/StoreDocumentRequest.php` — `folder_id` field + context resolution in `authorize()`
- `app/Http/Requests/DeleteDocumentRequest.php`, `app/Models/Document.php` (`folder_id`, `folder()`, `uniqueSlugForFolder()`), `app/Models/Section.php`/`Division.php` (`folders()`), `app/Models/User.php` (`canUploadTo()`/`shouldRequireApproval()` resolve `Folder` → its division or section)
- `app/Http/Controllers/SectionController.php` / `DivisionController.php` — load `$folders` with counts for the show page
- `app/Http/Controllers/SearchController.php` — folder name/description match + `$folders` block
- `resources/views/sections/show.blade.php` / `divisions/show.blade.php` — folder cards grid + "Add Folder" button
- `resources/views/documents/show.blade.php` — `$isSectionFolderDoc`/`$isDivisionFolderDoc` flags, folder breadcrumb + sidebar link, shared `$linkForDoc()` closure for parent/amendment cross-links
- `resources/views/documents/edit.blade.php` — same context flags added; this also fixed a **pre-existing bug** where division-doc edits silently posted to the wrong (section) update route, since this view had never been updated for M23's division docs
- `resources/views/documents/index.blade.php`, `resources/views/search/index.blade.php` — folder-aware routing priority and context-name fallback chain

**Bug caught during verification:** `documents/show.blade.php`'s `$linkForDoc` closure captured `$division` via `use (...)`, which throws "Undefined variable" for section-folder docs (no division in scope) even though it's guarded by `isset()` everywhere else — `isset()` doesn't protect a `use()` capture. Fixed by normalizing `$ruleSet`/`$division`/`$folder` to `null` at the top of the view.

**Not done (deliberately out of scope):** `documents/trash.blade.php` and `ApprovalController`'s `context_name` fallback chains still stop at `division?->name ?? section?->name ?? ruleSet?->name` without a folder branch — cosmetic label only in the archive/approval-queue UI, not a routing or access-control gap (folder documents already archive/approve correctly through the generic `Document` flows both modules already use).

---

## M30 — Text Extraction & Markdown Conversion Pipeline (COMPLETED 2026-07-13)

**Goal:** Convert a document's original PDF into reviewable Markdown, with OCR available as an
on-demand human-triggered option rather than an automatic step — first real use of the
`markitdown`/Tesseract toolchain `composer.json`/`CLAUDE.md` had been describing since project
inception, but nothing had ever written to `markdown_path` until this milestone.

**Design iteration — automatic OCR fallback was built, tested, and removed:** the first version
auto-dispatched OCR whenever the text-layer pass looked low-quality. Two concrete problems
surfaced in testing, not just a preference: (1) a single serial queue worker meant one slow OCR
job blocked every other document behind it — the literal cause of a "stuck on converting"
complaint; (2) running OCR on an already-good text layer actively corrupts correct text —
confirmed by testing Tesseract against `Haryana Excise Policy 2025-27.pdf` page 1 (already
cleanly handled by the text-layer pass): "150 meters" silently became "50 meters" in four
separate places. Redesigned to a "man in the middle" model: text-layer extraction always runs
first and is always shown to a reviewer (even if low quality, flagged rather than hidden); OCR
only runs when a human explicitly clicks "Run OCR-Based Extraction."

**Added:**
- `app/Jobs/ConvertDocumentToMarkdown.php` — `pdfminer.six` low-level extraction
  (`extract_pages`/`LTChar`/`LTTextLine` via `resources/python/pdf_structure_extractor.py --mode
  pdf`, run through the markitdown-provisioned venv) for font-size/bold structure detection;
  bypasses markitdown's own plain-text-only `pdfminer.high_level.extract_text()` converter.
  Quality gate (`isGoodQuality()`) catches two independent failure modes: `(cid:\d+)` glyph-ID
  fallback tokens (over 5 occurrences — legacy non-Unicode Devanagari fonts with no ToUnicode
  CMap) and near-empty text relative to page count. Writes Markdown and sets
  `metadata.needs_ocr_review` either way — never silently drops a bad result.
- `app/Jobs/RunOcrExtraction.php` — Tesseract (`hin+eng`, hOCR mode for per-line `x_size`
  heading detection) via `pdftoppm` rasterization into a per-job private-disk temp dir, cleaned
  up in a `finally` block. Never auto-dispatched; only reachable via `POST
  /documents/{id}/convert-ocr`.
- `app/Http/Requests/UpdateDocumentMarkdownRequest.php` — admin-only `authorize()`, backs the
  Compare & Verify modal's Save & Verify action.
- `resources/python/pdf_structure_extractor.py` — shared structure-extraction script, two modes
  (`pdf` for the text-layer pass, `hocr` for the OCR pass).
- `resources/views/documents/bulk-upload.blade.php` — multi-context (section/division/folder/
  rule-set) bulk upload page, server-computed scope tree (`DocumentController::
  buildUploadScopeTree()`) so the picker never offers an out-of-scope destination; optional
  auto-convert per file.
- `resources/views/documents/pipeline.blade.php` — table of every document not yet verified/
  archived, status tabs, live 5s polling on in-flight rows.
- `composer.json` — `innobrain/markitdown`, `erusev/parsedown`; `post-autoload-dump` runs
  `markitdown:install` automatically.

**Modified:**
- `app/Http/Controllers/DocumentController.php` — `convert()`/`convertOcr()`/
  `conversionStatus()`/`updateMarkdown()`/`discardMarkdown()`, plus `bulkUploadForm()`/
  `pipeline()`/`buildUploadScopeTree()`; `store()` now returns `document_id` in its JSON
  response so the bulk-upload page can chain an auto-convert call.
- `routes/web.php` — `documents.bulk-upload`, `documents.pipeline`, `documents.convert`,
  `documents.convert-ocr`, `documents.convert-status`, `documents.markdown.update`,
  `documents.markdown.discard`.
- `resources/views/documents/show.blade.php` — Compare & Verify split-pane modal (deferred
  `data-src` on the PDF iframe, fixing a zoom bug where a hidden iframe never re-applied
  `#view=FitH`); one-time Discard Draft action; Run OCR trigger moved inside the modal instead
  of a second page-level banner; Convert button icon swaps to a spinning loader instead of
  disappearing or spinning the markdown logo icon; Markdown tab/card hidden entirely until
  `status = 'verified'`.
- `resources/views/components/sidebar.blade.php` / `header.blade.php` — "Pipeline" nav link
  (unscoped live count badge) and "Bulk Upload & Convert" link (scoped to `uploadScope() !==
  'none'`) replacing prior placeholder/"Coming soon" entries.
- `resources/views/frontend/index.blade.php` — dashboard "In Review"/"Processing" stat tiles
  now link to the pipeline page.

**Known gap, not yet fixed:** `convert()`/`convertOcr()`/`discardMarkdown()` are gated
`isAdmin()` directly in the controller. The Bulk Upload page's auto-convert checkbox is
available to any user with upload scope (not just admins) and silently no-ops for non-admins —
the `fetch()` call 403s and is swallowed by a `.catch()` that only logs to console, with no
user-facing indication that conversion never started. Tracked in `SECURITY.md` Pass 4 and in
`CLAUDE.md`'s pipeline section; not fixed in this pass since it's a pre-existing gate this
milestone didn't introduce, just newly exposed by the bulk-upload UI.

**Research spike, no code change:** two on-premise OCR alternatives to Tesseract were installed
and tested (PaddleOCR, EasyOCR) against the same real problem document. PaddleOCR rejected —
its default Hindi preset tried to consume the entire host's RAM for a single page, and pinning
it to a lighter model crashed with a Paddle-inference version bug. EasyOCR showed a genuine
accuracy improvement on the exact known failure modes (correct Devanagari-numeral years vs.
Tesseract's `1904`→`4904`-style corruption, no conjunct/halant artifacts, no English-word
hallucination) at a workable ~700MB-steady/~4.4GB-peak memory cost — promising, but not
integrated; needs a multi-page test and an explicit sign-off given the PyTorch dependency
weight before any production change. Full write-up in `OCR_RESEARCH.md`.

## M30.1 — Rendered Markdown Viewing (follow-up, 2026-07-13)

**Goal:** modern browsers give PDFs native zoom/print/scroll for free; Markdown has no such
native rendering, so both the verified-document view and the Compare & Verify editor needed an
explicit "show it formatted, not as raw asterisks" path — the same expectation GitHub and VS Code
set.

**Added:**
- `marked@13` (jsDelivr) — page-scoped to `documents/show.blade.php` via `@push('scripts')`, not
  loaded globally in `head.blade.php`, since it's only needed on this one page.

**Modified:**
- `resources/views/components/head.blade.php` — Tailwind Play CDN URL gained `?plugins=typography`.
  The verified-document Markdown view already had `prose prose-sm dark:prose-invert` classes on
  its container, but the plugin wasn't loaded, so they were inert — Parsedown's output rendered as
  real `<strong>`/`<h2>` tags with no GitHub-style typography spacing/font treatment until this fix.
- `resources/views/documents/show.blade.php` — Compare & Verify modal's Markdown pane gained an
  Edit/Preview tab pair. Preview renders the live textarea content via `marked.parse()` into a
  `prose` div, passed through the same `href`/`src` `javascript:`/`data:`/`vbscript:` strip used
  server-side for the verified view (defense in depth on an admin-only, never-persisted preview).
  Editing itself stays a plain textarea — no CodeMirror/Monaco; the actual complaint was "I can't
  see formatting while reviewing," not "I need a code editor."

---

## M31 — Policy Taxonomy (COMPLETED 2026-07-15)

**Goal:** a first-class Policy taxonomy for the state/government's actual named policies (UP
Excise Policy, UP Cane Policy, UP Sugar Policy, UP Import/Export Policy — and other states'
published policies for reference), distinct from the existing Rule Set taxonomy which already
handles subject-specific rules (Bar, Beer, Bottling, Distillery, Vending) extracted from those
same policy documents for standalone browsing. Design went through two revision rounds with the
user before coding — full history preserved in `POLICY_TAXONOMY_PLAN.md` until this milestone
entry and the `CLAUDE.md`/`README.md` updates fully absorbed it (that file is deleted as of this
commit).

**Design decision — reuse `RuleSet`, don't build a parallel model:** a new `kind` enum column
(`rules` | `policy`) on `rule_sets` discriminates the two. Same controller (`RuleSetController`),
same five `DocumentController` rule-set-document methods, same Blade views — branching only on
the two things that genuinely differ: permission (`canManagePolicy()` instead of the generic
`canUploadTo()`/admin-only rules gate) and policy-only columns. This is structurally why Policy is
department-level-only "for free" — `RuleSet` never has a section/division FK, so neither does
Policy, and it works automatically for every department including ones created after this
milestone shipped.

**Schema (`2026_07_15_000001_add_policy_fields_to_rule_sets_table.php`, alter-in-place, no new
table):** `kind` enum default `rules`; `state`/`policy_type` string nullable (policy-only, dropdown
values from `RuleSet::STATES`/`POLICY_TYPES`, or a sanitized free-text value when `Other` is
picked); `effective_start_date`/`effective_end_date` date nullable (descriptive only); `policy_status`
enum (`current` default | `superseded`); `previous_policy_id` self-referencing FK, `nullOnDelete`.
Composite index `(department_id, kind, state, policy_type, policy_status)` backs the supersession
lookup.

**Supersession — the core new behavior, not just a relabeled Rule Set:** creating a policy for a
department + state + policy_type combination that already has a `current` row flips that old row
to `superseded` and sets the new row's `previous_policy_id`, inside the same `DB::transaction()`
as the create in `RuleSetController::store()` — no separate "start new period" endpoint, since
"first policy ever for this line" and "new year replaces the current one" share every field and
differ only in whether a matching row already exists. Superseded policies are **never** deleted or
hidden: they stay fully browsable, keep their original URL working forever (a pending court case
citing an old policy must keep resolving), and can still receive amendments — dormant means "not
the default citation," not "frozen." `RuleSet::supersededBy()`/`previousPolicy()` are the two
relation directions. The create form shows a SweetAlert2 confirmation before a supersession-causing
submit, so a department head can't accidentally demote a still-relevant policy without realizing.

**Controlled vocabularies, not free text:** `RuleSet::POLICY_TYPES` (`excise_policy`, `cane_policy`,
`sugar_policy`, `import_policy`, `export_policy`, `other`) and `RuleSet::STATES` (28 states + 8
union territories, hardcoded). Both fields are `<select>`-only in the UI, with an `Other` entry
that reveals a `state_other`/`policy_type_other` text input — validated with this codebase's
standard Unicode-safe pattern (`\p{L}\p{M}\p{N}\p{P}\p{Z}\s`), `strip_tags`/`trim` sanitized,
`max:100`, and the literal sentinel `"other"` is swapped for the sanitized value before persisting
(`StoreRuleSetRequest`/`UpdateRuleSetRequest` override the no-arg `validated()` call to do this).
**Correction mid-build:** an earlier draft of `POLICY_TYPES` incorrectly included Bar/Beer/
Vending/Bottling/Distillery — the user caught this: those are Rules (already the existing
`RuleSet` `kind=rules` taxonomy), not Policies, since a policy document covers a whole subject
area and those are narrower rule extracts pulled out of it for easier reading, not standalone
named policies themselves. Fixed before any further schema/UI work referenced the wrong list.

**Effective period is descriptive, not authoritative:** `effective_start_date`/`effective_end_date`
are shown on the form but `policy_status` is the only field the app trusts for "is this the policy
to cite" — a policy's stated end date rarely lines up exactly with when it's actually replaced.
Entered via **Cleave.js** (CDN, page-scoped to the policy create/edit view, not global) masked
`DD-MM-YYYY` text inputs feeding a hidden ISO field, chosen over a native `<input type="date">`
per explicit user preference — consistent with this codebase already having hit native-control
dark-mode styling friction once before (the 2026-07-14 OCR engine dropdown fix).

**Permissions — stricter than generic upload scope:** `User::canManagePolicy(RuleSet $policySet)`
= `isAdmin()` or (`hasPrivilege('department.head')` and matching `department_id`).
`canManagePolicyForDepartment(Department $department)` is the same check before a policy `RuleSet`
exists yet (create/store screen). Deliberately not `canUploadTo()`, whose generic `department`
scope would let any bare-`department_id` user manage policy without the `department.head`
privilege. Wired into: `StoreRuleSetRequest`/`UpdateRuleSetRequest::authorize()`;
`StoreDocumentRequest`/`UpdateDocumentRequest`/`DeleteDocumentRequest` (branch when the resolved
`RuleSet` context is `kind=policy`); a new `DocumentController::canManageDocument()` helper backing
`convert`/`convertOcr`/`revertOcr`/`discardMarkdown`; `UpdateDocumentMarkdownRequest::authorize()`.
Client-side, `documents/show.blade.php` gets a `$canManageDoc` variable and `rule_sets/show.blade.php`
gets `$canManage`, both gating Edit/Convert/OCR/Verify/Discard/Delete controls identically to the
server-side checks. **Pitfall explicitly avoided** (flagged by the still-open `SECURITY.md` H-03
bug in `UpdateFolderRequest`): `policy_status`/`previous_policy_id` are never included in
`UpdateRuleSetRequest::rules()`, so they can never be smuggled into `validated()` via a raw PATCH —
only the transaction inside `store()` may set them.

**Routing:** every `/rules` route block in `routes/web.php` gets a sibling `/policy` block, same
controller methods, disambiguated via a `kind` route default. **Bug caught during verification:**
`->defaults('kind', 'policy')` cannot be chained on `Route::prefix()->name()->group()` —
`RouteRegistrar::group()` returns void/non-chainable, only individual `Route::get()`/`post()`/etc.
calls return a `Route` instance that supports `->defaults()`. Fixed by moving `->defaults()` onto
each individual route definition inside every group, both new and existing.

**Views:** `rule_sets/create.blade.php` — "Add \[Dept\]'s UP Policy" (state hidden, defaults to
Uttar Pradesh) vs. "Add Other State's Policy" (reveals the state dropdown) toggle buttons;
`policy_type`/`state` dropdowns with `Other` fallback; Cleave.js date pair; supersession-warning
SweetAlert2 confirm. `rule_sets/edit.blade.php` — same fields editable, plus a current/superseded
status chip. `rule_sets/show.blade.php` — amber "Superseded — kept for historical reference only"
banner with a link to the current policy when applicable; upload modals relabel to "Upload Policy
Document"/"Upload Amendment" when `kind=policy`, reusing the existing two-modal pattern and
`makeQueue()` JS factory unchanged. `department/show.blade.php` — new "Policies" panel (current
only) plus a collapsed `<details>` "Historical Policies" disclosure, both server-filtered via new
`RuleSet::scopeCurrentPolicy()`/`scopePolicy()` query scopes in `DepartmentController::show()`.
`documents/bulk-upload.blade.php` — the existing "Rule Set" picker tab (relabeled "Rule Set /
Policy") now includes policy containers merged into the same `<select>` (prefixed `[Policy]`,
superseded ones suffixed `(Superseded)`) rather than a duplicate tab/mode, since both submit via
`rule_set_id` identically; `DocumentController::buildUploadScopeTree()` filters the policy half of
that list through `canManagePolicyForDepartment()` rather than the generic upload `$scope`.

**Verification performed before considering this done:** `php artisan migrate --pretend` (clean
SQL), `php -l` on every touched PHP file, `Blade::compileString()` on every touched view, `php
artisan route:list` confirming the full `/policy` route tree registered symmetrically with `/rules`,
a tinker-driven create → second-create → supersede → verify cycle (rolled back, no residue), and a
real `php artisan serve` + `curl` round-trip creating an actual policy row, confirming it rendered
correctly on both its own show page and the department page's new Policies panel, then deleting the
test fixture — `storage/logs/laravel.log` line count confirmed unchanged (no new errors) across the
whole verification pass.

**Files changed:** `database/migrations/2026_07_15_000001_add_policy_fields_to_rule_sets_table.php`
(new) · `app/Models/RuleSet.php` · `app/Models/User.php` · `routes/web.php` ·
`app/Http/Controllers/RuleSetController.php` · `app/Http/Controllers/DocumentController.php` ·
`app/Http/Controllers/DepartmentController.php` · `app/Http/Requests/StoreRuleSetRequest.php` ·
`app/Http/Requests/UpdateRuleSetRequest.php` · `app/Http/Requests/StoreDocumentRequest.php` ·
`app/Http/Requests/UpdateDocumentRequest.php` · `app/Http/Requests/DeleteDocumentRequest.php` ·
`app/Http/Requests/UpdateDocumentMarkdownRequest.php` ·
`resources/views/rule_sets/{create,edit,show,_doc_row}.blade.php` ·
`resources/views/department/show.blade.php` · `resources/views/documents/show.blade.php` ·
`resources/views/documents/bulk-upload.blade.php` · `CLAUDE.md` · `README.md` ·
`POLICY_TAXONOMY_PLAN.md` (deleted, folded into the above).

---

## M31.1 — Policy Taxonomy: post-merge fixes (2026-07-15)

Real-world use of the M31 create form (`/departments/{level}/{dept}/policy/create`) surfaced four
issues within the first session after merge — three UI bugs and one authorization/data-quality
gap. All fixed directly on `main` (the feature branch was already merged; these were treated as
immediate follow-up fixes, not a new feature branch).

**Bug 1 — `ParseError`, page fully down.** `route(\"departments.{$kind}.store\", ...)` inside
`action="{{ ... }}"` used backslash-escaped quotes — invalid PHP syntax outside a string literal,
since `{{ }}` is already a raw-PHP context and doesn't need the surrounding HTML attribute's
quoting escaped. 500'd on every load. Fixed to plain double quotes, matching the already-working
pattern in `rule_sets/show.blade.php`/`_doc_row.blade.php`. **Gap in M31's own verification
claim:** M31's write-up above states `Blade::compileString()` was run on every touched view, which
would have caught this — either that check didn't actually cover this file, or the escaping was
introduced after that check ran. Worth treating "ran a compile check" as verified-per-file, not
verified-per-commit, next time.

**Bug 2 — dark-mode toggle contrast.** The "Add UP Policy"/"Add Other State's Policy" buttons'
click handler toggled base utility classes (`bg-indigo-600`, `text-white`, `bg-white`,
`text-slate-500`) but never touched the `dark:` variants baked into the static markup. In dark
mode, `.dark .dark\:bg-slate-800` (two classes) outranks `.bg-indigo-600` (one class) on CSS
specificity — so "Other State" never visibly highlighted when selected, and "UP" reverted to a
plain white background (not a dark one) once deselected. Fixed by swapping the *entire*
active/inactive class list per button (new `applyToggleState()` helper) instead of toggling
individual utilities in place.

**Bug 3 — form width.** `max-w-2xl` (the shared Rule Set/Policy form width) left a lot of dead
space for the Policy variant specifically, which has more fields than the plain Rule Set form and
sits on a page with no sidebar-type content competing for width. Widened to `max-w-4xl` for the
Policy branch only; the plain Rule Set create form is untouched at `max-w-2xl`.

**Bug 4 — policy type not actually scoped to department.** `RuleSetController::create()` already
computed a `$defaultPolicyType` from the department's slug (`excise` → `excise_policy`, etc.), but
the create form's dropdown still listed *every* `RuleSet::POLICY_TYPES` entry — the computed
default only pre-selected one option in a list that still let an Excise upload be filed as a Cane
Policy by mistake. The dropdown now renders only the matched type + `other` when
`$defaultPolicyType` resolves (falls back to the full list only if the department's slug matches
none of the three heuristics) — a department can only upload its own named policy through the
controlled options; anything genuinely different (Import/Export Policy, etc.) goes through the
existing `other` free-text field. That free-text value is now also title-cased server-side
(`Illuminate\Support\Str::title()`, applied in `prepareForValidation()` in both
`StoreRuleSetRequest` and `UpdateRuleSetRequest`, before the "other" swap in `validated()`) so
`"import POLIcy"` and `"Import policy"` both persist as `"Import Policy"` — otherwise the `other`
escape hatch would have quietly reintroduced the exact casing-fragmentation problem the controlled
vocabulary was built to prevent.

**Verification performed:** `php artisan view:clear` + a real `php artisan serve` + `curl`
round-trip against `/departments/dept/excise/policy/create` after each fix (confirmed 200 and
correct rendered markup — locked `policy_type` `<select>` showing only `excise_policy` + `other`
for the Excise department); `php -l` on both touched Form Request files; a tinker call confirming
`Str::title('import POLIcy')` → `'Import Policy'`.

**Files changed:** `resources/views/rule_sets/create.blade.php` ·
`app/Http/Requests/StoreRuleSetRequest.php` · `app/Http/Requests/UpdateRuleSetRequest.php` ·
`README.md` · `claude.md`.

## M31.2 — Policy Taxonomy: security audit, Pass 5 (2026-07-15)

Went through `RuleSetController` and the two Policy Form Requests specifically looking for
authorization gaps introduced or widened by the Policy Taxonomy work — full findings in
`SECURITY.md` Pass 5. One real HIGH finding, three confirmed-clean checks.

**H-04 (HIGH, fixed) — `create()`/`edit()`/`destroy()` had no authorization check.**
`store()`/`update()` are correctly gated by `StoreRuleSetRequest`/`UpdateRuleSetRequest::authorize()`
(`canManagePolicyForDepartment()`/`canManagePolicy()` for `kind=policy`, `isAdmin()` for
`kind=rules`), but the other three controller methods call no `FormRequest` and had nothing beyond
the route's blanket `auth` middleware — meaning any authenticated user, regardless of role or
department, could view any department's create/edit forms and, critically, **delete any rule set
or policy outright** (cascading to every document under it, soft-deleted with an audit trail but
still fully removed from the live tree). This bug predates Policy Taxonomy — `destroy()` has been
unguarded since the original Rule Sets feature — but this module raised the stakes by putting
named legal policy documents behind the same unguarded route and opening `create()`/`edit()` up to
"every department, existing or future." Fixed with a private `authorizeManage()` helper on
`RuleSetController` mirroring the exact same check as the two Form Requests, called as the first
line of all three methods — before any view render or database mutation.

**Confirmed clean:** mass-assignment of `policy_status`/`previous_policy_id` (both `$fillable`,
but never reachable from `validated()` since neither is in either Form Request's `rules()` — only
the internal supersession logic in `store()` writes them, with hardcoded values); XSS via the
`policy_type_other`/`state_other` free-text fields (stripped at write time via `strip_tags()`,
escaped at render time via `{{ }}` — no `{!! !!}` anywhere in `resources/views/rule_sets/`); the
department-scoped policy-type dropdown lock added in M31.1 is enforced server-side too, not just a
client-side `<select>` restriction (`StoreRuleSetRequest::rules()`'s `Rule::in()` still validates
against the full `POLICY_TYPES` vocabulary regardless of what the view renders, so the "lock" is a
UX convenience on top of a real server-side allow-list, not the only thing standing between a user
and an arbitrary policy type).

**Verification:** exercised the `authorizeManage()` fix directly via `php artisan tinker` against
real `User` records — a non-admin user unrelated to the target department got `HTTP 403` calling
`edit()` on an in-memory (unsaved) `RuleSet`, and an admin user was still let through. `destroy()`'s
guard wasn't exercised live (any real invocation would delete real data) — verified by code review
that the same helper runs as the first line, before `DB::transaction()`, in every case.

**Files changed:** `app/Http/Controllers/RuleSetController.php` · `SECURITY.md` · `claude.md`.

**Follow-up (same day) — H-05, same gap found codebase-wide.** User asked directly whether other
parts of the app had the same flaw. Correct call: `store()`/`update()` across the codebase are
uniformly gated by `FormRequest::authorize()`, but `create()`/`edit()`/`destroy()` — which don't
take a `FormRequest` — had the identical no-check gap in `DepartmentController`,
`SectionController`, `DivisionController`, `FolderController` (both section- and division-scoped
folder variants), and `DocumentController`'s five `edit*Doc()` review-form methods. Most severe:
any authenticated user, regardless of role, could delete any department/section/division/folder
outright (cascading to every document beneath it). Fixed with the same pattern as H-04 — one
private authorization helper per controller, mirroring that controller's own paired
`FormRequest::authorize()` logic exactly, called first-line in every previously-unguarded method.
Verified live via `php artisan tinker` for `Department`/`Section` (403 for unrelated non-admin,
pass for admin); `Division`/`Folder`/`Document` had no fixtures in the local dev DB to exercise
live, verified by code review instead (identical helper shape, identical placement, logic
transcribed directly from each `FormRequest`). Full writeup: `SECURITY.md` H-05.

**Files changed:** `app/Http/Controllers/DepartmentController.php` ·
`app/Http/Controllers/SectionController.php` · `app/Http/Controllers/DivisionController.php` ·
`app/Http/Controllers/FolderController.php` · `app/Http/Controllers/DocumentController.php` ·
`SECURITY.md`.

**Second follow-up (same day) — process fix so this class of bug doesn't recur.** User flagged
this as a major flaw and asked for it to be documented so it never repeats, plus any further
schema/controller cleanup needed. Two concrete actions, not just a note:

1. `claude.md`'s "Auth & access control" section now leads with an explicit rule: every
   controller method that mutates or reveals scoped data must be authorized itself —
   `middleware('auth')` never implies per-record authorization — with H-04/H-05 named as the
   cautionary example and the exact fix pattern (`authorizeManage()` helper, called first-line,
   mirroring the sibling `FormRequest::authorize()`) spelled out so the next controller gets it
   right the first time.
2. Deleted four dead `app/Policies/*.php` stub classes (`DocumentPolicy`, `DepartmentPolicy`,
   `SectionPolicy`, `RuleSetPolicy`) — `make:policy` boilerplate that always returned `false`,
   was never registered with Laravel's `Gate`, and was never called anywhere. Confirmed via grep
   (zero references outside their own files) before deleting. Left in place, these were a real
   risk in their own right: a future developer skimming `app/Policies/` could easily assume
   Laravel's Policy/Gate system was the actual authorization mechanism here, when every real
   check lives in hand-written `FormRequest`/controller code. Half-wired-looking protection is
   worse than an obvious gap.

**Explicitly not done, flagged rather than skipped:** an automated regression test walking every
management route as a low-privilege user and asserting `403` would be the strongest possible
guard against a repeat — but `tests/Pest.php` has `RefreshDatabase` commented out and the
`Department`/`Section`/`RuleSet` factories are empty `make:factory` stubs with no fields, so
building that properly means standing up the test database wiring from scratch first. Recommended
as the next step if this needs a stronger guarantee than documentation.

**Files changed:** `claude.md` · `SECURITY.md` · deleted `app/Policies/DocumentPolicy.php`,
`app/Policies/DepartmentPolicy.php`, `app/Policies/SectionPolicy.php`,
`app/Policies/RuleSetPolicy.php`.

---

## M32 — Docling Structure Detection, Phase 1 (COMPLETED 2026-07-15)

Adds automatic structure/layout detection ahead of the existing text-extraction pipeline
(M30/M31), addressing a problem M30 didn't solve: even a good text-layer or OCR pass still
flattens tables into run-on paragraphs and loses heading hierarchy. Evaluated hands-on against
real other-state excise policy PDFs before building anything — see `STRUCTURE_RESEARCH.md` for
the full write-up (four real Docling CLI runs, the RapidOCR-defaults-to-Chinese bug found, the
`--force-ocr` impracticality finding at real page counts, and the Kruti Dev legacy-font
discovery made along the way).

### What shipped

- **`config/docling.php`** — new registry, parallel to `config/ocr.php`'s main-OCR-engine
  registry: Docling's venv path, default structure-OCR engine (`tesseract`), and the three
  engines Docling can actually call directly (`tesseract`/`easyocr`/`rapidocr` — confirmed it
  cannot use Paddle or Surya as a backend), each with the `hi`/`hin+eng`-style lang code its
  CLI expects.
- **`ConvertDocumentToMarkdown` gains a Pass 0** (`runDoclingStructureAnalysis()`), run
  automatically before the existing text-layer pass, for every document, every time. Invokes
  Docling's own CLI (`docling convert --to json --ocr-engine ... --ocr-lang ...`), parses its
  JSON export (`texts[].label === 'section_header'` for headings, `tables[].data.table_cells[]`
  for cell-level data — schema confirmed by inspecting a real 110MB+ export during evaluation),
  and trims it down to a compact structure map: headings + table cells + bounding boxes only.
  Non-fatal — any Docling failure (bad venv, timeout, malformed output) is logged and the rest
  of the job proceeds exactly as before; structure detection never blocks text extraction.
- **Storage: file, not database.** The compact map is written as a `{slug}.structure.json`
  sibling file on the `public` disk (same convention as the existing `.pre-ocr.md` backup) —
  the raw Docling export (100MB+ per document) is deleted immediately after parsing, same
  "never retain large intermediates" discipline as `RunOcrExtraction`'s `ocr_tmp/` directory.
  `discardMarkdown()` now deletes `.structure.json` alongside the Markdown draft it was produced
  with, and clears the four new `structure_*` metadata keys.
- **New route** `GET /documents/{id}/structure` → `DocumentController::structureJson()`, gated
  with the same `canManageDocument()` helper used by `convert()`/`convertOcr()`/
  `discardMarkdown()` — deliberately not shipped as an unscoped read the way `convert-status`
  (L-04) currently is; this is new code written immediately after M31.2's H-04/H-05 fixes and
  does not repeat that pattern.
- **UI** — a small informational strip ("Structure: N headings, M tables detected") on
  `documents/show` once analysis completes, with a "View raw JSON" link for admins/
  policy-managers; a matching small icon badge on the Pipeline monitor page. Deliberately
  informational only this round — **not** merged into the rendered Markdown yet.
- An engine-choice `<select>` (Tesseract/EasyOCR/RapidOCR) was initially placed next to the
  Convert button, then **removed on review** — spotted as inconsistent with the codebase's own
  established pattern of never surfacing an OCR-engine choice until there's a result to react to
  (the main OCR-engine dropdown in Compare & Verify only appears *after* conversion). `convert()`
  always uses `config('docling.default_ocr_engine')` now; the `structure_engine` request param
  and `config/docling.php` registry remain in place for a future "re-analyze with a different
  engine" control if one is ever built, just not exposed as a pre-conversion choice.
- **Kruti Dev / legacy-font detection** — `pdf_structure_extractor.py`'s `extract_pdf()` now
  checks each character's `fontname` against `LEGACY_HINDI_FONT_RE` (Kruti Dev, Chanakya,
  DevLys, Shusha, Walkman, etc.) — the same font-metadata read already used there for bold
  detection. When matched, a sentinel marker is prepended to the script's stdout output;
  `ConvertDocumentToMarkdown` strips it and forces `needs_ocr_review = true` unconditionally,
  independent of the existing `(cid:\d+)`/char-count checks — neither of which catches this case,
  since these fonts produce readable-looking-but-wrong text (`Hkkjr` instead of `भारत`), not
  cid-fallback tokens or sparse output. Deliberately a detection-and-flag fix, not a character
  remapping table — remapping risks silently producing subtly-wrong text in a legal government
  document, which is worse than asking a human to check.
- Job timeout bumped from 900s to 1200s to give the added Docling pass headroom (measured
  2-3 minutes on real 54-112 page documents during evaluation).

### Deliberately deferred (Phase 2, not built this round)

The geometric merge — aligning whichever OCR engine's word-level bounding boxes into Docling's
detected region/table boxes, to actually reconstruct structured Markdown for scanned documents
instead of relying solely on `pdf_structure_extractor.py`'s own heuristic row/column clustering.
Deferred deliberately until real structure output has been reviewed against enough real
documents in the UI to know whether the heuristic-only path is actually insufficient in
practice, rather than building the more complex merge blind. Also deferred: PaddleOCR's
Hindi-only recognition model gap (`devanagari_PP-OCRv5_mobile_rec`, no English-specific model)
and increasing PaddleOCR's CPU/resource limits — both noted as open follow-ups in
`OCR_RESEARCH.md`, not implemented.

**Files changed:** `config/docling.php` (new) · `app/Jobs/ConvertDocumentToMarkdown.php` ·
`app/Http/Controllers/DocumentController.php` · `routes/web.php` ·
`resources/python/pdf_structure_extractor.py` ·
`resources/views/documents/show.blade.php` · `resources/views/documents/pipeline.blade.php` ·
`claude.md` · `README.md` · `ROADMAP.md` · `STRUCTURE_RESEARCH.md` (new) · `OCR_RESEARCH.md` ·
`DEPLOY.md`.

## M33 — Docling table splice (partial Phase 2), review UX, queue-status fix, bulk seeding (COMPLETED 2026-07-16)

Follow-on from M32, driven by testing structure detection against real documents in the actual
review UI (per M32's own deferral condition) rather than more CLI evaluation. Four independent
threads: closing the exact gap M32 predicted, fixing how structure output is surfaced to
reviewers, a real concurrency bug found while testing under queue backlog, and a bulk-import tool
so test documents don't need uploading one at a time.

### What shipped

- **Bulk test-document seeding** — new `php artisan policies:seed` command
  (`app/Console/Commands/SeedStatePolicies.php`) scans a directory of state excise/export policy
  PDFs, matches each filename to an Indian state (`RuleSet::STATES`, with alias handling for
  misspellings like "Chhatisgarh" and abbreviations like "J&K"), creates/reuses a
  `RuleSet(kind=policy)` per state+policy-type, copies the PDF into the vault, and creates the
  `Document` + `DocumentStatusHistory` row. Idempotent (skips already-imported files by
  `original_filename`). Used to seed 14 real state policy PDFs for hands-on UI testing.
- **Engine-maker attribution** — `claude.md`/`README.md`/`OCR_RESEARCH.md` tech-stack entries now
  credit each engine's actual maintainer (Tesseract: Google/HP, EasyOCR: JaidedAI, PaddleOCR:
  Baidu, Surya: VikParuchuri, Docling: IBM, markitdown: Microsoft).
- **Structure panel moved into the Compare & Verify modal.** Previously a page-level banner
  outside the modal (easy to miss — hidden behind the modal once opened, with a raw-JSON link on
  a separate tab as the only way to inspect it). Now a collapsible panel inside the modal itself,
  above the OCR-quality warning: headings as a list, tables rendered via
  [Grid.js](https://gridjs.io/) (CDN, no build step) instead of a hand-rolled static `<table>`.
  The Compare & Verify modal itself is now full-screen (was a centered `min(1400px, 96vw)` box) —
  there's meaningfully more on screen now (PDF, Markdown, structure panel) than when the modal
  was first built.
- **Partial Phase 2 — Docling table text spliced into the Markdown.** Real testing against the
  Odisha document (54-page scan, PaddleOCR) surfaced exactly the gap M32 predicted: Docling's
  structure map correctly detected a table the existing geometric heuristic
  (`detect_tables()`/`classify_and_render()` in `pdf_structure_extractor.py`) missed entirely on a
  scanned page. Fix reuses data already captured rather than building the full geometric merge:
  Docling's compact `structure.json` retains each table cell's own recognized text (not discarded
  the way heading/body OCR text is) — `docling_table_blocks()` (new) turns that into per-page
  `TableBlock`s, and `classify_and_render()` (extended with an optional `docling_tables` param)
  splices one in wherever its own heuristic found no table on that page, at the correct point in
  that page's content rather than only appended at the document's end. Wired via a new
  `--structure-json PATH` CLI flag, passed by both `ConvertDocumentToMarkdown` and
  `RunOcrExtraction` whenever a document's structure.json exists. No LLM/Ollama — pure reuse of a
  model that already ran.
- **Fragment de-dup, partial.** `detect_tables()` now tags a rejected sparse multi-cell row run
  as `Line.table_fragment = true`; `classify_and_render()` strips those tagged lines when Docling
  supplies a clean replacement for that page, to avoid showing both the garbled attempt and the
  correct table. **Verified this only partially closes the gap**: on the real Odisha re-test,
  Tesseract's hOCR line boxes for that specific table were fragmented enough that row-clustering
  never reached the "candidate" stage at all (each line landing in its own single-cell row,
  outside `ROW_Y_TOLERANCE`) — so nothing was tagged, and the garbled fragments still appear
  alongside the correct spliced table. Documented as a known, unfixed limitation — true
  suppression needs bbox coordinate reconciliation between Docling (PDF-point, bottom-left
  origin) and each OCR engine (pixel space, top-left origin, DPI-dependent) — i.e. the full
  geometric merge, still deferred.
- **Fixed a real status-persistence bug**, found while testing conversion under real queue
  backlog (Odisha's 14-minute PaddleOCR run blocking a second document's conversion behind it in
  the single serial worker). `DocumentController::convert()`/`convertOcr()` only ever faked
  `status: 'processing'`/`'ocr_pending'` in their JSON response — never saved it to the document.
  Invisible when jobs ran within seconds, but with a real backlog the queued document's `status`
  column stayed at its old value the whole wait; the polling JS treats anything other than
  `processing`/`ocr_pending` as "done" and reloads, which then showed "not yet converted" —
  looking exactly like conversion had silently stopped. Both endpoints now persist the real
  status plus a `DocumentStatusHistory` entry before dispatch. `conversionStatus()` also now
  returns `queued_behind_other_job` (checks the `jobs` table for another currently-`reserved_at`
  job that isn't this document's), so the UI shows "waiting in queue" instead of looking stuck.
- **`OCR_RESEARCH.md` corrected** — its EasyOCR section still read "not adopted yet" / "not
  integrated into the app," contradicting the Surya section two paragraphs down which correctly
  noted all four engines have been live since 2026-07-14. Fixed the stale section; also refreshed
  the file's top-level status line, which still described OCR as unshipped research.

### Known limitation carried forward

Garbled table-text duplication on OCR-derived documents where the heuristic never attempts
row-clustering (see above) — requires the full geometric merge (bbox coordinate reconciliation),
not built this round. Tracked in `STRUCTURE_RESEARCH.md`'s "Open follow-ups" section.

**Files changed:** `app/Console/Commands/SeedStatePolicies.php` (new) ·
`app/Http/Controllers/DocumentController.php` · `app/Jobs/ConvertDocumentToMarkdown.php` ·
`app/Jobs/RunOcrExtraction.php` · `resources/python/pdf_structure_extractor.py` ·
`resources/views/documents/show.blade.php` · `claude.md` · `README.md` · `ROADMAP.md` ·
`STRUCTURE_RESEARCH.md` · `OCR_RESEARCH.md`.

## M34 — Heading splice, pipeline reorder, auto-OCR-trigger (COMPLETED 2026-07-17)

Follow-on from M33. Three changes, all in service of "fix headings too, and stop making a
reviewer manually click Run OCR when we already know it's needed":

- **Heading splice**, symmetric to M33's table splice: `docling_heading_blocks()` (new) loads
  each Docling-detected heading (text + page) from `structure.json`; level is inferred from a
  numbered prefix the same way the existing all-caps heuristic already does, defaulting to level
  2 when unnumbered. `classify_and_render()` inserts Docling's headings at the top of any page
  where the geometric heuristic found none of its own. A shared `_insert_index()` helper replaces
  the table splice's inline position logic, used by both.
- **Pipeline reorder**: `ConvertDocumentToMarkdown` now runs the text-layer pass (Pass 1, fast)
  *before* Docling's structure pass (Pass 0), not after — the quality/legacy-font check is known
  before spending Docling's per-page time, not after. Docling still always runs afterward (needed
  for the splice either way); text is re-rendered once `structure.json` exists.
- **Auto-OCR-trigger**: since the reorder means quality is known by the end of the job,
  `RunOcrExtraction` is now dispatched automatically (`config('ocr.default')` engine) when the
  text layer looks unreadable, with status going straight to `ocr_pending` — no reviewer click
  needed for the common "this is clearly a scan" case. Manual OCR (e.g. to retry with a different
  engine) still works exactly as before.

Verified end-to-end against two real documents via `dispatchSync`: a scanned/empty-text-layer
document (correctly auto-queued `RunOcrExtraction`, confirmed via the `jobs` table), and a
genuine text-layer document with 66 headings/88 tables detected (correctly stayed at
`status: review`, headings and tables spliced into 278KB of rendered Markdown).

Also documented, not fixed this round: Docling's own structure-pass OCR engine is still
hardcoded to Tesseract (`config('docling.default_ocr_engine')`) even though EasyOCR is more
accurate per `OCR_RESEARCH.md` — a one-line config change, flagged as the next easy win.

**Files changed:** `app/Jobs/ConvertDocumentToMarkdown.php` · `app/Jobs/RunOcrExtraction.php` ·
`resources/python/pdf_structure_extractor.py` · `claude.md` · `OCR_RESEARCH.md` ·
`STRUCTURE_RESEARCH.md`.

## M35 — Split read-only GETs off the mutation rate limiter (COMPLETED 2026-07-21)

Found while bulk-converting all 14 state policy PDFs at once: the pipeline monitor polls
`GET /documents/{id}/convert-status` every 5s per in-flight row, and that route — along with
`pipeline`, `trash`, `bulk-upload` (form), `trashed.pdf`, and `structure` — shared the same
`throttle:mutations` limiter (60/min/user) as actual state-changing requests. With a dozen
documents converting at once, one viewer's browser tab alone blew past 60 requests/minute and
got `429 Too Many Requests` on `/documents/pipeline`, with no mutation involved at all. This
would have hit real users hard once the portal is live for the whole state — plain viewers
watching a bulk upload/conversion in progress, not just the uploader.

Fix: new `reads` named rate limiter (`Limit::perMinute(600)/user`, `AppServiceProvider`), and the
six read-only document GET routes moved out of the `throttle:mutations` group into their own
`['auth', 'throttle:reads']` group in `routes/web.php`. Route names, controllers, and behavior
are unchanged — only the throttle bucket moved. Mutating routes (`convert`, `convert-ocr`,
`revert-ocr`, `markdown.update`, `markdown.discard`, `restore`, `force-destroy`, etc.) stay on
`throttle:mutations` exactly as before.

**Files changed:** `app/Providers/AppServiceProvider.php` · `routes/web.php` · `README.md`.

## M36 — Fix queue `retry_after` shorter than job runtime (COMPLETED 2026-07-21)

Found immediately after M35, still mid-bulk-backfill: 12 jobs landed in `failed_jobs` with
`MaxAttemptsExceededException`, despite the underlying OCR/Docling work having actually
succeeded in most cases. Root cause — `config/queue.php`'s database connection used Laravel's
stock `retry_after` default (90s), far shorter than a real `RunOcrExtraction`/
`ConvertDocumentToMarkdown` run (multi-minute on real policy PDFs). The database queue driver
treats a job whose `retry_after` window has elapsed as abandoned and hands it to the next
worker that polls — with only one `queue:work` process this rarely surfaced (the same worker
just kept running past 90s with nobody else to steal the job), but running several concurrent
workers for bulk-conversion throughput turned it into a real bug: multiple workers would grab
and re-run the *same* slow job, and whichever copy finished second hit
`MaxAttemptsExceededException` since `--tries=1` was already spent by its twin. One document
(Uttar Pradesh Excise Policy, id 16) ended up with no live job left to finish it at all after
two duplicate failures, and needed a manual redispatch.

Fix: `retry_after` default raised to 2000s in `config/queue.php` (committed, not `.env` —
an initial `.env`-only fix was a dead end since `.env` is gitignored and would never have
shipped to a fresh deploy), comfortably above the worker `--timeout` (1900s) which is itself
above both job classes' own `$timeout` (1200s/1900s). Documented the three-way ordering
constraint (`retry_after` > worker `--timeout` > job `$timeout`) and confirmed running multiple
concurrent `queue:work` processes is otherwise safe (the database driver row-locks on pop) in
`DEPLOY.md`, next to the existing `--timeout` explainer.

**Files changed:** `config/queue.php` · `DEPLOY.md`.

## M37 — Fix legacy-font (Kruti Dev) tables still garbled after M32 (COMPLETED 2026-07-24)

Found while handling an urgent real court-matter document upload: M32 (2026-07-15) already
detects legacy non-Unicode Devanagari fonts (Kruti Dev, Chanakya, DevLys, etc.) and forces OCR
review so body text never ships corrupted — but that fix only ever covered body text. Docling's
own structure pass (`runDoclingStructureAnalysis()` in `ConvertDocumentToMarkdown.php`) trusts a
PDF's native text layer for any region it doesn't detect as a scanned bitmap; a Kruti Dev PDF's
text is technically "selectable" (not an image), so Docling never OCR'd those regions either —
spliced table cells came out with the same garbled codepoints, and `RunOcrExtraction` then reused
that same unfixed `structure.json`. Net effect: a reviewer saw correct Hindi paragraphs and
garbage tables in the same document.

Fix: `runDoclingStructureAnalysis()` now takes `bool $forceOcr`, passed `true` only when
`$legacyFont !== null` (already detected before Docling runs, from Pass 1's output) — Docling
gets `--force-ocr` in that one case, re-reading every region including tables from rendered pixels
instead of the broken text layer. Never a blanket `--force-ocr` (confirmed impractical at real
page counts — times out past 10 minutes on a 112-page document, per `STRUCTURE_RESEARCH.md`'s
Finding 3); only reachable through the already-detected legacy-font path. Timeout for this path
bumped to 900s (was 600s), still inside the job's 1200s ceiling. Also stamped `force_ocr`/
`structure_force_ocr` into the structure JSON and document metadata, so which documents needed
this path is visible later without re-deriving it.

Verified against the real production document that originally surfaced the Kruti Dev gap (`Excise
Policy Uttar Pradesh`, id 16, 112 pages, `verified` status — not re-converted, to avoid touching a
live/possibly-cited document without asking first): extracted the affected page standalone
(`pdfseparate`), ran Docling directly with and without `--force-ocr`. Before: `'Ø0la0'`,
`"rhozrk ¼çfr'kr oh@oh½"`. After: `'क्र0सं0'`, `'तीव्रता (प्रतिशत वी / वी)'` — correct, readable
Devanagari, same page, same table.

Separately, same session: fixed a permission bug where Apache's systemd unit (`UMask=0022`)
created new document-vault folders at `0755`, blocking the queue worker (different Unix user,
shares only the `www-data` group) from writing conversion output into any folder's first-ever
upload — root cause of one real document (`Document 504`) failing conversion with "Failed to
write markdown file". Fixed via a `UMask=0002` systemd drop-in for `apache2.service` (matches the
queue worker's own unit, which already had this), plus a retroactive `chmod -R g+w` on the one
folder already affected. Also raised the document upload size limit from 50 MB to 300 MB
(validation + `.htaccess`) after a real 145 MB scanned court document couldn't be uploaded — noted
in `DEPLOY.md` that Cloudflare's own edge enforces a separate, lower cap on tunnel-proxied
uploads (100 MB on Free/Pro) that this app-side change cannot override.

**Files changed:** `app/Jobs/ConvertDocumentToMarkdown.php` · `app/Http/Requests/StoreDocumentRequest.php`
· `public/.htaccess` · `STRUCTURE_RESEARCH.md` · `OCR_RESEARCH.md` · `claude.md` · `README.md` ·
`DEPLOY.md`.

## M38 — Docling table splice now overrides a wrong geometric table, not just a missing one (COMPLETED 2026-07-24)

`classify_and_render()`'s table splice only filled pages where the geometric heuristic
(`detect_tables()`) found *no* table at all — a page where that heuristic misfired (columns
merged, rows split wrong) still produced *some* `TableBlock`, so Docling's more reliable
TableFormer-derived table was silently skipped for exactly the pages that needed it most, and
only ever used when the heuristic gave up entirely.

Fix: table splice in `resources/python/pdf_structure_extractor.py` now removes any geometric
`TableBlock` on a page Docling also detected a table for, then always inserts Docling's version —
Docling wins outright instead of only filling total gaps. Heading splice behavior is unchanged
(Docling's structure JSON has no heading-level data, so a page where the font-size/caps heuristic
already found a heading is presumed better classified by it — not the same reliability gap as
tables). Verified with a standalone script building `Line`/`TableBlock` objects directly (no PDF
needed): a page with both a wrong 2-column geometric table and a correct Docling table for the
same page now keeps only Docling's.

## M39 — Policy Period UX fixes: wording, calendar picker, wrong-supersede bug, one-step upload (COMPLETED 2026-07-25)

Four issues reported from the live Policy Period screens:

1. **Wording.** "Add Period"/"Create Period" read as adding a bookkeeping record, not uploading
   a policy (including old, backfilled ones) — relabeled to "Add Policy" throughout
   `policy_container.blade.php` and `periods/create.blade.php`.

2. **Wrong-supersede bug (root cause fix).** `PolicyPeriodController@store` always marked the
   newly-created period `current` and flipped whatever was `current` before it to `superseded`,
   regardless of either period's dates — so adding a backfilled "2021-22" after "2024-25" was
   already current wrongly stole "current" status. Fixed by comparing `effective_start_date`:
   a new period only supersedes the existing current one when it's chronologically on/after it;
   an earlier or undated new period is created as `superseded` instead of guessing. Extracted as
   `PolicyPeriodController::isChronologicallyLater()` (pure, DB-free) and covered by
   `tests/Unit/PolicyPeriodSupersedeTest.php`. Since dates can be omitted, also added a "Set as
   the current policy period" checkbox to the edit form (`mark_as_current`) as a manual override —
   there was previously no way to promote a period at all.

3. **One-step create + upload.** Creating a period and uploading its PDF were two separate visits
   (create the period, then go upload). `periods/create.blade.php` now has an optional file input
   plus language/visibility fields; `PolicyPeriodController@store` stores the file and creates the
   `Document` row(s) (mirroring `DocumentController@store`'s `rule_set_id` branch, including the
   English+Hindi "both" sibling-document split) inside the same transaction as the period.

4. **Calendar picker, two iterations.** First pass used native `<input type="date">` — dropped
   because its displayed format (and the calendar itself) is rendered by the browser/OS locale,
   not controllable via `placeholder` or any HTML attribute, so a US-locale browser showed
   MM/DD/YYYY on an India-only form. Replaced with **Air Datepicker** (CDN, page-scoped, matches
   this codebase's existing "no build step" convention already used for `Cleave.js`/`Grid.js`):
   locked to `dd-MM-yyyy` display regardless of browser locale, real calendar popup, and — the
   deciding factor over Flatpickr, which was tried first and dropped — clicking the month/year
   header natively cycles day → month → year grid views for fast navigation to an old policy
   year, with no plugin required. Implementation keeps the established display-input +
   hidden-ISO-field split (same shape as the original Cleave.js masking) so the value posted to
   Laravel's `date` validation rule is always unambiguous `Y-m-d`, never locale-dependent.
   Dark mode themed entirely via Air Datepicker's `--adp-*` CSS custom properties rather than
   per-element overrides.

**Files changed:** `app/Http/Controllers/PolicyPeriodController.php` ·
`app/Http/Requests/StorePolicyPeriodRequest.php` · `app/Http/Requests/UpdatePolicyPeriodRequest.php` ·
`resources/views/rule_sets/policy_container.blade.php` ·
`resources/views/rule_sets/periods/create.blade.php` · `resources/views/rule_sets/periods/edit.blade.php` ·
`tests/Unit/PolicyPeriodSupersedeTest.php` · `README.md`.

**Files changed:** `resources/python/pdf_structure_extractor.py` · `STRUCTURE_RESEARCH.md`.

## M40 — Bulk import of UP excise rule-book PDFs, with root/amendment nesting (COMPLETED 2026-07-25)

User had ~190 real rule-book PDFs on disk (`~/Excise Rule Book/All Rules Files/`,
one subfolder per subject — Bar, Beer Retail, Bottling Foreign Liquor, Distilleries, Model Shops,
etc.) that needed to land in the document vault without uploading one file at a time through the
form.

New command `php artisan rules:seed` (`app/Console/Commands/SeedExciseRules.php`, modeled on the
existing `policies:seed`/`SeedStatePolicies`): one `RuleSet` (`kind=rules`) per subject subfolder
under the Excise Department, one `Document` per PDF, same storage convention a normal upload uses
(`document_vault/{level}/{deptSlug}/rules/{ruleSetSlug}/...`).

Per the user's explicit ask ("don't dump all in root, ... each file lists which amendment it is,
the file without any amended word is the base file"), each subfolder is split into a root "base
rules" document and its amendments (`parent_id` chain) instead of a flat list:
- Files are sorted by year parsed from the filename (undated files, e.g. a "(Misc)" consolidated
  copy, sort first); the earliest becomes the root (`document_type=rule`, `parent_id=null`), the
  rest become amendments (`document_type=rule_amendment`, `parent_id` = root's id) — even when the
  root file's own name happens to contain the word "amendment" (a few subjects' earliest digitized
  copy is itself titled "(amendment) Rules 19xx", amending an even older undigitized rule).
- Filenames that are clearly not part of the rule series (circulars, checklists — matched via
  `/circular|check\s*list/i`) import as standalone documents (`document_type=other`) instead of
  joining the amendment chain.
- Idempotent (skips a file if a `Document` with that `original_filename` already exists in that
  `RuleSet`), so safe to re-run if more files are added later.

**Bug caught after the first run, fixed same session:** the year-sort tie-break was wrong when two
files in the same subfolder shared a year (e.g. `Beer Retail Rules 2001.pdf` and `Beer Retail
Rules 2001 (1st amendment).pdf` are both dated 2001) — `sortBy` fell back to array/filename order,
which put the amendment-titled file first, so it got picked as root. Caught by auditing every
ruleset's chosen root via tinker before declaring done. Fixed the two affected rulesets (Beer
(Retail), Foreign Liquor (Retail)) directly in the DB (repointed children, swapped `parent_id`/
`document_type` between the two documents), and patched the command's sort key to tie-break on
"contains the word amendment" so a future re-run won't reproduce it.

Verified: 190/190 real PDFs imported (macOS `._*`/`.DS_Store` junk filtered out), count matches
per-subfolder, `RFP State Tax` (the only pre-existing `kind=rules` RuleSet) and the unrelated "Bar
Policy Chhattisgarh" policy row both left untouched, physical files present on the `public` disk,
`amendments()` relation returns the correct children for a spot-checked root (Bar).

**Files changed:** `app/Console/Commands/SeedExciseRules.php` (new) · `README.md`.

## M41 — Kruti Dev detection missed anonymized fonts; bulk-import batch reset and reprocessed (COMPLETED 2026-07-25)

Reported live: `BWFL-2 Rules-2021 (7th amendment)` (one of the M40 bulk-imported documents) still
showed garbled Hindi text (`Øe-la[;k-99¼d½...` instead of readable Devanagari) despite M37's
legacy-font detection, and `needs_ocr_review` was `false`. Root cause: M37's detection
(`LEGACY_HINDI_FONT_RE` in `pdf_structure_extractor.py`) only ever matched on the embedded font's
*name* (`kruti|chanakya|devlys|...`) — this PDF's export tool had stripped/anonymized its fonts
down to generic subset tags (`pdffonts` showed `CIDFont+F1`…`F5`, no trace of the original name),
so the regex never fired.

Fix: added a content-based fallback in `extract_pdf()` that doesn't depend on the font name at
all — legacy Devanagari fonts (Kruti Dev, Chanakya, etc.) remap matras onto a handful of
Latin-1/extended-Latin codepoints that essentially never appear in genuine English or properly
decoded Unicode-Hindi text (`¼ ½ ¾ Œ ª Ù ç ¶ § † ‡ „ Ø ø µ`); if a document has zero real
Devanagari Unicode characters (U+0900–097F) but ≥15 of these tell-characters, it's flagged as
legacy-font regardless of what the font is named. Verified against the reported document (138
tell-chars, now correctly flagged) and spot-checked against 6 other already-converted documents:
caught 2 more silently-affected ones from the same M40 batch (`FL Bottling 24th amendment`, `Beer
Retail 19th amendment`), zero false positives on clean English/Chandigarh-policy/Jharkhand-policy
documents.

Per direct request, since the fix could only be trusted going forward: stopped all 3 queue
workers, wiped the `jobs` table (confirmed all 179 pending jobs belonged to this batch — no
unrelated work in flight), and reset all 190 M40-imported documents (RuleSets 32–49; `RFP State
Tax` explicitly left untouched) back to `status=uploaded` — deleted their `.md`/`.structure.json`/
`.pre-ocr.md` artifacts, cleared extraction metadata, logged a `DocumentStatusHistory` note
explaining why. Restarted workers, re-dispatched via `documents:convert-all`. Reprocessing was
still in progress with 0 failures as of this entry.

Separately diagnosed, not yet fixed: the same document's English section has a two-column
Column-I/Column-II amendment-comparison table that Docling's table model didn't detect
(`structure_tables_count: 0`) — falls back to a flattened row-by-row paragraph read, which reads
as "duplicated" text (often genuinely near-identical, since many clauses are unchanged between
old and new wording) rather than a real table. This is a Docling layout-model limitation on this
document's borderless/continued-table format, not a text-encoding bug — left as a known gap.

**Files changed:** `resources/python/pdf_structure_extractor.py`.

## M42 — Dynamic OG/SEO meta tags, share buttons, sitemap, a real Blade double-escaping bug, and self-hosted Tabler Icons (COMPLETED 2026-07-25)

Asked to make link previews work properly when a document/rule-set page is shared via WhatsApp or
any other social channel — the two sibling projects (Revenue Recovery, Spatial Revenue Recovery)
only ever had static, generic OG tags since they're guarded portals; this one needed real
per-page dynamic previews since its content is meant to be shared and found.

`<x-head>` (`resources/views/components/head.blade.php`) gained `description`/`image`/`url`/
`type` props, emitting `<meta name="description">`, `<link rel="canonical">`, full Open Graph, and
`twitter:card=summary_large_image` tags; `<x-layout>` forwards them (`description` defaults to
`pageSubtitle`). Every page now sets a real dynamic title/subtitle instead of the generic
homepage default. `documents/show` builds a real description (doc type · rule set/section ·
department · amendment number · effective year) and, for `visibility=public` documents only,
points `image` at a new route (`DocumentController::ogImage()`) that rasterizes PDF page 1 via
`pdftoppm` on first request, caches it as a `.og.jpg` sibling file (same convention as `.md`/
`.structure.json`), and serves it directly after — `visibility=authenticated` documents fall back
to a generic banner (`public/og-default.jpg`, a static 1200×630 image generated once via a small
GD script) since the route has to be crawler-reachable unauthenticated. Document show also gained
visible WhatsApp/X/copy-link share buttons.

**Real bug found and fixed while wiring this up, not isolated to OG tags:** every page using
`<x-layout title="{{ $x }}">` (unprefixed Blade attribute containing `{{ }}`) was silently
double-escaping. Laravel compiles that syntax to `'title' => e($x)` — the prop arrives at the
component *pre-escaped* — so wherever it got echoed again downstream via a plain `{{ }}` (the
page header's `<h1>`, and now this session's new meta tags), the escaping happened a second time:
`&` → `&amp;` → `&amp;amp;`, rendered as the literal text `&amp;` in the browser. This had been
silently present since the app's first Blade layouts; it only became visible now because "Rules &
Regulations" (added as breadcrumb/title text a few commits before this session) was the first
title string in the app to contain an HTML-special character. Fixed at the root across all ~18
affected views (`rule_sets/*`, `documents/*`, `sections/*`, `divisions/*`, `folders/*`,
`department/show`, `approvals/index`, `search/index`) by converting `title`/`page-title`/
`page-subtitle` from the unprefixed `attr="{{ }}"` form to bound `:attr="..."` raw PHP
expressions, so the single `{{ }}` in `head.blade.php`/`header.blade.php` is the only escape that
ever runs. Verified live: `Rules &amp; Regulations` (was `Rules &amp;amp; Regulations`) across
every affected page; `php artisan view:cache` compiles all views clean.

Also fixed, reported separately in the same session: Tabler Icons occasionally rendering as empty
glyph boxes. The existing setup was jsDelivr-primary with a JS timeout fallback to the (already
vendored) self-hosted copy — but the fallback only checked whether the *stylesheet* had loaded
(`link.sheet`), not the actual `.woff2` font file behind it, so on a flaky/restrictive network
(this app runs inside a government office) the CSS could load fine while the font silently
stalled, and the fallback never triggered. Switched to serving the self-hosted copy directly, no
CDN involved.

Also added, per request ("we can add robots etc too"): `public/robots.txt` now disallows the
auth-only top-level paths (`/admin`, `/profile`, `/approvals`, `/login`, `/logout`) and points to
a new `/sitemap.xml` (`SitemapController`, cached 1 hour) listing every department, every
`kind=rules` RuleSet, and every `visibility=public` document in `review`/`verified` status — the
same visibility rule the show routes themselves enforce, so nothing in the sitemap 403s for an
anonymous crawler.

**Files changed:** `resources/views/components/head.blade.php` · `resources/views/components/layout.blade.php`
· `app/Http/Controllers/DocumentController.php` (new `ogImage()`) · `app/Http/Controllers/SitemapController.php` (new)
· `resources/views/sitemap.blade.php` (new) · `routes/web.php` · `public/robots.txt` · `public/og-default.jpg` (new)
· `resources/views/documents/show.blade.php` · `resources/views/rule_sets/show.blade.php` · `resources/views/rule_sets/index.blade.php`
· `resources/views/rule_sets/edit.blade.php` · `resources/views/rule_sets/create.blade.php` · `resources/views/rule_sets/policy_container.blade.php`
· `resources/views/rule_sets/periods/edit.blade.php` · `resources/views/rule_sets/periods/create.blade.php`
· `resources/views/documents/edit.blade.php` · `resources/views/sections/show.blade.php` · `resources/views/sections/edit.blade.php`
· `resources/views/sections/index.blade.php` · `resources/views/divisions/show.blade.php` · `resources/views/divisions/edit.blade.php`
· `resources/views/folders/show.blade.php` · `resources/views/folders/edit.blade.php` · `resources/views/department/show.blade.php`
· `resources/views/approvals/index.blade.php` · `resources/views/search/index.blade.php` · `claude.md`.

## M43 — URL/sharing edge-case sweep: trailing-slash https downgrade, fallback 404, full branded error pages, favicon, stray legacy slug (COMPLETED 2026-07-25)

Continued from M42 after two real bugs surfaced from live WhatsApp testing:

1. **Fallback route redirected everyone to `/login`.** `Route::fallback()` unconditionally
   redirected any URL matching no route — guests and crawlers included — to `/login`, instead of
   404ing. A malformed/incomplete document link (missing a path segment) silently became a
   "Sign In" page instead of failing cleanly. Changed to `abort(404)`; real protected routes
   still redirect correctly via their own `auth` middleware, untouched.
2. **Trailing-slash redirect downgraded https to http.** Apache sits behind a Cloudflare Tunnel
   that terminates TLS and forwards plain `http://` internally (`~/.cloudflared/config.yml`), so
   Laravel's standard `.htaccess` "redirect trailing slash" rule built its `301` using the scheme
   Apache itself saw (`http`), not what the client actually used. Most crawlers (WhatsApp
   included) refuse to follow an `https→http` downgrade, so `.../rules/` (trailing slash) failed
   to preview while `.../rules` (no slash) worked — same underlying "doesn't exist" URL, two
   different-looking symptoms purely from the slash. Forced `https://` explicitly in the rewrite
   target.

Per direct request ("fix all things all types of issues... software that should work always"),
followed with a broad sweep across every route pattern (department/section/division/folder/
rule-set/policy-container/policy-period × show/document/auth-restricted, trailing slashes) rather
than stopping at the two reported bugs:

- Built a branded 404 page (`resources/views/errors/404.blade.php` + new shared
  `<x-error-page>` component) — previously fell back to Laravel's bare default. Then, per a
  follow-up request to use Laravel's own scaffolding rather than hand-rolling, ran
  `php artisan vendor:publish --tag=laravel-errors` for the complete standard error-code set and
  replaced all of them (401/402/403/404/419/429/500/503) with the same shared component instead
  of Laravel's default templates — consistent branding, real per-page title/description,
  `noindex` meta (never indexed, never generates a misleading rich preview if a broken/restricted
  link is shared).
- No favicon existed at all (`public/favicon.ico` was a 0-byte empty file, no `<link rel="icon">`
  in `<head>`). Generated a small branded icon set (document-page glyph, indigo, matching the
  app's own palette) via a one-off GD script: `favicon.ico` (PNG-in-ICO, valid in every current
  browser), 16/32px PNGs, `apple-touch-icon.png`, 192/512px PWA icons, and a `site.webmanifest`.
- Found and fixed one real legacy document (RFP, id 21) whose slug was `1776420884` — its raw
  uploaded filename, not derived from its title, predating this session (the slug generator
  itself was never buggy; this one row came from some other insertion path). Regenerated via the
  same `Document::uniqueSlugForRuleSet()` every other document already uses. Swept
  Document/RuleSet for other numeric-only slugs — none found, isolated to this one row.
- Also fixed the og-image thumbnail route carrying implicit `private`/session-cookie cache
  headers (Cloudflare refuses to cache anything with `Set-Cookie`, confirmed via
  `cf-cache-status: BYPASS`) — added explicit `Cache-Control: public, max-age=86400`.
- Also cleaned up user-facing copy that leaked an internal implementation detail: the
  document-conversion "waiting in queue" message said "(single worker, see CLAUDE.md)" — removed
  (and was stale anyway, since the pipeline now runs multiple concurrent workers).

Verified live via a systematic sweep of every route-pattern type (department/section/division/
folder/rule-set/policy show pages, a real `visibility=authenticated` document via a section-folder
path, the sitemap, robots.txt, all 8 error codes, all 6 favicon/manifest assets) — all correct
status codes, all real branded content, no regressions.

**Files changed:** `routes/web.php` · `public/.htaccess` · `public/robots.txt`* ·
`app/Http/Controllers/DocumentController.php` · `resources/views/components/error-page.blade.php` (new)
· `resources/views/errors/{401,402,403,404,419,429,500,503}.blade.php` ·
`public/{favicon.ico,favicon-16.png,favicon-32.png,apple-touch-icon.png,icon-192.png,icon-512.png,site.webmanifest}` (new)
· `resources/views/documents/show.blade.php` · `claude.md`.
*(robots.txt already updated in M42; no further change this milestone.)

## M44 — Root-caused and closed the RFP numeric-slug bug at its source (COMPLETED 2026-07-25)

M43 fixed the one affected document (RFP, id 21) by regenerating its slug, but didn't explain how
a document with a perfectly good title ("Request for Proposal...") ended up with slug
`1776420884`. Traced it: the upload form auto-fills the title field from the file's name as a
convenience (`fileToTitle()` in `bulk-upload.blade.php`/`rule_sets/show.blade.php` — strips the
extension, swaps `-`/`_` for spaces). The source PDF's filename was itself just a number
(`1776420884.pdf`, presumably its internal document ID), so the auto-filled title stayed
`"1776420884"` when the uploader didn't replace it — that (bad) title is exactly what
`Document::uniqueSlugForRuleSet()` slugged at creation time. The document's title must have been
corrected afterward through the edit form, but title edits never touch `slug` (by design, to keep
URLs stable across a legitimate rename) — so the slug stayed frozen on the original bad title.

Fix: added a validation rule to both `StoreDocumentRequest` and `UpdateDocumentRequest` requiring
the title to contain at least one letter (any language) — rejects both the upload-time and
edit-time path that let this happen, with a message explaining why ("a filename or ID number
alone makes a poor, unreadable document URL"). Verified via `Validator::make()`: `"1776420884"`
rejected, `"Bar Rules 2020"` accepted.

**Files changed:** `app/Http/Requests/StoreDocumentRequest.php` · `app/Http/Requests/UpdateDocumentRequest.php`.

## M45 — Quality check was averaging across whole document, silently dropping scanned pages (COMPLETED 2026-07-25)

Reported live: `FL Bottling Rules 2011 (16th amendment)` shows only English content, Hindi entirely
missing. Traced it: `isGoodQuality()` only ever checked an aggregate char-count average across the
whole document (`charCount >= pageCount * 40`). This document is 15 pages — 13 of them pure JBIG2
scanned images with zero text layer, and only 2 pages (7 and 9) with real embedded text. Those 2
text-heavy pages alone produced ~7,265 characters against a 600-character threshold (15 × 40),
clearing the average by ~10x — so `needs_ocr_review` stayed `false` and the other 13 pages, which
happen to carry the Hindi notification content, silently vanished from the extracted Markdown.
Nothing errored, nothing was flagged; the gap was invisible without manually checking page-by-page.

Fix: `pdf_structure_extractor.py`'s `extract_pdf()` now tracks per-page character counts during
extraction (same 40-chars-per-page bar `isGoodQuality()` already used, so "sparse page" means the
same thing in both places) and emits a `SPARSE_PAGES:n/total` sentinel — same stdout-string
contract as the existing `LEGACY_FONT_DETECTED` marker — when ≥15% of pages are near-blank.
`ConvertDocumentToMarkdown` parses both sentinels independently now (they can appear together, in
either order — the parsing loop from a single `^`-anchored regex check to one that strips
whichever marker(s) are present) and routes to OCR whenever the sparse-page condition fires,
alongside the existing legacy-font and aggregate-quality checks. Verified: sentinel correctly
fires `13/15` on the reported document.

**Also investigated, not shipped:** the same document's two-column "Column-I / Column-II"
amendment-comparison table (confirmed recurring across most of these gazette documents — the
user described it as "standard in amendments"). Built and tested a targeted heuristic: detect the
"Column-I" marker text, then split the remainder of that page into two columns by clustering line
x0-positions around the widest gap. Tested against the real OCR (Tesseract) output for this
document and rejected it — the gap-based clustering picked the wrong split point on real messy
OCR data, dumping most of both columns' text into one bucket while the other came out nearly
empty. That's a worse failure mode than the status quo (flattened-but-complete text), so it was
reverted rather than shipped. Two-column detection remains a known limitation (see
`STRUCTURE_RESEARCH.md`) — a real fix needs per-word OCR coordinates and a more careful clustering
algorithm, not a quick heuristic.

Per direct request, the reprocessing batch (already running from M41's Kruti Dev fix) was stopped
before this landed, since the same blind spot could affect other mixed typed/scanned documents in
it — held stopped, to be restarted later once confirmed ready.

**Files changed:** `app/Jobs/ConvertDocumentToMarkdown.php` · `resources/python/pdf_structure_extractor.py`.

## M46 — Rule-set/policy names now auto-suffixed with "Rules"/"Policy" (COMPLETED 2026-07-25)

Bulk-imported rule sets were named after their bare category ("Bar", "BWFL-2"), so the breadcrumb
and header read "Bar" instead of "Bar Rules". Renamed the 15 genuine rule categories in the batch
(ids 32–41, 43–45, 47–48) to append " Rules"; left `UP Excise Act` (it's the Act, not Rules),
`FL9-9A` and `Number and Location` (their contents are circulars/licence orders, not rules), and
`RFP State Tax` (a pre-existing, unrelated mis-slotted record) unchanged.

Made it permanent going forward: `RuleSet::withKindSuffix($name, $kind)` appends " Rules" (kind=
rules) or " Policy" (kind=policy) to whatever a user types when creating/renaming a rule set or
policy container, unless that word is already present (case-insensitive). Wired into
`RuleSetController@store` (both kinds) and `@update` (only fires when the name field actually
changed, so resaving an untouched record can't double-suffix it). Policy *periods* (yearly
sub-documents like "2024-25") are unaffected — different naming convention entirely.

Slugs/URLs were never touched — `RuleSet::slug` is a separate stored column set once at creation,
so renaming the display `name` doesn't move or break any existing link.

**Files changed:** `app/Models/RuleSet.php` · `app/Http/Controllers/RuleSetController.php`.

## M47 — Mobile-browsable layout: off-canvas sidebar, responsive header, stacking dashboard grid (COMPLETED 2026-07-25)

The site was desktop-only in practice: `<x-sidebar>` was a permanent 16rem (or 4rem collapsed)
flex child inside a `flex h-screen` row, so on a phone viewport it alone consumed most or all of
the screen width, squeezing the header and every page's content along with it. Not a mobile-first
rebuild — desktop stays the primary experience — just making the app usable on a phone/tablet for
someone who has to view or browse a document there.

Approach is media-query/responsive-class driven (Tailwind breakpoints + a JS toggle), not
server-side user-agent sniffing — simpler, no separate mobile template to maintain, and it
degrades correctly for tablet widths too:

1. **Sidebar** becomes a fixed-position off-canvas drawer below the `md` breakpoint — translated
   fully off-screen by default, slides in over the content with a dimmed backdrop when opened.
   At `md:` and up it reverts to today's static in-flow behavior unchanged.
2. **Header** gains a hamburger button (visible only below `md`) that toggles the drawer; tapping
   the backdrop or a nav link closes it again. The search box (redundant with the sidebar's
   "Search" link) hides below `sm`, "New Conversion" drops to icon-only, padding tightens.
3. **Dashboard**: stat cards were already responsive (`grid-cols-2` and up) and are left as-is.
   The "Recent Documents / Status Breakdown" row was hard-locked to `grid-cols-3` — now stacks to
   one column on mobile, matching the pattern already used in `documents/show.blade.php`.
4. **Spot-check pass** found `department/show.blade.php`'s category cards already responsive
   (`grid-cols-1 sm:grid-cols-3`) — no change needed there. It also surfaced a real bug beyond the
   original plan: every document-row "view/edit" action across the app used
   `opacity-0 group-hover:opacity-100` to stay hidden until hovered — on a touchscreen there is no
   hover, so those actions were invisible and untappable on mobile. Fixed by changing the pattern
   to `opacity-100 sm:opacity-0 sm:group-hover:opacity-100` (always visible below `sm`, hover-reveal
   restored at `sm:` and up) across all 7 files that used it: `documents/index.blade.php`,
   `documents/show.blade.php`, `search/index.blade.php` (5 occurrences), `sections/show.blade.php`,
   `rule_sets/_doc_row.blade.php`, `folders/_doc_row.blade.php`, `divisions/_doc_row.blade.php`.

Verified by rendering the dashboard through `php artisan serve` and inspecting the emitted HTML
for the new drawer/backdrop/hamburger markup and JS wiring (no headless-browser tooling available
in this environment to screenshot at a narrow viewport).

**Files changed:** `resources/views/components/sidebar.blade.php` ·
`resources/views/components/header.blade.php` · `resources/views/components/layout.blade.php` ·
`resources/views/frontend/index.blade.php` · `resources/views/documents/index.blade.php` ·
`resources/views/documents/show.blade.php` · `resources/views/search/index.blade.php` ·
`resources/views/sections/show.blade.php` · `resources/views/rule_sets/_doc_row.blade.php` ·
`resources/views/folders/_doc_row.blade.php` · `resources/views/divisions/_doc_row.blade.php` ·
`claude.md`.

## M48 — Follow-up mobile fixes: breadcrumb/title overflow, document header pills, PDF-in-iframe fallback (COMPLETED 2026-07-25)

Three more mobile bugs found after M47 shipped, all on `documents/show.blade.php` and the shared
`<x-breadcrumb>`/`<x-header>` components it uses:

1. **Breadcrumb stretched the whole page horizontally on mobile** instead of wrapping — `<x-breadcrumb>`
   was a plain `flex items-center` row with no wrap, so a long chain (Home › Departments › Excise ›
   Rules & Regulations › a long rule-set name › a long document title) forced the page width out
   and added a horizontal scrollbar. Fixed by making the nav `flex-wrap` (each crumb now wraps to
   the next line instead of pushing the page wide) and constraining only the *last* crumb (the
   current page name, usually the longest) to `max-w-[70vw] sm:max-w-xs truncate` so one runaway
   title can't dominate the wrapped line either.
2. **Sticky header title bar clipped long document titles** — `header.blade.php`'s `<h1>` used
   `truncate` (single line + ellipsis), added during M47 to stop long titles overflowing the header
   row. On a phone, legal document titles are often the whole page title and got clipped mid-word
   with no way to read the rest. Changed to `line-clamp-2 sm:truncate` — wraps up to 2 lines below
   `sm`, keeps the original single-line truncate at `sm:` and up where there's more horizontal room
   next to search/buttons.
3. **Document header pills/actions could overflow narrow screens** — the title+badges block and
   the share/edit/convert/delete button row sat in a `flex items-start justify-between` with no
   wrap. Added `flex-wrap` to both the outer row and the actions row so buttons drop to their own
   line(s) instead of squeezing or overflowing on a narrow viewport.
4. **PDF wouldn't render inside the `<iframe>` on mobile browsers** — not a server bug (every PDF
   route already sends `Content-Type: application/pdf` + `Content-Disposition: inline`). Most
   mobile browsers simply have no inline-PDF plugin for an iframe to use — desktop Chrome/Firefox/
   Edge use PDFium, Android Chrome/Firefox don't, so the frame renders blank or triggers a silent
   download. Fixed with feature-detection, not UA-sniffing: Chromium browsers expose
   `navigator.pdfViewerEnabled` (true on desktop, false on Android — exactly the broken case).
   Where that API doesn't exist (older Firefox, Safari) fall back to assuming only Android is
   unsupported, since iOS Safari previews PDFs in an iframe fine. When unsupported, the iframe
   (`#pdf-iframe`) is hidden and a plain "Open PDF" card (`#pdf-iframe-fallback`, opens the PDF
   route in a new tab) is shown instead. Only the main document viewer was changed — the
   admin-only Compare & Verify modal's side-by-side iframes, and the trash/approvals preview
   iframes in `documents/trash.blade.php`/`approvals/index.blade.php`, are desktop admin workflows
   and were left as-is.

Verified by compiling the Blade template (`Blade::compileString` + `php -l` on the output — an IDE
false-positive flagged unrelated lines as JS syntax errors, confirmed spurious this way) and by
rendering a real public document's show page through `php artisan serve`, confirming the new
`#pdf-iframe-fallback` markup, `supportsInlinePdf` script, and wrapped breadcrumb nav all render.

**Files changed:** `resources/views/components/breadcrumb.blade.php` ·
`resources/views/components/header.blade.php` · `resources/views/documents/show.blade.php`.

## M49 — Pipeline/server health endpoint, and a real MariaDB-timezone bug found while building it (COMPLETED 2026-07-25)

User reported documents showing "awaiting verification" in one place and "OCR pending" on the
document page itself — investigated and found no data corruption: the single `queue:work`
process (`pdf-pipeline-queue.service`) was healthy (0 restarts, 0 failed jobs, steadily completing
jobs), just working serially through the ~190-document rules batch dispatched by
`documents:convert-all`. That command bulk-flips every matching document to `processing`
immediately at dispatch time, before the single worker has touched most of them — so "Processing"
on 47 of them meant "queued, not yet started," not "actively running," which is what read as
inconsistent. Confirmed via `journalctl` and DB state, not a bug — just an artifact of one worker
serially draining a large batch (estimated ~13–14h total remaining at observed per-job timings).

Declined to add extra concurrent queue workers to speed it up: CPU was already at 85°C (package)
with only one Docling process running, on an unattended AIO with case fans only (no AC) over the
weekend — adding 2 more CPU-bound workers risked the 100°C critical threshold with no one there to
notice. Left it on one worker; confirmed the remaining backlog comfortably fits the weekend runtime
already planned.

To let this be checked remotely (through the tunnel) without SSHing in or running `tinker` by hand,
added `GET /documents/pipeline/health` (`DocumentController::pipelineHealth()`, same `auth` +
`throttle:reads` gate as the existing `/documents/pipeline` monitor — no new public exposure).
Returns JSON: queue backlog (`pending_jobs`/`failed_jobs` from the `jobs`/`failed_jobs` tables),
per-status document counts, a `last_job_activity` timestamp sourced from the most recent
`DocumentStatusHistory` row (every job status transition writes one, so a stalled worker shows up
as this going stale), and a `server` block (load average via `sys_getloadavg()`, memory from
`/proc/meminfo`, CPU temperature from `/sys/class/thermal/thermal_zone*/temp`, CPU count via
`nproc`) — all read directly from `/proc`/`/sys`, no new software installed. `status` flips to
`stalled` if jobs are queued but nothing has moved in 15+ minutes.

User also asked about installing Webmin for server monitoring — declined: Webmin is a full
root-level system-administration panel (users, firewall, packages, its own daemon/port), a much
bigger attack surface than "check load and queue status" needs, especially exposed through a
public tunnel. The new health endpoint answers the actual need (queue + CPU/memory/temp) through
the app's existing auth, with no new exposed service.

**Real bug found while building this:** `last_job_activity` initially showed timestamps hours in
the *future* (e.g. "5 hours from now" for an event that had just happened). Root cause: this
server's system timezone is IST (`Asia/Kolkata`), while `config('app.timezone')` is `UTC` — normal
Eloquent-managed timestamps go through Carbon (correctly UTC), but three tables opt out of that and
rely on a MariaDB-level `useCurrent()` column default instead: `document_status_histories.created_at`
(`$timestamps = false` on the model), `activity_logs.created_at`, and `jobs`' sibling
`failed_jobs.failed_at`. MariaDB's own `CURRENT_TIMESTAMP` evaluates using the **server's system
timezone** (IST) unless told otherwise, so those columns were silently storing IST wall-clock values
that every other part of the app (Carbon casts, `now()`, `diffForHumans()`) treats as UTC — a
consistent ~5.5h skew on any calculation (not on the raw printed digits, which coincidentally show
correct IST wall-clock time when just `format()`-ed, e.g. the Status History list on the document
page was never visibly wrong). Fixed at the connection level, not per-table: added
`'timezone' => '+00:00'` to the `mariadb` connection in `config/database.php` (the connection this
app actually uses per `DB_CONNECTION=mariadb` in `.env`, not the unused `mysql` block) — Laravel's
connector issues `SET time_zone = '+00:00'` on every new connection, so all three affected tables'
DB-level defaults are correct going forward with a one-line, one-place fix. Verified: `SELECT NOW()`
now returns the same instant as PHP's `now()`, and a live `document_status_histories` row written
after the fix showed `last_activity_ago: "1 minute ago"` instead of "5 hours from now." Existing
historical rows in those three tables remain skewed (not backfilled — an audit-trail table isn't
something to bulk-rewrite without being asked).

**Files changed:** `config/database.php` · `app/Http/Controllers/DocumentController.php` ·
`routes/web.php`.

**Follow-up (same day):** the JSON-only endpoint wasn't useful to glance at from a phone browser,
so gave it a real dashboard. `pipelineHealth()` now content-negotiates on the same URL — a browser
gets a new `documents/pipeline-health.blade.php` view (status banner, queue/document stat cards,
color-coded load average / memory / CPU temperature thresholds, auto-refreshes every 15s via a
plain `<meta http-equiv="refresh">`, no JS polling needed for something checked this occasionally),
while a script/monitor requesting JSON (or hitting `?format=json`, since plain `curl`'s default
`Accept: */*` doesn't trip Laravel's `wantsJson()`) still gets the original JSON body. No new route
— same `GET /documents/pipeline/health`. Verified by compiling the Blade template and rendering it
through the real controller method (`Blade::compileString` + `php -l`, then an authenticated
in-process request) — CPU temp read 94°C in that same check, a couple degrees past the 90°C
"reduce concurrent workers" threshold the dashboard itself now flags in red, consistent with the
earlier decision this session to leave the queue on a single worker.

**File added:** `resources/views/documents/pipeline-health.blade.php`.

**Follow-up:** added a `Pipeline Health` sidebar link so it's discoverable without knowing the URL
— sits under "Manage" (admin-only, next to Users/Activity Log), matching its operational nature
rather than the document-browsing sections above it.

**File changed:** `resources/views/components/sidebar.blade.php`.

## M50 — Laravel Pulse installed for ongoing app monitoring; a self-inflicted outage along the way (COMPLETED 2026-07-25)

User asked about `pulse.laravel.com` as an alternative to the custom health page. Recommended
keeping both rather than choosing one: Pulse gives slow-query/exception/cache/queue-throughput
history the custom page never had, but has no concept of this app's `documents` table or its
pipeline statuses, and its Servers card doesn't read CPU temperature at all — the one metric that
actually mattered this weekend. `composer require laravel/pulse` (pulled in `livewire/livewire` —
the only page in the app using it; everything else stays plain Blade/vanilla JS), published
config + migration + the dashboard view, `php artisan migrate`. Bonus: `composer audit` flagged 6
medium-severity `guzzlehttp/guzzle`/`guzzlehttp/psr7` advisories already present before this
session touched anything — fixed in the same pass with `composer update
guzzlehttp/guzzle guzzlehttp/psr7 --with-all-dependencies`, zero advisories remaining.

Gated `GET /pulse` behind a `viewPulse` Gate in `AppServiceProvider::configurePulseAccess()`
(`fn (?User $user) => $user?->isAdmin() ?? false`) — same Fortify-managed session and admin check
as every other admin-only page (Users, Activity Log, Pipeline Health), answering the user's "same
Fortify logic" ask directly: no separate login, reachable through the same public tunnel as
everything else since it's just another gated route. The Servers card (CPU/memory/storage) needs
its own continuously-running `pulse:check` process — added
`~/.config/systemd/user/pdf-pipeline-pulse.service`, same shape as the existing
`pdf-pipeline-queue.service`, enabled and confirmed recording within seconds. Added a `Pulse`
sidebar link under "Manage", next to the new Pipeline Health link.

**Self-inflicted outage, root-caused and fixed:** while checking why the *previous* session's
health-dashboard Memory card was blank, re-verified `/etc/systemd/system/apache2.service.d/
override.conf` — the file this session's earlier `ProcSubset=all` fix had been written to. Missed
that `DEPLOY.md` already documented a **different, load-bearing** directive living in that exact
same file (`ReadWritePaths`, letting Apache write `storage/`/`bootstrap/cache` despite
`ProtectHome=read-only` — without it, every request fails on "Read-only file system"). The
`sudo tee ... <<EOF` command given to the user overwrote the file instead of amending it, silently
dropping `ReadWritePaths`. Confirmed via `curl` against the live domain: HTTP 500 on every page,
site down for roughly 14 minutes until caught and the user re-ran a corrected command with both
directives together. Fixed the root cause, not just the symptom: updated `DEPLOY.md`'s own
documentation of this file to keep both directives shown together going forward with an explicit
warning never to blindly overwrite it, and corrected the same mistake in
`infra-notes/cpu-thermal-and-apache-procfs.md`. Verified recovery via `curl` (200 on both
`127.0.0.1:8080` and the public `docsrepo.exciseup.in`) and confirmed Apache was writing to
`storage/framework/views/` again post-fix.

**Files changed:** `composer.json` · `composer.lock` · `config/pulse.php` (new) ·
`database/migrations/2026_07_25_180203_create_pulse_tables.php` (new) ·
`resources/views/vendor/pulse/dashboard.blade.php` (new, published) ·
`app/Providers/AppServiceProvider.php` · `resources/views/components/sidebar.blade.php` ·
`DEPLOY.md`. **Also:** `~/.config/systemd/user/pdf-pipeline-pulse.service` (new, outside the
repo) · `infra-notes/cpu-thermal-and-apache-procfs.md`.

**Follow-up (same day): Pulse cards stuck loading forever, no server error.** Every card on
`/pulse` sat on its gray loading-skeleton placeholder indefinitely — confirmed via a rendered
dump that the page and each Livewire component *did* mount server-side (`wire:snapshot` present in
the HTML), and `storage/logs/laravel.log` had zero errors for the request window, ruling out a
backend failure. Root cause was client-side and silent: `App\Http\Middleware\SecurityHeaders`'s
CSP allows `'unsafe-inline'` but not `'unsafe-eval'`, and Livewire bundles Alpine.js for its
front-end reactivity — Alpine's default build evaluates every directive via `new Function(...)`,
which that CSP blocks with no visible error anywhere (not a server log, not even a page-level
failure — Alpine just never initializes, so nothing ever replaces the skeletons). Rather than
loosen the CSP app-wide for one page, published `config/livewire.php` and set `'csp_safe' => true`
— Livewire ships an eval-free Alpine build (`livewire.csp.min.js`) precisely for this, served at
the exact same asset URL, confirmed via `curl` (zero `new Function` occurrences in the served JS,
vs. several in the default bundle).

**Correction (same day): that fix was wrong, made it worse.** User reported the page still didn't
work after a hard refresh (ruling out browser/Cloudflare caching, both checked directly) — now
showing a blank white page instead of stuck skeletons. Root cause: Pulse's *own* dashboard
components (`card.blade.php`, `scroll.blade.php`, `theme-switcher.blade.php`) use raw inline
Alpine expressions — `x-data="{ ... }"`, `@click="menu = !menu"`, `:class="theme === 'light' ? ...
: ..."` — which the CSP-safe Alpine build cannot evaluate at all (no `eval`/`new Function`
capability by design, unlike Livewire's own precompiled `wire:` directives it's meant for). With
`csp_safe` on, Alpine threw immediately trying to parse Pulse's UI, halting all page JS —
literally worse than the original bug. Reverted `csp_safe` to `false`, and instead scoped
`'unsafe-eval'` into `SecurityHeaders`'s CSP for just `/pulse` and `/livewire-*` routes (checked
via `$request->is()`) — every other page keeps the stricter policy unchanged. Verified via `curl`:
CSP header on `/pulse` includes `'unsafe-eval'`, homepage's doesn't; confirmed live through the
tunnel too (no config caching on this box, so no redeploy/cache-clear step needed).

**Files changed:** `config/livewire.php` (new, published) ·
`app/Http/Middleware/SecurityHeaders.php` · `claude.md`.

## M51 — Tabler icon font subset to the ~114 classes actually used (COMPLETED 2026-07-26)

User asked why the self-hosted Tabler Icons webfont "doesn't load easily" and whether SVG would
be better. Answer: it wasn't a WOFF-vs-SVG problem so much as a *scope* problem — the self-hosted
font (`public/vendor/tabler-icons/`) bundled the entire ~5,900-icon Tabler set (~892KB woff2 +
247KB CSS) when a `grep` across every Blade view found only **114 unique `ti-*` classes** actually
used anywhere in the app — roughly 2% utilization. Subsetting keeps the exact same class-based
markup (`<i class="ti ti-xxx">`, zero Blade changes) while dropping the font itself to what's
actually needed.

Built via a throwaway venv (`fonttools[woff]`, not an app dependency — a one-time asset-generation
step, output committed as static files): parsed the existing CSS's `.ti-name:before{content:"\eXXX"}`
rules to map class names → codepoints, subsetted the full `.ttf` down to just the 114 used
codepoints via `pyftsubset` (`--drop-tables+=GSUB,GPOS,GDEF --layout-features='' --no-hinting` —
the full font's GSUB table trips a `fontTools` assertion otherwise, and icon fonts have no real
layout features to lose anyway), converted to woff2/woff, and rebuilt a trimmed CSS with just the
`@font-face`/`.ti` base rules plus one `:before` rule per used class. Result: **247KB → ~5KB CSS,
892KB → ~22KB woff2** — roughly a 97% reduction. Verified the subset font's cmap contains exactly
114 glyphs (matching the kept CSS rules 1:1), and confirmed both files serve `200` through the live
tunnel.

Considered CDN instead, given the file is now tiny — decided against it. Self-hosting was
originally chosen specifically for reliability on a flaky/restrictive office network (see the
existing "Tabler Icons webfont" note in `head.blade.php`/`CLAUDE.md`), and that reasoning doesn't
change just because the file got smaller; if anything a CDN is now the comparatively *heavier*
dependency (an external network round-trip for a file that's cheaper to just serve locally).

**Bonus find:** one of the 115 `ti-*` classes grepped from the codebase, `ti-upload-off`
(`documents/bulk-upload.blade.php`), turned out not to be a real Tabler icon name in this version
at all — it was silently rendering nothing already, pre-existing and unrelated to this change.
Fixed to `ti-upload` (already in the used-icon set, no subset change needed for the fix itself).

Documented the going-forward policy in `CLAUDE.md`: before using a new `ti-*` class, check the
full set at tabler.io/icons (or the CDN copy, for reference only, never loaded on the page) to
confirm the exact name, then add its glyph to the subset — don't add the class and assume it
renders, and don't switch back to loading the full set for one new icon.

**Files changed:** `public/vendor/tabler-icons/tabler-icons.min.css` ·
`public/vendor/tabler-icons/fonts/tabler-icons-subset.{woff,woff2}` (new; old
`tabler-icons.{woff,woff2,ttf}` removed) · `resources/views/documents/bulk-upload.blade.php` ·
`resources/views/components/head.blade.php` · `claude.md`.

## M52 — document.show mobile polish; Laravel Pulse removed, its two useful cards ported natively (COMPLETED 2026-07-26)

**Mobile fixes on `documents/show.blade.php`, following on from M47/M48's mobile pass:**
- The Share/Edit/Convert/Delete action row was icon-only and left-cramped on mobile once it
  wrapped below the title (the parent's `justify-between` only works across two items on one
  line — a single wrapped row just lands at the start). Rebuilt as `flex-1` buttons that share
  the row's width evenly and grow to a normal inline row again at `sm:`. Delete also gained a
  visible label (was the only button with none, even on desktop).
- The Share dropdown's `right-0` anchor overflowed off the left edge of the viewport once the
  button itself sat near the screen's left edge (the mobile-wrapped row case above) — a
  right-anchored panel extends *leftward* from the button, so a narrow left-side button pushed
  it into negative X. Fixed with `left-0 sm:left-auto sm:right-0` — anchors left on mobile,
  right on desktop, matching wherever the button actually ends up.
- Amendments section reordered to appear right after the header/vault path **on mobile only**
  (`order-1`), instead of at the very bottom of the page below the Details/Status History
  sidebar — the single-column mobile stack was burying it below a 75vh PDF viewer. Desktop
  explicitly untouched (`lg:order-2`, same position as before) per direct instruction partway
  through this change (an earlier version of the fix moved it for all screen sizes; corrected
  once flagged).
- Status History converted to a native `<details>/<summary>` accordion (collapsed by default,
  both desktop and mobile) — the full transition history is useful but was always fully
  expanded taking up sidebar space nobody asked to see by default. No JS needed.

**Laravel Pulse removed** — see `claude.md`'s Pulse section for the full history (CSP/Alpine
incompatibility, then a genuine Livewire v4.3.3 `unserialize()` bug, both fixed in the previous
session) and the reasoning for removing it anyway: two non-trivial compatibility patches deep
for a monitoring package, on an app with exactly one server and one queue worker to watch, was
more maintenance burden than the dashboard was worth. `composer remove laravel/pulse
livewire/livewire --with-all-dependencies`; rolled back and deleted its migration; deleted
`config/pulse.php`, `config/livewire.php`, `resources/views/vendor/pulse/`; removed the
`pdf-pipeline-pulse.service` systemd unit; removed the `viewPulse` Gate from
`AppServiceProvider`, the sidebar link, and the `unsafe-eval` CSP carve-out from
`SecurityHeaders` (CSP is back to one uniform policy app-wide, no route-specific branching).

Before removing, checked which of Pulse's cards were actually worth keeping — only two:
**Exceptions** and **SlowQueries**. Both ported as native additions to the existing Pipeline
Health page rather than reinstalling a dashboard for them:
- `AppServiceProvider::configureSlowQueryLogging()` — `DB::whenQueryingForLongerThan(500, ...)`,
  built into Laravel core since 8.x, no package needed. Logs a `SLOW_QUERY` warning whenever a
  single request or job's *total* query time crosses 500ms.
- `DocumentController::recentLogSignals()` — tails the last 2MB of `storage/logs/laravel.log`
  (bounded read, stays cheap regardless of how large the log grows) and counts `.ERROR:` and
  `SLOW_QUERY` lines timestamped within the last hour.
- Surfaced as a new "App signals (last hour)" card on `documents.pipeline.health`, same
  traffic-light color coding (green/amber/red by count) as the existing server-vitals cards.

Servers/Queues/Cache/Usage/SlowJobs/SlowRequests/SlowOutgoingRequests weren't ported — server
vitals and queue backlog counts were already covered by the existing health page before Pulse
was ever installed, and the rest don't apply to a single-server, admin-only, no-outgoing-HTTP
app like this one.

**Files changed:** `resources/views/documents/show.blade.php` ·
`resources/views/documents/pipeline-health.blade.php` ·
`app/Http/Controllers/DocumentController.php` · `app/Providers/AppServiceProvider.php` ·
`app/Http/Middleware/SecurityHeaders.php` · `resources/views/components/sidebar.blade.php` ·
`composer.json`/`composer.lock` (Pulse + Livewire removed) · deleted `config/pulse.php`,
`config/livewire.php`, `database/migrations/2026_07_25_180203_create_pulse_tables.php`,
`resources/views/vendor/pulse/` · deleted `~/.config/systemd/user/pdf-pipeline-pulse.service`
(outside repo) · `claude.md`.

## M53 — Alpine.js adopted for live UI updates; Livewire evaluated and rejected (COMPLETED 2026-07-26)

Recurring complaint: pages needed a manual refresh to show anything current. Most concretely,
the OCR elapsed-timer on `documents/show.blade.php` reset to `0:00` on every page reload mid
conversion — even though the job had genuinely been running for minutes — making it look like
the job had silently restarted. Raised the question of adopting the TALL stack (Tailwind,
Alpine, Livewire, Laravel) to fix this class of problem properly.

**Livewire — evaluated, rejected.** This app already tried Livewire once this week, as Laravel
Pulse's dependency (M52), and hit two real compatibility bugs in a few days: a CSP/Alpine
`unsafe-eval` conflict and a Livewire v4.3.3 `unserialize()` bug forcing a version pin. It also
solves the wrong problem here — every interaction becomes a server round-trip with component
state serialization, which is real architectural weight for what turned out to be plain bugs
(a client-side timer seeded from the wrong value, a `<meta http-equiv="refresh">` standing in
for a targeted update), not a case where server-driven reactivity was actually missing.

**Alpine.js — adopted.** One `<script defer>` CDN include in `resources/views/components/head.blade.php`
(pinned `alpinejs@3.14.9`), no build step, no server round-trips — it wires already-existing
`fetch()` calls to reactive Blade-native markup instead of hand-rolled DOM-query JS:
- **`documents.pipeline.health`** — rewritten from a 15s full-page `<meta refresh>` (felt like
  the page "rebooting" just to show updated numbers) to an Alpine component
  (`pipelineHealthState()`) that polls the page's own `?format=json` endpoint every 15s and
  patches values/colors in place. Still fully server-rendered on first paint (works even before
  Alpine finishes loading); Alpine only keeps it current afterward. Root cause of the `@json()`
  compile failure hit while building this: Blade's `@json()` directive does a naive
  `explode(',', ...)` on its argument, so an inline multi-key array literal silently truncates —
  fixed by building the array as its own `$healthData` variable first and passing a bare
  reference to `@json()`.
- **`documents/show.blade.php` share dropdown** — `x-data="{ shareOpen, copied }"` with
  `@click.outside` / `@keydown.escape.window`, replacing ~30 lines of manual toggle/outside-click/
  Escape-key/clipboard-icon-swap JS with declarative bindings.
- **OCR elapsed-timer reset** — turned out to be a plain one-line JS bug, not something Alpine
  needed to solve: `startConversionPolling()` always seeded its counter from `Date.now()`, right
  for a just-clicked Convert button but wrong on page reload mid-conversion. Fixed by rendering
  the real conversion-start time (latest `DocumentStatusHistory.created_at` for the document)
  into a `data-started-at` attribute on `#markdown-card`, which the polling function now reads
  when present.

**Deliberately left as plain JS:** the global nav chrome in `layout.blade.php` (dark mode,
mobile sidebar drawer, sidebar collapse, nav tooltips) — it already works correctly, touches
every page in the app, and nothing about it was actually reported broken. Converting working,
low-risk, whole-app code to Alpine for its own sake would just be regression surface with no
functional gain. Same call on `documents/pipeline.blade.php`'s per-row status polling — it
already updates rows in place via `fetch()` without a full reload, so there was nothing to fix.

**Files changed:** `resources/views/components/head.blade.php` (Alpine CDN include, `[x-cloak]`
CSS rule) · `resources/views/documents/pipeline-health.blade.php` (full Alpine rewrite) ·
`resources/views/documents/show.blade.php` (share dropdown → Alpine, elapsed-timer seed fix) ·
`claude.md`, `README.md` (tech-stack rows, new "Frontend interactivity: Alpine, not Livewire"
section).

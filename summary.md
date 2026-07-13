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
| Admin | `shubhanraj2002@gmail.com` | `Admin@1234` | `['*']` — primary dev account |
| Admin (demo) | `admin.demo@excise.up.gov.in` | `Admin@1234` | `['*']` — Deputy Commissioner persona |
| Operator (full) | `operator.full@excise.up.gov.in` | `Operator@1234` | upload + edit + delete + restore + verify |
| Operator (upload-only) | `operator.upload@excise.up.gov.in` | `Operator@1234` | `['documents.upload']` — junior clerk |
| Operator (review/verify) | `operator.review@excise.up.gov.in` | `Operator@1234` | edit + verify — QA reviewer persona |
| Viewer | `viewer@excise.up.gov.in` | `Viewer@1234` | `[]` — read-only authenticated access |

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

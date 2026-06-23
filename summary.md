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
| `uploads` | 10/min | user ID or IP | `POST /documents` only — applied on top of `mutations` |

**Strict file upload validation (`StoreDocumentRequest`)**

Replaced `mimes:pdf` with `mimetypes:` (magic-byte check via PHP Fileinfo). Accepted MIME types defined as `ACCEPTED_MIMETYPES` public constant:
- **Documents:** `application/pdf`, `.doc`/`.docx`, `.xls`/`.xlsx`, `.ppt`/`.pptx`, `.odt`/`.ods`/`.odp`, `application/rtf`, `text/plain`, `text/csv`
- **Images:** `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `image/tiff`, `image/bmp`, `image/heic`, `image/heif`, `image/svg+xml`

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

# Project Summary

Running log of major milestones and architectural decisions. Minor tweaks are not recorded here — check git history for those.

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

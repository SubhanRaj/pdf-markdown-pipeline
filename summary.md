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

**Next:** queue job for PDF extraction (`markitdown`), OCR fallback logic, split-pane review UI, vault path resolution on verification.

---

## M4 — Document Upload UI & File Browser

**Schema additions** — `title` (string) and `document_type` (string enum: `go | policy | notice | court_order | service_code | other`) added directly to the `documents` migration. Both promoted to real columns (not metadata) as they are structurally stable and used for display/filtering.

**`Document` model** — `DOCUMENT_TYPES` and `STATUSES` constants added; both used across views and Form Requests without duplication.

**`StoreDocumentRequest`** — full validation: `section_id` (exists check), `title` (regex-guarded, strip_tags in prepareForValidation), `document_type` (in-list against constant keys), `file` (mimetypes: magic-byte checked, max 50 MB — see M5 for full type list). Server messages mapped per field.

**`DocumentController@store`** — vault directory resolved from department level + slug + section wing + section slug via `array_filter(implode(...))`. PDF stored to `local` disk at `uploads/{uuid}/original.pdf` before the DB transaction (file I/O is not transactional). On DB failure, uploaded file is deleted as best-effort cleanup. Status history row written inside same transaction. Redirects back to the originating section on success.

**`SectionController@show`** — now paginates documents (20/page, `withQueryString`). Public guests see only `status = verified` docs; authenticated users see all statuses.

**Section show page as dual-purpose hub** — the section page (`/departments/{dept}/sections/{section}`) now serves as both the public file browser and the authenticated upload point:
- Public view: document list (verified only), type badge, date, view action
- Auth view: all documents with status badges + uploader name + delete (admin)
- Upload panel: toggled by "Upload PDF" button; fields are Title, Document Type (select), PDF file with drag-and-drop zone; vault destination shown as a readable breadcrumb (`Vault › Department Level › Excise Department › Headquarter › Alcohol Section`)
- Upload submitted via `fetch` with 422 inline error surfacing; no page reload on validation failure
- JS validation mirrors server rules: title regex, type in-list, PDF-only + 50 MB size check, real-time on blur/input

**Sidebar UX** — restructured for auth state:
- Guests: "Browse Vault" section (specific dept links) + "Departments" section with "All Departments" link to `departments.index`
- Authenticated: "Browse Vault" + "Manage" section (Departments + Users for admin) — "Admin" label replaced with "Manage"
- Excise Department vault link now routes directly to the Excise dept show page (DB lookup with fallback to index if record absent); highlights as active on that dept's pages
- Non-admin guests never see the Manage section

**Vault path display** — raw slug paths removed from section headers and replaced with human-readable breadcrumb trails using department name, humanised wing, and section name throughout.

---

## M5 — Security Hardening: Rate Limiting & File Upload

**Rate limiting (`AppServiceProvider` + `routes/web.php`)**

Four named limiters defined in `AppServiceProvider::boot()` via `RateLimiter::for(...)`:

| Limiter | Limit | Key | Applied to |
|---|---|---|---|
| `login` | 5/min per email+IP AND 10/min per IP | email+IP / IP | Fortify login route (was referenced but undefined) |
| `two-factor` | 5/min | session login.id + IP | Fortify 2FA route |
| `mutations` | 60/min | user ID or IP | All auth-protected POST/PATCH/DELETE route groups |
| `uploads` | 10/min | user ID or IP | `POST /documents` only — applied on top of `mutations` |

The `login` and `two-factor` limiters were already named in `config/fortify.php` but had no definition — Fortify was silently falling back to a built-in default. Now they are explicit and tunable.

**Strict file upload validation (`StoreDocumentRequest`)**

Replaced `mimes:pdf` (extension-based check) with `mimetypes:` (magic-byte check via PHP Fileinfo). A renamed `.exe` will fail validation regardless of extension or client Content-Type. Accepted MIME types defined as `ACCEPTED_MIMETYPES` public constant on the Form Request:

- **Documents:** `application/pdf`, `.doc`/`.docx`, `.xls`/`.xlsx`, `.ppt`/`.pptx`, `.odt`/`.ods`/`.odp`, `application/rtf`, `text/plain`, `text/csv`
- **Images:** `image/jpeg`, `image/png`, `image/webp`, `image/gif`, `image/tiff`, `image/bmp`, `image/heic`, `image/heif`, `image/svg+xml`

Max size remains 50 MB (`max:51200`).

**Frontend alignment (`sections/show.blade.php`)**

File input `accept` attribute updated to all supported extensions. JS validation switched from PDF-only extension check to an `ACCEPTED_EXTS` `Set` — UX guard only; server enforces via magic bytes.

---

## M6 — Dashboard Auth-Aware Feed & Department Links

**Guest-safe document feed (`FrontendController@dashboard`)**

Recent-documents query now applies a conditional scope: guests receive only `status = verified` documents; authenticated users see all statuses. The `statusBreakdown` query (per-status counts) was removed — it was unused on the dashboard view after the status-breakdown widget was dropped.

**Department card links (`frontend/index.blade.php`)**

Department cards on the dashboard were previously `href="#"` placeholders. Each card now resolves its target at render time: if the card's slug matches a loaded `Department` record, it routes to `departments.show`; otherwise it falls back to `departments.index`. No new DB query — uses the `$departments` collection already fetched for stat counts.

**Empty-state CTA updated**

The zero-documents empty state CTA was changed from "Convert your first PDF" (which linked to `#`) to "Browse Departments" linking to `departments.index`, accurately reflecting the upload flow (upload is initiated from a section page, not the dashboard).

**Document feed display**

Recent-document rows now show `$doc->title` (the human-readable document title set at upload) instead of `$doc->original_filename`. A document-type label is also appended alongside the department and section name in the subtitle row, using the `Document::DOCUMENT_TYPES` constant for display.

---

## M7 — Slug-Based URLs, Section Module, Document Views & Upload Fix

### Slug-based routing (all models)

`Department`, `Section`, and `Document` models all now override `getRouteKeyName()` to return `'slug'`. IDs no longer appear in any public URL. The sidebar's excise-dept DB lookup was updated to select `['id', 'slug']` — previously only `['id']` was fetched, which caused `UrlGenerationException` once `getRouteKeyName()` changed.

### Route ordering — static before wildcard

Any static path segment (e.g. `/create`, `/upload`) that sits next to a `/{wildcard}` route **must** be registered before the wildcard, or Laravel matches the string `"create"` as a model slug and 404s. Fixed for:
- `GET /departments/create` — moved into the public group with inline `->middleware(['auth', 'throttle:mutations'])` before `GET /departments/{department}`
- `GET /departments/{department}/sections/create` — same fix before `GET …/sections/{section}`

This is the canonical pattern for all future static+wildcard pairings in this codebase.

### Document slug column

`slug` column added to `documents` table (in-place migration edit per CLAUDE.md convention). Unique constraint on `(section_id, slug)`. `Document::uniqueSlugForSection($title, $sectionId)` auto-generates a URL-safe slug from the title, querying `withTrashed()` to avoid reusing slugs of soft-deleted docs, appending `-2`/`-3` etc. on collision.

### Hierarchical document URLs

Document routes changed from flat `/documents/{document}` to `/documents/{department}/{section}/{document}`, mirroring the vault path hierarchy. All three segments are slug-bound. All views and route helpers updated accordingly.

### Section module

`SectionController` built with full CRUD. `sections/create.blade.php`, `sections/edit.blade.php`, and `sections/show.blade.php` implemented. Show page is the dual-purpose file browser + upload modal. Sections are nested under departments in both URLs and route names (`departments.sections.*`).

### Document views

- **`documents/show`** — hierarchical breadcrumb (Home → Departments → Dept → Section → Title), inline PDF embed (iframe, 75vh) via controller-streamed route, extracted Markdown rendered below once available, metadata + status history sidebar (auth only), admin Review/Delete.
- **`documents/index`** — tabbed by department with document count badges; auth users see all statuses, guests see verified only.

### PDF streaming

`GET /documents/{department}/{section}/{document}/pdf` — `DocumentController@pdf` streams the file from the `local` disk via `Storage::disk('local')->response()` with `Content-Disposition: inline`. Guests blocked (403) on non-verified documents. File is never served from `public` disk.

### Upload AJAX fix — always JSON

`POST /documents` is now an AJAX-only endpoint that always returns JSON, regardless of `Accept` header:
- `DocumentController@store` return type changed to `JsonResponse` only — no dual `$ajax ? json : redirect` branching.
- `StoreDocumentRequest::failedValidation()` overridden to throw `HttpResponseException` with 422 JSON body, bypassing Laravel's default redirect on validation failure.
- JS `fetch` sends `Accept: application/json` + `X-CSRF-TOKEN` header + `X-Requested-With: XMLHttpRequest`.
- File input outside the `<form>` element (left column of two-column modal) — `new FormData(form)` does NOT capture it. File is explicitly appended via `formData.append('file', fileInput.files[0])`.
- Form has `method="POST"` and `action` as hard fallback to prevent GET submission if JS fails.
- JS init block wrapped in `try/catch` so a JSON parse error doesn't silently leave the form unprotected.

### `DocumentStatusHistory` cast fix

`$timestamps = false` on the model disabled auto-casting, leaving `created_at` as a raw string. Added `protected $casts = ['created_at' => 'datetime']` so Carbon methods work correctly.

---

## M8 — Dynamic Sidebar Browse Vault

**Browse Vault section made fully dynamic.**

Previously, sidebar department links were hardcoded in `sidebar.blade.php` — adding a new department required a manual sidebar edit, and non-Excise entries pointed to `departments.index` instead of the actual dept page.

`sidebar.blade.php` now queries all `Department` records (ordered by level, then name) and renders each as a `departments.show` link. A `$deptMeta` map (slug → icon + color) provides distinct icons for known departments; any slug not in the map falls back to a cycling palette of icons/colors. The active-link highlight checks both `routeIs()` and the current route's `{department}` slug parameter so the correct entry highlights when browsing that dept's sections or documents.

**Slug key convention:** map keys use underscores to match DB slugs (e.g. `sugarcane_sugar`, `sugar_mill_corp`, `cane_federation`). This was the root cause of the initial icon regression — the map was written with hyphens before the actual DB slugs were verified.

---

## M9 — Storage Consolidation: Vault-First, Public Disk, Slug-Named Files

**What changed:** Eliminated the separate `uploads/{uuid}/original.pdf` staging pattern. All document files now go directly into the vault directory on the `public` disk, using the document slug as the filename.

**New file path convention:**
```
storage/app/public/document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/{slug}_{YmdHis}.pdf
storage/app/public/document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/{slug}_{YmdHis}.md   ← future markdown
```

**Key changes:**

- **Disk**: `local` (`storage/app/private/`) → `public` (`storage/app/public/`). Symlink created via `php artisan storage:link`.
- **Filename**: `{slug}_{YmdHis}.pdf` instead of `original.pdf` inside a UUID folder. Slug is generated before file I/O so the filename is determined before the file is written. Collision prevention: `uniqueSlugForSection` appends `-2`/`-3` on title collision; the timestamp suffix prevents any remaining overwrites.
- **`original_pdf_path`**: now the full relative vault path (e.g. `document_vault/department_level/excise/headquarter/accounts_section/beer-retail-2021_20260621143022.pdf`).
- **`vault_path`**: directory only (unchanged semantics, used by extraction jobs to know where to write `.md`).
- **Markdown**: will be stored in the same directory with the same base name and `.md` extension. `show.blade.php` markdown check updated from `file_exists(storage_path('app/...'))` to `Storage::disk('public')->exists(...)`.
- **PDF streaming**: `DocumentController@pdf` updated to `Storage::disk('public')`. Auth gate (403 for guests on non-verified docs) unchanged — always link to `/pdf` route, never raw storage URL.
- **Cleanup on failure**: `Storage::disk('public')->delete($pdfPath)` (single file delete) instead of `deleteDirectory("uploads/{uuid}")`.
- **Existing data**: 1 document migrated via tinker — file copied from `private/uploads/{uuid}/original.pdf` to new vault path, DB record updated.

**Why**: Single storage location for PDF + future Markdown makes the vault a proper file repository. Slug-named files are human-readable in the filesystem. No wasted UUID directories. Aligns storage layout with the logical document hierarchy already expressed in the DB.

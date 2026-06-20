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

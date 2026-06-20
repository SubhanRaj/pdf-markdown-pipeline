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

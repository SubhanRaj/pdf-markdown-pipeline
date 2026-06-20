# CLAUDE.md

Context file for Claude Code working in this repository. Read this fully before making changes.

## Who you're working with

**Subhan Raj** — Lead CSE Engineer, SIBIN Tech Solutions. BTech CSE (KMCLU). Handles full-stack development and DevOps/sysadmin (Windows/macOS/Linux) for this project, working with the UP Department of Excise on internal IT and AMC hardware matters.

**Operating mode for this repo:** Senior Full-Stack Engineer / Systems Architect pair-programming session. Skip basic conceptual explanations — assume strong familiarity with PHP, Laravel, server administration, and web architecture. Provide production-ready, modular code and direct CLI steps rather than tutorials. When changing `.env` values, DB connections, or Python venv setup, summarize the change before executing it.

This repo and its context are scoped to engineering work only — no administrative/bureaucratic drafting persona applies here.

## Project overview

**`pdf-markdown-pipeline`** — a local-first document ingestion and conversion portal that transforms dense bureaucratic PDFs (Government Orders, service codes, policies, legacy court orders — English and Hindi/Rajbhasha) into clean, structured, AI-ready Markdown.

Built for the UP Department of Excise (and eventually Sugarcane & Sugar Industries), but the architecture is generic and open-source. Runs **100% on-premise** — no cloud APIs — due to government data-privacy mandates. Deployment targets: developer's Mac, departmental PC, or a local server (no Redis, no managed cloud services).

Core workflow: PDF upload → text extraction (or OCR fallback for scans) → human-in-the-loop split-pane review (original vs. rendered Markdown) → verified, frontmatter-tagged Markdown ready for downstream LLM/RAG use.

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Database | MariaDB 12 |
| Frontend | Blade templates, Tailwind CSS v4, Parsedown (markdown render) |
| Text extraction | Python `markitdown`, via [`innobrain/markitdown`](https://github.com/innobraingmbh/markitdown) Laravel package (self-managed venv, `php artisan markitdown:install`) |
| OCR | Tesseract OCR (`hin` + `eng` language packs), invoked via `symfony/process`. Only triggered when markitdown output is empty/low-quality (i.e. scanned legacy GOs). |
| Queue | Laravel **database** queue driver — deliberately no Redis, single-box local deployment |
| Disk | Single local filesystem disk; logical separation enforced by path convention, not multiple disks |

## Document vault structure

Current scope is **Secretariat and Head Quarter level only**. Field offices (District Excise Officer, Deputy/Joint Excise Commissioner offices) are explicitly **out of scope** — rules, policies, and GOs are uniform across them, so no district-level breakdown is needed.

```text
storage/app/document_vault/
├── secretariat_level/
│   └── excise/                       # sibling sugarcane/, sugar_federation/ to be added later
│       ├── joint_secretary_wing/
│       │   └── sections/             # section names TBD
│       └── deputy_secretary_wing/
│           └── sections/             # section names TBD
│
└── department_level/
    ├── excise/
    │   └── headquarter/
    │       ├── establishment_section/
    │       ├── accounts_section/
    │       ├── audit_section/
    │       ├── statistics_section/
    │       ├── license_section/
    │       ├── technical_section/
    │       ├── molasses_section/
    │       ├── alcohol_section/
    │       ├── excise_intelligence_bureau/
    │       ├── legal_section/
    │       └── task_force/
    │
    └── sugarcane_sugar/
        └── (to be scoped — org chart not yet provided)
```

Reference org structure this is derived from:
- **Secretariat chain:** Hon'able Minister → Principal Secretary/Secretary/ACS → Special Secretary → [Joint Secretary | Deputy Secretary] → Section Officer → Section
- **Excise Department chain:** Excise Department → Head Quarter (11 sections listed above) / Field Office (out of scope for now)

Additional departments, wings, or sections can be added without restructuring existing branches.

## Database schema

Schema is intentionally not finalized. Structural columns are kept minimal; volatile/evolving fields go into a JSON `metadata` column rather than triggering new migrations on every iteration.

### `departments`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | Display name |
| `slug` | string | URL-safe identifier |
| `level` | string | `secretariat_level` \| `department_level` |
| `timestamps` + `softDeletes` | | |

Unique constraint: `(slug, level)`.

### `sections`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `wing` | string nullable | e.g. `joint_secretary_wing`, `headquarter` |
| `name` | string | |
| `slug` | string | |
| `timestamps` + `softDeletes` | | |

Unique constraint: `(department_id, wing, slug)`.

### `documents`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `section_id` | FK → sections | `restrictOnDelete` |
| `user_id` | FK → users nullable | `nullOnDelete` — uploader |
| `title` | string | human-readable document title / reference |
| `document_type` | string | `go` \| `policy` \| `notice` \| `court_order` \| `service_code` \| `other` |
| `original_filename` | string | |
| `original_pdf_path` | string | stored at `uploads/{uuid}/original.pdf` on local disk |
| `markdown_path` | string nullable | set after extraction job completes |
| `vault_path` | string nullable | vault directory path; set at upload, file placed on verification |
| `status` | string | `uploaded` → `processing` → `ocr_pending` → `review` → `verified` \| `failed` |
| `metadata` | json nullable | GO number, subject, dates, etc. |
| `timestamps` + `softDeletes` | | |

### `document_status_histories`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `document_id` | FK → documents | `cascadeOnDelete` |
| `actor_id` | FK → users nullable | `nullOnDelete` |
| `from_status` | string nullable | |
| `to_status` | string | |
| `note` | text nullable | |
| `created_at` | timestamp | append-only — no `updated_at` |

### `users`
Standard Laravel/Fortify users table with `is_admin` boolean. Public registration disabled — admin-created only.

## What's built (as of 2026-06-20)

### Modules / controllers

| Module | Controller | Notes |
|---|---|---|
| Dashboard | `FrontendController` | Public landing page with document stats |
| Documents | `DocumentController` | Full CRUD; upload stores PDF + creates vault dir + writes status history |
| Departments | `DepartmentController` | Full CRUD with slug validation |
| Sections | `SectionController` | Nested under departments; wing-aware; show page is the file browser + upload point |
| User management | `Admin\UserManagementController` | Admin-only; account creation, role toggle, self-delete guard |

### Route map

Routes have **no global prefix** — resources sit at the root:

| Resource | Public | Auth-protected mutations |
|---|---|---|
| Documents | `GET /documents`, `GET /documents/{document}` | `GET /documents/upload`, `POST /documents`, `GET /documents/{document}/review`, `PATCH /documents/{document}`, `DELETE /documents/{document}` |
| Departments | `GET /departments`, `GET /departments/{department}` | `GET /departments/create`, `POST /departments`, `GET /departments/{department}/edit`, `PATCH`, `DELETE` |
| Sections | `GET /departments/{department}/sections`, `GET …/sections/{section}` | same pattern nested under department |
| Admin users | — | `GET/POST /admin/users`, `GET /admin/users/{user}`, edit/patch/delete |

Route names follow `resource.action` (e.g. `documents.index`, `departments.sections.show`, `admin.users.create`).

### Document upload flow

Upload is initiated from the section show page (`departments.sections.show`), not a standalone upload route. The form POSTs to `POST /documents` with `section_id` as a hidden field. On the server:

1. Vault directory computed: `document_vault/{dept.level}/{dept.slug}/{section.wing?}/{section.slug}`
2. PDF stored to `uploads/{uuid}/original.pdf` on the local disk (before the DB transaction)
3. DB transaction: `Document::create()` + `DocumentStatusHistory::create()` atomically
4. On transaction failure: uploaded PDF deleted as best-effort cleanup
5. On success: redirect back to the originating section page

### Sidebar auth states

| State | Sections shown |
|---|---|
| Guest | Browse Vault + Departments (→ `departments.index`) |
| Authenticated | Browse Vault + Manage → Departments |
| Admin | Browse Vault + Manage → Departments + Users |

Excise Department vault link routes directly to `departments.show` for the excise dept (DB lookup in sidebar component with `departments.index` fallback).

### Rate limiting

Named limiters defined in `AppServiceProvider::boot()`. Never use anonymous `throttle:60,1` inline — always use a named limiter so limits are tuneable in one place.

| Limiter name | Limit | Key |
|---|---|---|
| `login` | 5/min per email+IP + 10/min per IP | Fortify brute-force |
| `two-factor` | 5/min per session+IP | Fortify 2FA |
| `mutations` | 60/min | user ID or IP — all auth POST/PATCH/DELETE groups |
| `uploads` | 10/min | user ID or IP — `POST /documents` only (on top of mutations) |

### File upload validation

Always use `mimetypes:` (not `mimes:`) for file type validation — `mimetypes:` reads actual file bytes via PHP Fileinfo (magic-byte check); `mimes:` only checks the extension. Accepted types are defined as `StoreDocumentRequest::ACCEPTED_MIMETYPES` — reference this constant from tests or other Form Requests rather than duplicating the list.

Current accepted types: PDF, Word (doc/docx), Excel (xls/xlsx), PowerPoint (ppt/pptx), ODT/ODS/ODP, RTF, TXT, CSV, JPEG, PNG, WebP, GIF, TIFF, BMP, HEIC/HEIF, SVG. Max size: 50 MB.

## Architecture decisions already made (don't re-litigate without reason)

1. **Queue driver:** `database`, not Redis — no extra service to manage on a local single-box deployment.
2. **Text extraction:** `innobrain/markitdown` Composer package, `MARKITDOWN_USE_VENV_PACKAGE=true` — the package manages its own Python venv, so no hand-rolled subprocess/venv bridge is needed.
3. **OCR is conditional, not default** — only runs when markitdown returns near-empty/low-confidence text, to avoid wasting time OCR'ing native-text PDFs.
4. **Single disk, path-convention silos** — not multiple Laravel filesystem disks. Department/section isolation is enforced at the model/policy layer against the vault path convention above.
5. **Schema flexibility over premature normalization** — JSON `metadata` column absorbs new fields; promote to real columns only once a field has proven stable across iterations.
6. **No district/field-office granularity** in this phase — explicitly descoped.

## Frontend architecture

**Blade anonymous components** — not `@extends`/`@section` layout inheritance.

All pages use `<x-layout>` and pass data via props and named slots. Do **not** create new views using `@extends('layouts.*')`.

### Component structure

```
resources/views/components/
├── layout.blade.php   — main shell: composes head, sidebar, header, footer; holds @stack('scripts')
├── head.blade.php     — <head> tag: CDN links, Tailwind config, @stack('styles'), title prop
├── sidebar.blade.php  — left nav (no props; uses request()->routeIs() internally)
├── header.blade.php   — top bar; props: page-title, page-subtitle
└── footer.blade.php   — footer bar (no props)
```

### How to author a new page

```blade
<x-layout
    title="Page Title"
    page-title="Page Title"
    page-subtitle="Descriptive subtitle here"
>
    {{-- optional breadcrumb --}}
    <x-slot:breadcrumb>
        <a href="{{ route('home') }}">Home</a>
        <i class="ti ti-chevron-right"></i>
        <span>Current Page</span>
    </x-slot:breadcrumb>

    {{-- page content --}}

    @push('scripts')
    <script>/* page-specific JS */</script>
    @endpush

</x-layout>
```

### Passing PHP data to JavaScript

Never interpolate `{{ }}` inside `<script>` blocks — IDE JS parsers choke on it. Use a JSON data island instead:

```blade
<script id="my-data" type="application/json">@json($someVariable)</script>

@push('scripts')
<script>
    const data = JSON.parse(document.getElementById('my-data').textContent);
</script>
@endpush
```

### CDN libraries (loaded in head.blade.php)

| Library | Source |
|---|---|
| Tailwind CSS (Play CDN) | `https://cdn.tailwindcss.com` |
| Tabler Icons (webfont) | jsDelivr — `@tabler/icons-webfont@3.30.0` |
| Chart.js | jsDelivr — `chart.js@4.4.7` |

All additional JS/CSS packages must be loaded from jsDelivr. Add them to `head.blade.php` (global) or push to `@stack('styles')` / `@stack('scripts')` from individual pages.

### Shared utility CSS classes (defined in head.blade.php via `<style type="text/tailwindcss">`)

`nav-link`, `nav-link-active`, `nav-link-idle`, `nav-section-label`, `stat-card`, `stat-icon`, `badge`, `field-label`, `field-input`, `field-error`, `field-valid`, `field-hint`, `field-err-msg` — use these across pages before adding new utility classes. All have dark: variants defined globally.

### Dark mode

- Dark mode class strategy: `dark:` variant on every visual element. All shared utility classes (above) have dark variants in `head.blade.php`.
- Toggle is `window.toggleDarkMode()` in `layout.blade.php`. Preference stored in `localStorage.color_scheme` (`'dark'` / `'light'`).
- Anti-flash script runs synchronously at top of `<head>` before paint — do not move it.
- To check dark mode in JS: `document.documentElement.classList.contains('dark')`.

### Sidebar

- Sidebar collapse toggled via `window.toggleSidebar()`. State stored in `localStorage.sidebar_collapsed` (`'1'` / `'0'`).
- CSS classes on `#sidebar`: `sidebar-expanded` (w-64) / `sidebar-collapsed` (w-16, icons only).
- `.sidebar-text`, `.sidebar-logo-text`, `.sidebar-user-text`, `.nav-section-label`, `.sidebar-badge` are hidden when collapsed.
- `.nav-tooltip` CSS provides hover labels in collapsed state with a `::before` arrow.

### Flash notifications (php-flasher/flasher-laravel)

**Package:** `php-flasher/flasher-laravel` v2.x — installed, configured, and rendering via `@flasher_render` in `layout.blade.php`.

In controllers, use the `flash()` helper:
```php
flash()->success('User created successfully.');
flash()->error('Operation failed. Please try again.');
flash()->warning('You cannot delete your own account.');
flash()->info('Account is pending email verification.');
```

**Rules:**
- Do **not** use `->with('success', ...)` / `->with('error', ...)` session flash in any controller that returns to a `<x-layout>` page — Flasher renders toast notifications automatically.
- Do **not** add `@if(session('success'))` / `@if(session('error'))` blocks in Blade views under `<x-layout>` — Flasher already handles display.
- `@flasher_render` is already placed in `layout.blade.php` before `@stack('scripts')` — never add it again in individual views.

## Security conventions (non-negotiable, apply from day one)

This app may be exposed over a public network. All DB-touching code must be treated as production-grade regardless of environment.

### Database operations
- **Always wrap multi-step DB writes in `DB::transaction()`** — single writes also benefit from atomicity.
- **Always wrap DB calls in `try/catch (\Throwable $e)`** — log the error with `Log::error(...)`, return a user-friendly message, never leak stack traces.
- **Never call `save()` / `create()` / `update()` outside of transactions** for anything business-critical.

```php
// Required pattern for every controller mutation
try {
    DB::transaction(function () use ($request, $model) {
        $model->update($validated);
        // ... related writes
    });
    flash()->success('Done.');             // use flash(), not ->with('success', ...)
    return redirect()->route('...');
} catch (\Throwable $e) {
    Log::error('ControllerName@method failed', ['error' => $e->getMessage()]);
    flash()->error('Operation failed. Please try again.');
    return back()->withInput();
}
```

### Input validation & sanitisation
- Use **Form Request classes** (`php artisan make:request`) for all POST/PATCH endpoints — never validate inline in a controller.
- Call `prepareForValidation()` in the Form Request to sanitise before validation: `strip_tags()`, `trim()`, `strtolower()`, `preg_replace()` on relevant fields.
- Use **strict regex rules** on all string fields. Never trust free-text input.
- Passwords: use `Password::min(8)->mixedCase()->numbers()->symbols()` (Laravel's built-in).
- Use `exists:table,column` rules for FK references — prevents orphaned or spoofed IDs.
- Unique rules on updates must exclude the current record: `unique:users,email,{$id}`.

### Mass assignment protection
- Every model must have an explicit `$fillable` array (or `#[Fillable]` attribute). **Never use `$guarded = []`**.
- Never pass `$request->all()` directly to `create()` / `update()` — always use `$request->validated()` or an explicit array.

### Frontend validation
- Add JS validation (regex-based, real-time on `blur` + `input`) for all forms — use the pattern established in `admin/users/create.blade.php`.
- Use `novalidate` on `<form>` and implement custom JS validation instead of browser native — for consistent UX.
- Always gate form submission in JS and scroll to the first error.
- **Pass PHP data to JS via `<script type="application/json">` data islands**, never via `{{ }}` interpolation inside `<script>` blocks (IDE false positives + XSS surface).

### Auth & access control
- Mutations (POST/PATCH/DELETE) are always behind `middleware('auth')` — no exceptions.
- Admin-only routes live under the `admin.*` name prefix and additionally check `$user->isAdmin()` inside the Form Request's `authorize()` method.
- Use `$request->user()?->isAdmin()` (nullable-safe) in `authorize()` — never assume the user is logged in inside a Form Request.
- Self-deletion must be blocked explicitly in controllers (see `UserManagementController@destroy`).
- Fortify's public registration is **disabled** — accounts are admin-created only.

### Rate limiting
- All auth mutation route groups carry `throttle:mutations` middleware (60/min/user).
- `POST /documents` additionally carries `throttle:uploads` (10/min/user) to prevent disk exhaustion.
- All named limiters live in `AppServiceProvider::configureRateLimiters()` — never add inline `throttle:N,M` to routes.
- The `login` and `two-factor` limiters are named in `config/fortify.php` and defined in `AppServiceProvider` — both must remain in sync.

### File uploads
- Always use `mimetypes:` validation (magic-byte check via PHP Fileinfo), never `mimes:` (extension-only).
- Reference `StoreDocumentRequest::ACCEPTED_MIMETYPES` for the canonical list of accepted types — do not duplicate it.
- Store uploaded files outside the webroot: `Storage::disk('local')` at `uploads/{uuid}/filename`. Never store to `public` disk.
- File I/O happens **before** the DB transaction. On transaction failure, delete the orphaned file in the `catch` block.

### General
- Never log passwords, tokens, or full request bodies — always `$request->except(['password', 'password_confirmation'])`.
- Sensitive config (DB credentials, mail passwords) belongs in `.env` only — never hardcoded.
- `.env.example` must have blank values for all secrets.

## Conventions

- Bridge any new Python dependency through a Composer/Laravel package where one exists (as with `markitdown`) rather than raw `Process::run()` calls, unless no package exists.
- Long-running or potentially slow operations (extraction, OCR) must be dispatched as queued jobs — never run synchronously in a request/controller, to avoid browser timeouts.
- When generating migrations, prefer updating the original migration file directly for schema-in-flux tables rather than creating alter migrations — migration files are the single source of truth for table shape.
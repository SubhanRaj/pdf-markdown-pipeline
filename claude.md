# CLAUDE.md

Context file for Claude Code working in this repository. Read this fully before making changes.

## Who you're working with

**Subhan Raj** — Lead CSE Engineer, SIBIN Tech Solutions. BTech CSE (KMCLU). Handles full-stack development and DevOps/sysadmin (Windows/macOS/Linux) for this project, working with the UP Department of Excise on internal IT and AMC hardware matters.

**Operating mode for this repo:** Senior Full-Stack Engineer / Systems Architect pair-programming session. Skip basic conceptual explanations — assume strong familiarity with PHP, Laravel, server administration, and web architecture. Provide production-ready, modular code and direct CLI steps rather than tutorials. When changing `.env` values, DB connections, or Python venv setup, summarize the change before executing it.

This repo and its context are scoped to engineering work only — no administrative/bureaucratic drafting persona applies here.

## Project overview

**`pdf-markdown-pipeline`** — a local-first document ingestion and conversion portal that transforms dense bureaucratic PDFs (Government Orders, service codes, policies, Acts, Rules, amendments — English and Hindi/Rajbhasha) into clean, structured, AI-ready Markdown.

Built for the UP Department of Excise (and eventually Sugarcane & Sugar Industries), but the architecture is generic and open-source. Runs **100% on-premise** — no cloud APIs — due to government data-privacy mandates. Deployment targets: developer's Mac, departmental PC, or a local server (no Redis, no managed cloud services).

Core workflow: PDF upload → text extraction (or OCR fallback for scans) → human-in-the-loop split-pane review (original vs. rendered Markdown) → verified, frontmatter-tagged Markdown ready for downstream LLM/RAG use.

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Database | MariaDB 12 |
| Web server | Apache (mod_php or php-fpm via mod_proxy_fcgi) — **no Nginx** |
| Frontend | Blade templates, Tailwind CSS v4 (Play CDN), Parsedown (markdown render) — **no Node, no npm, no build step** |
| Text extraction | Python `markitdown`, via [`innobrain/markitdown`](https://github.com/innobraingmbh/markitdown) Laravel package (self-managed venv, `php artisan markitdown:install`) |
| OCR | Tesseract OCR (`hin` + `eng` language packs), invoked via `symfony/process`. Only triggered when markitdown output is empty/low-quality (i.e. scanned legacy GOs). |
| Queue | Laravel **database** queue driver — deliberately no Redis, single-box local deployment |
| Disk | Single local filesystem disk (`public`); logical separation enforced by path convention, not multiple disks |

## PHP upload limits

PHP's defaults (2 MB upload, 8 MB POST) block real document uploads. Four directives must be raised. Three options, in order of preference for this project:

**Option A — `public/.htaccess`** (already in the repo, works immediately for Apache + mod_php, no restart needed)
```apache
<IfModule mod_php.c>
    php_value upload_max_filesize 64M
    php_value post_max_size       64M
    php_value max_execution_time  120
    php_value max_input_time      120
</IfModule>
```
Requires `AllowOverride All` (or `AllowOverride Options FileInfo`) in the Apache vhost/Directory block — otherwise `.htaccess` is silently ignored.

**Option B — `public/.user.ini`** (works for both mod_php and php-fpm, no Apache directive needed, ~5 min TTL)
```ini
upload_max_filesize = 64M
post_max_size       = 64M
max_execution_time  = 120
max_input_time      = 120
```

**Option C — system `php.ini`** (cleanest for a dedicated on-premise server; requires Apache/fpm restart to apply)
- macOS/Homebrew: `/usr/local/etc/php/8.x/php.ini` → `brew services restart httpd`
- Debian/Ubuntu: `/etc/php/8.x/apache2/php.ini` → `sudo systemctl restart apache2`
- RHEL/CentOS: `/etc/php.ini` → `sudo systemctl restart httpd`

`post_max_size` must always be ≥ `upload_max_filesize`. Apache has no `client_max_body_size` (that's Nginx); PHP is the only upload gatekeeper here.

## Document vault structure

Current scope is **Secretariat and Head Quarter level only**. Field offices (District Excise Officer, Deputy/Joint Excise Commissioner offices) are explicitly **out of scope** — rules, policies, and GOs are uniform across them, so no district-level breakdown is needed.

```text
storage/app/document_vault/
├── secretariat_level/
│   └── excise/                       # sibling sugarcane/, sugar_federation/ to be added later
│       ├── joint_secretary_wing/
│       │   └── sections/
│       └── deputy_secretary_wing/
│           └── sections/
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
    │       ├── task_force/
    │       └── rules/
    │           └── {rule-set-slug}/   # Acts, Rules, and their amendments
    │
    └── sugarcane_sugar/
        └── (to be scoped — org chart not yet provided)
```

**Section-based file path:** `document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/{slug}_{YmdHis}.pdf`

**Rule-set-based file path:** `document_vault/{level}/{dept_slug}/rules/{rule_set_slug}/{slug}_{YmdHis}.pdf`

Reference org structure this is derived from:
- **Secretariat chain:** Hon'able Minister → Principal Secretary/Secretary/ACS → Special Secretary → [Joint Secretary | Deputy Secretary] → Section Officer → Section
- **Excise Department chain:** Excise Department → Head Quarter (11 sections listed above) / Field Office (out of scope for now)

Additional departments, wings, sections, or rule sets can be added without restructuring existing branches.

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

### `rule_sets`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `name` | string | Full name of the Act/Rule (e.g. *U.P. Excise Act 1910*) |
| `slug` | string | Auto-generated from name; unique per department |
| `description` | text nullable | Optional summary (max 500 chars) |
| `metadata` | json nullable | Category, origin year, etc. |
| `timestamps` + `softDeletes` | | |

Unique constraint: `(department_id, slug)`. Slug generated via `RuleSet::uniqueSlugForDepartment($name, $departmentId)` — checks `withTrashed()` to avoid reusing slugs of soft-deleted rule sets.

### `documents`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `section_id` | FK → sections **nullable** | `restrictOnDelete` — null for rule-set docs |
| `rule_set_id` | FK → rule_sets **nullable** | `nullOnDelete` — null for section-based docs |
| `user_id` | FK → users nullable | `nullOnDelete` — uploader |
| `title` | string | human-readable document title / reference |
| `slug` | string | URL-safe; auto-generated from title at upload; unique per section or per rule set |
| `document_type` | string | `go` \| `policy` \| `notice` \| `court_order` \| `service_code` \| `rule_amendment` \| `other` |
| `original_filename` | string | |
| `original_pdf_path` | string | full relative path on `public` disk |
| `markdown_path` | string nullable | set after extraction job completes |
| `vault_path` | string nullable | vault directory path; set at upload |
| `status` | string | `uploaded` → `processing` → `ocr_pending` → `review` → `verified` \| `failed` |
| `visibility` | string | `public` (default) \| `authenticated` — controls guest access independently of status |
| `metadata` | json nullable | GO number, subject, dates, etc. |
| `timestamps` + `softDeletes` | | |

Exactly one of `section_id` or `rule_set_id` is non-null per row. Slug helpers:
- Section docs: `Document::uniqueSlugForSection($title, $sectionId)` — unique within `(section_id, slug)`.
- Rule-set docs: `Document::uniqueSlugForRuleSet($title, $ruleSetId)` — unique within `(rule_set_id, slug)`.
Both check `withTrashed()` and append `-2`, `-3` on collision.

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

## What's built (as of 2026-06-22, updated)

### Modules / controllers

| Module | Controller | Notes |
|---|---|---|
| Dashboard | `FrontendController` | Public landing page with document stats; auth-aware recent feed |
| Documents | `DocumentController` | Full CRUD; AJAX-only store (handles both section and rule-set uploads); PDF stream; hierarchical URLs; slug generation; rule-set doc methods; soft-delete with reason; trash/restore/force-delete |
| Departments | `DepartmentController` | Full CRUD; slug-based route model binding; loads rule sets for show page |
| Sections | `SectionController` | Nested under departments; wing-aware; show page is the file browser + upload modal |
| Rule Sets | `RuleSetController` | Full CRUD; admin-only mutations; upload modal on show page pre-selects `rule_amendment` type |
| Search | `SearchController` | Public `GET /search?q=`; LIKE-based search across document titles, section names, rule set names/descriptions; guests see verified docs only; results capped at 50 docs + 20 sections + 20 rule sets |
| User management | `Admin\UserManagementController` | Admin-only; account creation, role toggle, self-delete guard |

### Route map

Routes have **no global prefix** — resources sit at the root. All models use `getRouteKeyName()` returning `'slug'` — IDs never appear in URLs.

**`{level}` URL segment** — departments share slugs across levels (e.g. `excise` exists at both `department_level` and `secretariat_level`). A `{level}` alias is inserted before `{department}` in every department/section/rule-set/document URL:
- `dept` → `department_level`
- `sectt` → `secretariat_level`

`Route::bind('department', ...)` in `AppServiceProvider::configureRouteBindings()` reads `request()->route('level')`, converts the alias to the DB value, and queries `WHERE slug = ? AND level = ?`.

`Route::bind('rule_set', ...)` scopes rule set lookups to `WHERE slug = ? AND department_id = ?` using the already-resolved `{department}` from the same request.

Controller method signatures **must** declare `string $level` as their first parameter (before model arguments) for any route containing `{level}`, or Laravel throws a `TypeError`.

`Department::levelAlias()` → URL alias for route helpers. `Department::levelLabel()` → human label for breadcrumbs.

| Resource | Public | Auth-protected mutations |
|---|---|---|
| Documents index | `GET /documents` | — |
| Search | `GET /search?q=` | — |
| Section document show | `GET /documents/{level}/{dept}/{section}/{doc}` | `POST /documents`, `PATCH …/{doc}`, `DELETE …/{doc}` |
| Section document PDF | `GET /documents/{level}/{dept}/{section}/{doc}/pdf` | — |
| Rule-set document show | `GET /documents/{level}/{dept}/rules/{rule_set}/{doc}` | `PATCH …/{doc}`, `DELETE …/{doc}` |
| Rule-set document PDF | `GET /documents/{level}/{dept}/rules/{rule_set}/{doc}/pdf` | — |
| Document trash | — | `GET /documents/trash`, `POST …/trash/{id}/restore`, `DELETE …/trash/{id}` |
| Departments | `GET /departments`, `GET /departments/{level}/{dept}` | `POST /departments`, edit/patch/delete |
| Sections | `GET /departments/{level}/{dept}/sections/{section}` | `POST`, edit/patch/delete |
| Rule sets | `GET /departments/{level}/{dept}/rules/{rule_set}` | `POST /departments/{level}/{dept}/rules`, edit/patch/delete |
| Admin users | — | `GET/POST /admin/users`, edit/patch/delete |

Route names: `documents.show`, `documents.rules.show`, `departments.sections.show`, `departments.rules.show`, `admin.users.create`, etc.

### Slug-based routing (all models)

`Department`, `Section`, `RuleSet`, and `Document` all override `getRouteKeyName()` to return `'slug'`. Route helpers accept model instances. Never pass `->id` manually to a route helper for these models.

Slug helpers:
- `Document::uniqueSlugForSection($title, $sectionId, $exceptId?)` — section-scoped
- `Document::uniqueSlugForRuleSet($title, $ruleSetId, $exceptId?)` — rule-set-scoped
- `RuleSet::uniqueSlugForDepartment($name, $departmentId, $exceptId?)` — department-scoped

All check `withTrashed()` and append `-2`, `-3` on collision.

**Level-aware department binding** — see route map above. Controller methods must declare `string $level` as first parameter.

### Document upload flow

Upload is initiated from a section show page or rule set show page via a modal. The form POSTs to `POST /documents` via AJAX (`fetch`). The endpoint is **AJAX-only** and always returns JSON — `StoreDocumentRequest::failedValidation()` throws `HttpResponseException` with 422 JSON.

**Section-based upload:**
1. Slug: `Document::uniqueSlugForSection($title, $section->id)`
2. Vault dir: `document_vault/{dept.level}/{dept.slug}/{section.wing?}/{section.slug}`
3. File stored: `{vaultDir}/{slug}_{YmdHis}.pdf` on `public` disk
4. DB transaction: `Document::create()` + `DocumentStatusHistory::create()`
5. On failure: delete orphaned PDF; return 500 JSON
6. On success: JSON `{'redirect': sections_url}`

**Rule-set-based upload:**
1. Slug: `Document::uniqueSlugForRuleSet($title, $ruleSet->id)`
2. Vault dir: `document_vault/{dept.level}/{dept.slug}/rules/{ruleSet.slug}`
3. Same file/DB/error flow as above
4. On success: JSON `{'redirect': rule_set_url}`

`StoreDocumentRequest` — `section_id` and `rule_set_id` are `required_without:` each other. Exactly one must be provided.

**Converted Markdown** lands in the same vault directory, same base filename, `.md` extension. `markdown_path` stores the full relative path on `public` disk.

### PDF streaming

Section docs: `GET /documents/{level}/{dept}/{section}/{doc}/pdf` → `DocumentController@pdf`

Rule-set docs: `GET /documents/{level}/{dept}/rules/{rule_set}/{doc}/pdf` → `DocumentController@pdfRuleSetDoc`

Both stream from the `public` disk via `Storage::disk('public')->response(...)` with `Content-Disposition: inline`. Guests blocked (403) on non-verified documents. Always link via these routes — raw `Storage::url()` links bypass the auth gate.

### Document visibility

Documents carry a `visibility` column independent of the processing-status workflow:

| Value | Who can access |
|---|---|
| `public` (default) | All visitors, including unauthenticated guests |
| `authenticated` | Logged-in users only |

**Guest gate** — every public-facing query (`DocumentController@index/show/pdf`, `SectionController@show`, `FrontendController@dashboard`, `SearchController@index`) filters on `visibility = 'public'` for unauthenticated requests. The old `status = 'verified'` gate has been removed entirely.

**Upload modals** — both section and rule-set upload modals include a visibility radio selector (defaults to Public). The `StoreDocumentRequest` validates and passes the value through to `Document::create()`.

**`documents/show`** — green "Public" or amber "Authenticated Only" badge shown in the document header.

**Key distinction:** `status` tracks the conversion pipeline (`uploaded → processing → review → verified`); `visibility` controls read access. A document can be `public` while still `uploaded` (guests can download the original PDF immediately), or `authenticated` while `verified` (internal-only even after full processing).

### Document views

- **`documents/show`** — context-aware: receives either `$section` (section doc) or `$ruleSet` (rule-set doc). A `$isRuleSetDoc` flag switches breadcrumbs, page subtitle, vault path display, and all route helpers (PDF, edit, destroy) without duplicating the template. The "Section / Rule Set" metadata label also adapts. Visibility badge shown in header.
- **`documents/index`** — tabbed by department; renders both section and rule-set documents; row links branch on `$doc->section ? documents.show : documents.rules.show`.

### Search

`GET /search?q=` → `SearchController@index` → `search/index.blade.php`. Public route, no auth required.

**Query scope:** LIKE `%q%` on `documents.title`; also surfaces documents whose `section.name` or `rule_set.name` matches. Separate LIKE queries on `sections.name` and `rule_sets.name` + `description`. Guests see only `status = verified` documents.

**Result ordering:** documents with a direct title match float first (via `CASE WHEN` `orderByRaw`), then by `created_at DESC`. Capped at 50 documents, 20 sections, 20 rule sets.

**View structure:** large search bar (autofocused, × clear button) → summary strip with total count → indigo callout explaining cross-taxonomy surfacing (shown when sections or rule sets are matched) → Documents block (reuses `documents/index` row design) → Sections block (sky-accented) → Rule Sets block (violet-accented).

**Header integration:** existing search input in `header.blade.php` is wrapped in `<form method="GET" action="{{ route('search.index') }}">` with `name="q"` and `value="{{ request('q') }}"` so the field stays populated on the results page.

**Sidebar:** Search nav link (icon `ti-search`) sits between All Documents and Browse Vault; active on `routeIs('search.*')`.

### Document deletion, trash & permanent removal

Deletion is a two-stage process — soft-delete to trash, then optional permanent removal.

**Soft delete (Move to Trash):**
- Admin clicks the delete button on `documents/show`. A SweetAlert2 modal prompts for a deletion reason (required, 5–500 chars).
- `DeleteDocumentRequest` validates the reason (admin-only `authorize()`).
- Inside a `DB::transaction`: a `DocumentStatusHistory` row is inserted (`from_status` = current status, `to_status = 'deleted'`, `note` = reason, `actor_id` = current user), then `$document->delete()` (soft-delete sets `deleted_at`).
- Soft-deleted documents are invisible to guests and excluded from all public queries automatically via `SoftDeletes`.
- Route: `DELETE /documents/{level}/{dept}/{section}/{doc}` → `DocumentController@destroy` (section-based) or `DELETE /documents/{level}/{dept}/rules/{rule_set}/{doc}` → `@destroyRuleSetDoc` (rule-set-based).

**Trash view (`GET /documents/trash` → `documents.trash`):**
- Auth-only. Shows all soft-deleted documents ordered by `deleted_at DESC`.
- Each row displays: title, department, section/rule-set, deletion timestamp, actor, and the reason from the status history entry with `to_status = 'deleted'`.
- Sidebar: "Trash" link (`ti-trash` icon) visible to all authenticated users. "All Documents" active-state check excludes `documents.trash` so it doesn't highlight incorrectly.

**Restore (`POST /documents/trash/{id}/restore` → `documents.restore`):**
- Auth-only. Resolves the document via `Document::withTrashed()->findOrFail($id)` (numeric ID — slug binding doesn't work on soft-deleted records).
- Calls `$document->restore()`, then inserts a history row (`from_status = 'deleted'`, `to_status` = the document's existing status column value, note = 'Restored from trash.').
- Confirmation via SweetAlert2.

**Permanent delete (`DELETE /documents/trash/{id}` → `documents.force-destroy`):**
- Admin-only (controller checks `auth()->user()->isAdmin()`). Resolves via `Document::withTrashed()->findOrFail($id)`.
- Inside a transaction: deletes `original_pdf_path` and `markdown_path` files from the `public` disk, then calls `$document->forceDelete()`.
- `document_status_histories` rows cascade-delete automatically (FK `cascadeOnDelete`).
- Confirmation via SweetAlert2 with an explicit "This cannot be undone" warning.

**Form request:** `DeleteDocumentRequest` — `authorize()` returns `$this->user()?->isAdmin()`, `prepareForValidation()` runs `strip_tags` + `trim` on reason, validates `required|string|min:5|max:500`.

**SweetAlert2 (`sweetalert2@11`):** loaded globally in `head.blade.php` via jsDelivr. Used for all destructive-action confirmations. Dark mode background/color passed via `document.documentElement.classList.contains('dark')`. All JS blocks wrapped in `try/catch` to avoid silent failures.

### Rule set views

- **`rule_sets/create`** — name + description form with JS validation; slug is auto-generated server-side from name.
- **`rule_sets/edit`** — same, pre-populated; slug is read-only (set at creation, never changed).
- **`rule_sets/show`** — header with description, upload amendment modal (hidden for guests; pre-selects `rule_amendment` type; sends `rule_set_id`), amendment/document timeline list with status badges and auth-gated view/delete actions.

### Sidebar auth states

| State | Sections shown |
|---|---|
| Guest | Browse Vault + Departments (→ `departments.index`) |
| Authenticated | Browse Vault + Manage → Departments |
| Admin | Browse Vault + Manage → Departments + Users |

**Browse Vault is fully dynamic** — `sidebar.blade.php` queries all `Department` records ordered by level then name. Icon and color resolved from a `$deptMeta` slug → `[icon, color]` map; unknown slugs fall back to a cycling palette. Slug keys use underscores (matching DB slugs), e.g. `sugarcane_sugar`.

### Rate limiting

Named limiters defined in `AppServiceProvider::boot()`. Never use anonymous `throttle:60,1` inline.

| Limiter name | Limit | Key |
|---|---|---|
| `login` | 5/min per email+IP + 10/min per IP | Fortify brute-force |
| `two-factor` | 5/min per session+IP | Fortify 2FA |
| `mutations` | 60/min | user ID or IP — all auth POST/PATCH/DELETE groups |
| `uploads` | 10/min | user ID or IP — `POST /documents` only (on top of mutations) |

### File upload validation

Always use `mimetypes:` (not `mimes:`) — reads actual file bytes via PHP Fileinfo (magic-byte check); `mimes:` only checks extension. Accepted types defined as `StoreDocumentRequest::ACCEPTED_MIMETYPES` — reference this constant from tests or other Form Requests rather than duplicating the list.

Current accepted types: PDF, Word (doc/docx), Excel (xls/xlsx), PowerPoint (ppt/pptx), ODT/ODS/ODP, RTF, TXT, CSV, JPEG, PNG, WebP, GIF, TIFF, BMP, HEIC/HEIF, SVG. Max size: 50 MB.

## Architecture decisions already made (don't re-litigate without reason)

1. **Queue driver:** `database`, not Redis — no extra service to manage on a local single-box deployment.
2. **Text extraction:** `innobrain/markitdown` Composer package, `MARKITDOWN_USE_VENV_PACKAGE=true` — the package manages its own Python venv, so no hand-rolled subprocess/venv bridge is needed.
3. **OCR is conditional, not default** — only runs when markitdown returns near-empty/low-confidence text, to avoid wasting time OCR'ing native-text PDFs.
4. **Single disk (`public`), path-convention silos** — all document files (PDF + Markdown) live on the `public` disk (`storage/app/public/`), symlinked to `public/storage/` via `php artisan storage:link`. Isolation is enforced at the model/policy layer against vault path convention. No separate staging/uploads folder.
5. **Schema flexibility over premature normalization** — JSON `metadata` column absorbs new fields; promote to real columns only once a field has proven stable across iterations.
6. **No district/field-office granularity** in this phase — explicitly descoped.
7. **Slug-based URLs with level disambiguation** — `Department`, `Section`, `RuleSet`, `Document` all use `getRouteKeyName() = 'slug'`. IDs never appear in public URLs. A `{level}` alias (`dept` / `sectt`) precedes `{department}` in every URL. Always pass `[$dept->levelAlias(), $dept]` to route helpers — never just `$dept` alone.
8. **`POST /documents` is AJAX-only** — always returns JSON regardless of `Accept` header. `StoreDocumentRequest::failedValidation()` overrides the default redirect to throw `HttpResponseException` with 422 JSON. The JS `fetch` call always sends `Accept: application/json` + `X-CSRF-TOKEN` + `X-Requested-With: XMLHttpRequest`.
9. **PDF served via controller routes** — `DocumentController@pdf` and `@pdfRuleSetDoc` stream from the `public` disk with `Content-Disposition: inline`. Guests see 403 on non-verified documents. Always link via these routes — raw `Storage::url()` links bypass the auth gate.
10. **Dual document taxonomy** — documents belong to either a `Section` (GOs, notices, circulars) or a `RuleSet` (Acts, Rules, amendments), never both. `section_id` and `rule_set_id` are mutually exclusive nullable FKs. The `documents/show` view handles both contexts with a single template via the `$isRuleSetDoc` flag; separate URL routes exist for each context (`documents.show` vs `documents.rules.show`). When iterating documents across both types (index, dashboard feed), always check `$doc->section ? ... : ...` for routing and `$doc->section?->name ?? $doc->ruleSet?->name` for display.
11. **Rule-set slug is immutable after creation** — `UpdateRuleSetRequest` does not accept a `slug` field; the edit form shows slug as read-only. Changing the slug would break all existing vault file paths.
12. **Two-stage document deletion** — `DELETE /documents/…` soft-deletes only (sets `deleted_at`). Physical files are never removed at this stage. Permanent file+record removal requires a second explicit action from the trash view (`DELETE /documents/trash/{id}`). This preserves recoverability and the full audit trail until an admin consciously decides to purge. The deletion reason is always captured and stored in `document_status_histories` before the soft-delete occurs.
13. **SweetAlert2 for all confirmations** — all destructive-action confirmations use `Swal.fire()` (loaded globally via jsDelivr `sweetalert2@11`). Never use `window.confirm()` or inline `onsubmit` confirm checks. Respect dark mode by passing `background` and `color` based on `document.documentElement.classList.contains('dark')`.
14. **`visibility` is the sole guest access gate** — the old `status = 'verified'` filter for guests has been removed. Access control for unauthenticated users is now exclusively determined by `documents.visibility` (`public` | `authenticated`). The `status` column tracks only the conversion pipeline state and must never be used as an access gate. When writing any query that serves public-facing views, filter on `visibility = 'public'` for guests — never on `status`.

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
| SweetAlert2 | jsDelivr — `sweetalert2@11` |

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
- Section-based uploads store at: `document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/{slug}_{YmdHis}.pdf`
- Rule-set uploads store at: `document_vault/{level}/{dept_slug}/rules/{rule_set_slug}/{slug}_{YmdHis}.pdf`
- No staging/UUID folder. File I/O happens **before** the DB transaction; on transaction failure, delete the file in the `catch` block.

### Forms and mutations — no native GET/POST submissions
- **Never allow a form to submit natively via GET or POST.** All mutations that originate from a modal or AJAX flow must use `fetch()` with `method: 'POST'` and `Accept: application/json` + `X-CSRF-TOKEN` headers.
- Always add `method="POST"` and `action="..."` to every `<form>` as a hard fallback — so that if JS fails, the request at minimum goes to the right endpoint via POST (never GET), preventing credentials and sensitive params from appearing in the URL.
- The file input in upload forms must have `name="file"` so `new FormData(form)` captures it automatically. Do not use `formData.set('file', ...)` as a workaround for a missing `name` attribute.
- Always wrap the JS init block (the IIFE that attaches event listeners) in a `try/catch` so that a parse or runtime error during setup does not silently leave forms unprotected.
- Controllers that serve both AJAX and non-AJAX callers must use `$request->expectsJson()` to switch between `response()->json(...)` and `redirect(...)`.

### General
- Never log passwords, tokens, or full request bodies — always `$request->except(['password', 'password_confirmation'])`.
- Sensitive config (DB credentials, mail passwords) belongs in `.env` only — never hardcoded.
- `.env.example` must have blank values for all secrets.

## Conventions

- Bridge any new Python dependency through a Composer/Laravel package where one exists (as with `markitdown`) rather than raw `Process::run()` calls, unless no package exists.
- Long-running or potentially slow operations (extraction, OCR) must be dispatched as queued jobs — never run synchronously in a request/controller, to avoid browser timeouts.
- When generating migrations, prefer updating the original migration file directly for schema-in-flux tables rather than creating alter migrations — migration files are the single source of truth for table shape.

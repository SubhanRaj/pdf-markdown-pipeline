# PDF to Markdown Pipeline (`pdf-markdown-pipeline`)

A robust, local-first document ingestion and conversion portal that transforms dense, unstructured PDFs into clean, structured, AI-ready Markdown.

## 📖 Project Background & Scope

This pipeline was architected to handle the document digitization needs of two State Government bodies:

- **Department of Excise**, Government of Uttar Pradesh
- **Department of Sugarcane & Sugar Industries**, Government of Uttar Pradesh

Government workflows require parsing thousands of pages of dense bureaucratic material — Government Orders (GOs), service codes, departmental policies, Acts, Rules, and amendments — in both English and administrative Hindi (Rajbhasha). Due to strict data privacy and security mandates, this system runs **100% on-premise**, ensuring sensitive administrative data never touches third-party cloud APIs.

While built for government requirements, the architecture is fully open-source and adaptable for any organization that needs an auditable, human-in-the-loop document conversion pipeline.

## ✨ Core Features

- **Dual-Engine Processing**
  - Native-text PDFs are processed via the `markitdown` Python package (invoked through Laravel queue jobs).
  - Scanned legacy documents fall back to OCR (Tesseract, with the `hin` language pack for bilingual Devanagari/English text).
- **Human-in-the-Loop Validation UI** — A split-pane interface where clerks and administrators visually verify the original PDF against the compiled, styled Markdown (rendered via Parsedown) before committing the data to the vault.
- **Strict Siloed Architecture** — A hierarchical directory structure (Level → Body → Section/RuleSet) maps directly to database records, preventing context leakage between administrative units.
- **Dual Document Taxonomy** — Documents belong to either a **Section** (for GOs, notices, policy circulars) or a **Rule Set** (for Acts, Rules, and their amendments), each with dedicated vault paths and URL structures.
- **Metadata Injection** — Processed Markdown files carry YAML frontmatter (department, section, GO reference, dates, etc.), enabling accurate context retrieval for downstream LLM/RAG pipelines.
- **Maker-Checker Approval Workflow** — Bulk-onboarding operators can have all their uploads held in `pending_approval` until a designated approver reviews them. Approval scope follows the existing organisational hierarchy (section / department / global). Approvers can approve, reject (with mandatory reason), or reclassify (move document to the correct section/division/rule set without re-uploading). Rejected documents can be resubmitted by the uploader. The entire flow is audit-logged.
- **Full Audit Trail** — Every document state transition (`Uploaded → Processing → Review → Verified`, including `pending_approval` and `rejected`) is logged with the acting user and timestamp.
- **Full Rajbhasha / Unicode Support** — All document titles, section names, rule set names, and division names accept Devanagari text natively — including combining marks (matras, halant). Mixed Hindi-English titles like `FL Bottling Rules 2011 (शुद्धिपत्र)` are stored, displayed, and slugified correctly. Validation uses Unicode category classes (`\p{L}\p{M}\p{N}\p{P}\p{Z}`) in both PHP (PCRE) and browser JavaScript. URL slugs preserve Devanagari characters intact (e.g. `…/fl-bottling-rules-2011-16th-amendment-शुद्धिपत्र`) instead of transliterating them.

## 🛠️ Technology Stack

| Layer | Technology |
|---|---|
| Core Framework | Laravel 13, PHP 8.4 |
| Database | MariaDB 12 |
| Web Server | Apache (mod_php or php-fpm) — no Nginx |
| Frontend / UI | Blade Templates, Tailwind CSS v4 (Play CDN), Parsedown — no Node, no npm, no build step |
| Text Extraction | Python `markitdown`, via the [`innobrain/markitdown`](https://github.com/innobraingmbh/markitdown) Laravel package |
| OCR Engine | Tesseract OCR (`hin` + `eng` language packs) |
| Queue | Laravel database queue driver (local single-box deployment, no Redis dependency) |

## ⚙️ PHP Configuration Requirements

PHP ships with restrictive defaults that block uploads larger than 2 MB. Four directives need raising before the pipeline can accept real documents. There are three places to set them — use whichever matches your deployment:

### Option A — `public/.htaccess` (already present in this repo)

The repo ships with these directives inside `<IfModule mod_php.c>` in `public/.htaccess`. This works for **Apache + mod_php** and takes effect immediately per-request with no server restart. It is also the only option on shared/managed hosting where you don't have php.ini access.

**Requirement:** the Apache vhost or `<Directory>` block must have `AllowOverride All` (or at minimum `AllowOverride Options FileInfo`). If `AllowOverride None` is set, `.htaccess` is silently ignored.

### Option B — `public/.user.ini` (Apache + php-fpm or any SAPI)

Create `public/.user.ini`:

```ini
upload_max_filesize = 64M
post_max_size       = 64M
max_execution_time  = 120
max_input_time      = 120
```

PHP reads this file directly for both mod_php and php-fpm. No Apache directive required. Changes take effect within 5 minutes (`user_ini.cache_ttl = 300`) without a restart.

### Option C — `php.ini` on the server (recommended for a dedicated on-premise box)

Edit the system php.ini — path varies by distro:

| Environment | Path |
|---|---|
| macOS / Homebrew | `/usr/local/etc/php/8.x/php.ini` |
| Debian / Ubuntu | `/etc/php/8.x/apache2/php.ini` |
| RHEL / CentOS | `/etc/php.ini` |

```ini
upload_max_filesize = 64M   ; must be ≥ the 50 MB Laravel validation limit
post_max_size       = 64M   ; must be ≥ upload_max_filesize
max_execution_time  = 120   ; large uploads on slow hardware can exceed the 30s default
max_input_time      = 120   ; time allowed to receive the upload data stream
```

Restart after editing:
- Apache + mod_php: `sudo systemctl restart apache2` or `brew services restart httpd`
- Apache + php-fpm: `sudo systemctl restart php8.x-fpm`

**Note:** `post_max_size` must always be ≥ `upload_max_filesize` — the POST body wraps the file plus form fields. Apache has no `client_max_body_size` equivalent (that's Nginx); PHP is the only gatekeeper here.

## 📂 Document Vault Structure

Scope for this phase is **Secretariat and Head Quarter level only** — policies, GOs, and rules are uniform across field offices (DEO/DEC/JEC), so no district/jurisdiction-level breakdown is needed. Field office tiers can be added later if a use case requires it.

```text
storage/app/document_vault/
├── secretariat_level/
│   └── excise/
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
    │           ├── {rule-set-slug}/       ← Acts, Rules, and their amendments
    │           └── ...
    │
    └── sugarcane_sugar/
        └── (structure to be added once scoped)
```

**Section-based document path:**
```
document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/{slug}_{YmdHis}.pdf
```

**Rule-set-based document path:**
```
document_vault/{level}/{dept_slug}/rules/{rule_set_slug}/{slug}_{YmdHis}.pdf
```

## 🗄️ Database Schema

Core tables are migrated and in use. Structural columns are minimal; evolving fields (GO number, subject, dates, etc.) live in a `metadata` JSON column until they stabilise.

### `departments`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | Display name |
| `slug` | string | URL-safe identifier |
| `level` | string | `secretariat_level` \| `department_level` |
| timestamps + softDeletes | | |

Unique constraint: `(slug, level)`.

### `sections`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `wing` | string nullable | e.g. `joint_secretary_wing`, `headquarter` |
| `name` | string | |
| `slug` | string | |
| `requires_approval` | boolean | default false — any upload to this section is held for approval |
| timestamps + softDeletes | | |

Unique constraint: `(department_id, wing, slug)`.

### `folders`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `section_id` | FK → sections | `restrictOnDelete` |
| `division_id` | FK → divisions nullable | `nullOnDelete` — non-null for division folders |
| `name` | string | Display name (e.g. "Court Case – Liquor License Appeal 2024") |
| `slug` | string | Auto-generated from name; immutable after creation |
| `description` | text nullable | Optional summary (max 500 chars) |
| `visibility` | string | `public` (default) \| `authenticated` — gates the folder page |
| `requires_approval` | boolean | default false — uploads to this folder held for approval |
| `metadata` | json nullable | Case number, year, tags, etc. |
| timestamps + softDeletes | | |

Unique constraint: `(section_id, division_id, slug)`.

### `rule_sets`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `name` | string | Full name of the Act/Rule (e.g. *U.P. Excise Act 1910*) |
| `slug` | string | Auto-generated from name |
| `description` | text nullable | Optional summary |
| `requires_approval` | boolean | default false — any upload to this rule set is held for approval |
| `metadata` | json nullable | Category, origin year, etc. |
| timestamps + softDeletes | | |

Unique constraint: `(department_id, slug)`.

### `documents`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `section_id` | FK → sections nullable | `restrictOnDelete` — null for rule-set docs; set for all others |
| `division_id` | FK → divisions nullable | `nullOnDelete` — non-null for division docs and division-folder docs |
| `rule_set_id` | FK → rule_sets nullable | `nullOnDelete` — non-null for rule-set docs only |
| `folder_id` | FK → folders nullable | `nullOnDelete` — non-null for folder docs (section or division folder) |
| `user_id` | FK → users nullable | `nullOnDelete` — uploader |
| `title` | string | Human-readable title / reference |
| `slug` | string | URL-safe; auto-generated from title |
| `document_type` | string | `go` \| `policy` \| `notice` \| `court_order` \| `service_code` \| `rule` \| `rule_amendment` \| `other` |
| `original_filename` | string | |
| `original_pdf_path` | string | Full relative path on `public` disk |
| `markdown_path` | string nullable | Set after extraction job completes |
| `vault_path` | string nullable | Vault directory; set at upload |
| `status` | string | `pending_approval → uploaded → processing → ocr_pending → review → verified \| failed \| rejected` — see approval workflow below |
| `visibility` | string | `public` (default) \| `authenticated` — guest access gate, independent of status |
| `parent_id` | FK → documents nullable | `nullOnDelete` — links amendments to their parent document |
| `metadata` | json nullable | GO number, subject, dates, etc. |
| timestamps + softDeletes | | |

Five-way context exclusivity — exactly one context group is active:

| Context | `section_id` | `division_id` | `rule_set_id` | `folder_id` |
|---|---|---|---|---|
| Direct section doc | non-null | null | null | null |
| Division doc | non-null | non-null | null | null |
| Rule-set doc | null | null | non-null | null |
| Section-folder doc | non-null | null | null | non-null |
| Division-folder doc | non-null | non-null | null | non-null |

### `document_status_histories`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `document_id` | FK → documents | `cascadeOnDelete` |
| `actor_id` | FK → users nullable | `nullOnDelete` |
| `from_status` | string nullable | |
| `to_status` | string | |
| `note` | text nullable | |
| `metadata` | json nullable | Extra context per transition. On `to_status = 'force_deleted'`: `{"letter_path": "archive_letters/...pdf", "reason": "..."}` |
| `created_at` | timestamp | Append-only — no `updated_at` |

### `users`
Standard Laravel/Fortify users table extended with `username`, `mobile` (10 digits, nullable), `landline` (free-form STD+number, nullable), `post`, `role`, `uploads_require_approval` (boolean, default false — bulk-mode flag; all uploads from this user go to `pending_approval`), `privileges` (JSON — validated against `User::PRIVILEGES` whitelist), `department_id`, `section_id`, `division_id`. Public registration disabled — admin-created only.

**Privilege strings:** `documents.upload`, `documents.edit`, `documents.delete`, `documents.restore`, `documents.force-delete`, `documents.verify`, `documents.approve`, `organization.head`, `department.head`, `section.head`. Admins bypass all privilege checks unconditionally.

## 🗺️ Route Map

All models use slug-based routing (`getRouteKeyName() = 'slug'`). IDs never appear in URLs.

`{level}` = `dept` (department_level) | `sectt` (secretariat_level) — disambiguates departments sharing a slug across levels.

### Documents

| Route | Method | Name | Auth |
|---|---|---|---|
| `/documents` | GET | `documents.index` | Public |
| `/documents` | POST | `documents.store` | Auth |
| `/documents/{level}/{dept}/{section}/{doc}` | GET | `documents.show` | Public* |
| `/documents/{level}/{dept}/{section}/{doc}` | PATCH | `documents.update` | Auth |
| `/documents/{level}/{dept}/{section}/{doc}` | DELETE | `documents.destroy` | Auth |
| `/documents/{level}/{dept}/{section}/{doc}/pdf` | GET | `documents.pdf` | Public* |
| `/documents/{level}/{dept}/{section}/{doc}/review` | GET | `documents.edit` | Auth |
| `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}` | GET | `documents.divisions.show` | Public* |
| `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}` | PATCH | `documents.divisions.update` | Auth |
| `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}` | DELETE | `documents.divisions.destroy` | Auth |
| `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}/pdf` | GET | `documents.divisions.pdf` | Public* |
| `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}/review` | GET | `documents.divisions.edit` | Auth |
| `/documents/{level}/{dept}/rules/{rule_set}/{doc}` | GET | `documents.rules.show` | Public* |
| `/documents/{level}/{dept}/rules/{rule_set}/{doc}` | PATCH | `documents.rules.update` | Auth |
| `/documents/{level}/{dept}/rules/{rule_set}/{doc}` | DELETE | `documents.rules.destroy` | Auth |
| `/documents/{level}/{dept}/rules/{rule_set}/{doc}/pdf` | GET | `documents.rules.pdf` | Public* |
| `/documents/{level}/{dept}/rules/{rule_set}/{doc}/review` | GET | `documents.rules.edit` | Auth |
| `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}` | GET | `documents.folders.show` | Public* |
| `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}` | PATCH | `documents.folders.update` | Auth |
| `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}` | DELETE | `documents.folders.destroy` | Auth |
| `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}/pdf` | GET | `documents.folders.pdf` | Public* |
| `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}/review` | GET | `documents.folders.edit` | Auth |
| `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}` | GET | `documents.divisions.folders.show` | Public* |
| `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}` | PATCH | `documents.divisions.folders.update` | Auth |
| `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}` | DELETE | `documents.divisions.folders.destroy` | Auth |
| `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}/pdf` | GET | `documents.divisions.folders.pdf` | Public* |
| `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}/review` | GET | `documents.divisions.folders.edit` | Auth |
| `/documents/trash` | GET | `documents.trash` | Auth (all roles — UI calls this "Archive") |
| `/documents/trash/{id}/pdf` | GET | `documents.trashed.pdf` | Auth |
| `/documents/trash/{id}/restore` | POST | `documents.restore` | `documents.restore` privilege or admin |
| `/documents/trash/{id}` | DELETE | `documents.force-destroy` | `documents.force-delete` privilege or admin |
| `/documents/trash/bulk-restore` | POST | `documents.trash.bulk-restore` | `documents.restore` privilege or admin |
| `/documents/trash/bulk-force-destroy` | DELETE | `documents.trash.bulk-force-destroy` | `documents.force-delete` privilege or admin |
| `/documents/bulk-destroy` | POST | `documents.bulk-destroy` | Auth (scoped to user's upload/delete scope) |

*Public routes 403 on `visibility = authenticated` documents for guests.

### Departments, Sections, Divisions, Rule Sets, Folders

| Route | Method | Name | Auth |
|---|---|---|---|
| `/departments` | GET | `departments.index` | Public |
| `/departments` | POST | `departments.store` | Auth |
| `/departments/{level}/{dept}` | GET | `departments.show` | Public |
| `/departments/{level}/{dept}` | PATCH | `departments.update` | Auth |
| `/departments/{level}/{dept}` | DELETE | `departments.destroy` | Auth |
| `/departments/{level}/{dept}/sections` | GET | `departments.sections.index` | Public |
| `/departments/{level}/{dept}/sections` | POST | `departments.sections.store` | Auth |
| `/departments/{level}/{dept}/sections/{section}` | GET | `departments.sections.show` | Public |
| `/departments/{level}/{dept}/sections/{section}` | PATCH | `departments.sections.update` | Auth |
| `/departments/{level}/{dept}/sections/{section}` | DELETE | `departments.sections.destroy` | Auth |
| `/departments/{level}/{dept}/sections/{section}/divisions` | POST | `departments.sections.divisions.store` | Admin |
| `/departments/{level}/{dept}/sections/{section}/divisions/{division}` | GET | `departments.sections.divisions.show` | Public |
| `/departments/{level}/{dept}/sections/{section}/divisions/{division}` | PATCH | `departments.sections.divisions.update` | Admin |
| `/departments/{level}/{dept}/sections/{section}/divisions/{division}` | DELETE | `departments.sections.divisions.destroy` | Admin |
| `/departments/{level}/{dept}/sections/{section}/folders` | POST | `departments.sections.folders.store` | Auth |
| `/departments/{level}/{dept}/sections/{section}/folders/{folder}` | GET | `departments.sections.folders.show` | Public* |
| `/departments/{level}/{dept}/sections/{section}/folders/{folder}` | PATCH | `departments.sections.folders.update` | Auth |
| `/departments/{level}/{dept}/sections/{section}/folders/{folder}` | DELETE | `departments.sections.folders.destroy` | Auth |
| `/departments/{level}/{dept}/sections/{section}/divisions/{division}/folders` | POST | `departments.sections.divisions.folders.store` | Auth |
| `/departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder}` | GET | `departments.sections.divisions.folders.show` | Public* |
| `/departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder}` | PATCH | `departments.sections.divisions.folders.update` | Auth |
| `/departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder}` | DELETE | `departments.sections.divisions.folders.destroy` | Auth |
| `/departments/{level}/{dept}/rules` | POST | `departments.rules.store` | Auth |
| `/departments/{level}/{dept}/rules/{rule_set}` | GET | `departments.rules.show` | Public |
| `/departments/{level}/{dept}/rules/{rule_set}` | PATCH | `departments.rules.update` | Auth |
| `/departments/{level}/{dept}/rules/{rule_set}` | DELETE | `departments.rules.destroy` | Auth |

*Folder show routes 403 if `folder.visibility = 'authenticated'` and user is guest.

### Users & Profile

| Route | Method | Name | Auth |
|---|---|---|---|
| `/admin/users` | GET | `admin.users.index` | Admin |
| `/admin/users` | POST | `admin.users.store` | Admin |
| `/admin/users/{user}` | GET | `admin.users.show` | Admin |
| `/admin/users/{user}` | PATCH | `admin.users.update` | Admin |
| `/admin/users/{user}` | DELETE | `admin.users.destroy` | Admin |
| `/admin/users/{user}/edit` | GET | `admin.users.edit` | Admin |
| `/profile/edit` | GET | `profile.edit` | Auth |
| `/profile` | PATCH | `profile.update` | Auth |

### Approval Queue

| Route | Method | Name | Auth |
|---|---|---|---|
| `/approvals` | GET | `approvals.index` | Auth |
| `/approvals/{id}/pdf` | GET | `approvals.pdf` | Auth |
| `/approvals/{id}/approve` | POST | `approvals.approve` | `documents.approve` privilege or admin |
| `/approvals/{id}/reject` | POST | `approvals.reject` | `documents.approve` privilege or admin |
| `/approvals/{id}/reclassify` | POST | `approvals.reclassify` | `documents.approve` privilege or admin |
| `/approvals/{id}/resubmit` | POST | `approvals.resubmit` | Auth (own document only) |

Approval routes use numeric `{id}` — reclassification changes context mid-flow so slug-based binding would break.

### Other

| Route | Name | Notes |
|---|---|---|
| `GET /` | `home` | Dashboard |
| `GET /search?q=` | `search.index` | Public full-text search |
| `GET /login`, `POST /login` | `login`, `login.store` | Fortify auth |
| `POST /logout` | `logout` | Fortify auth |

## 🚧 Status

Active development. The core upload, browse, and rule-set flows are working end-to-end.

**Complete:**
- Database schema: `departments`, `sections`, `rule_sets`, `documents` (with `rule_set_id`, `title`, `document_type`), `document_status_histories`, `users`
- Full CRUD for Documents, Departments, Sections, Rule Sets, and admin User Management — all with DB transactions, try/catch, and `$request->validated()` throughout
- Dual document taxonomy: section-based (GOs, notices, circulars) and rule-set-based (Acts, Rules, amendments) with separate vault paths and URL structures
- File upload: accepts PDF, Word, Excel, PowerPoint, ODT, JPEG/PNG/WebP/GIF/TIFF/BMP/HEIC, RTF, TXT, CSV — validated against actual magic bytes (no extension spoofing); SVG explicitly excluded; stored directly in the vault directory as `{slug}_{YmdHis}.pdf`
- Rate limiting: login brute-force (5/min per email+IP), general mutation cap (60/min/user), upload cap (20/min/user) — all named limiters
- Sidebar fully dynamic: driven by DB records; no hardcoded department links
- Level-aware department routing: `{level}` URL segment disambiguates departments sharing slugs across levels
- Browse Vault sidebar and dashboard department cards are fully dynamic

- Basic search: `GET /search?q=` across document titles, section names, and rule set names/descriptions — results split into three typed blocks (Documents / Sections / Rule Sets); guests see verified docs only; header search bar wired to this route; Search link added to sidebar
- Two-stage document deletion: soft-delete with mandatory reason (stored in status history audit log) → trash view (`GET /documents/trash`) with restore and permanent-delete actions; permanent delete removes files from disk before hard-deleting the DB record; SweetAlert2 used for all confirmations
- Document visibility control: `public` (default, visible to all guests) vs `authenticated` (logged-in users only); decoupled from the processing-status pipeline so documents can be public immediately on upload without waiting for the review/verified workflow; visibility selector in upload modals; badge on document show page
- Rule set upload flow: two independent modals — "Upload Rule" (disabled once a rule doc exists) and "Upload Amendment" (disabled until a rule doc exists); amendment modal auto-selects the parent if only one root rule doc is present; rule set cascade delete soft-deletes all documents with audit entries before removing the rule set; Edit button locked on rule docs that already have amendments
- Internal Divisions module: sub-entities of sections (पटल / desk / cell) with their own document stream and amendment hierarchy; division docs carry both `section_id` and `division_id`; cross-division amendments permitted; vault path under `sections/{slug}/divisions/{slug}/`
- Amendment metadata: `amendment_number`, `effective_year`, `effective_month`, `effective_day` stored in the existing `metadata` JSON column (no migration); upload modals include optional fields; sort/filter by amendment number or effective year available on rule sets, divisions, and section document lists; effective date displayed on document rows and the show-page sidebar
- Full Unicode / Rajbhasha support: all document title, section name, rule set name, and division name fields accept Devanagari and mixed-script text; validation uses `[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]` in both PHP Form Requests and JS frontend patterns; user model fields remain Latin-only by design

**Completed (M23 — 2026-06-24):**
- **Archive module:** "Trash" renamed to "Archive" in all UI (route names/backend unchanged); archive page accessible to all authenticated users; document counts split into Active + Archived everywhere; restore gated by `documents.restore` privilege; permanent delete requires `documents.force-delete` privilege + reason + mandatory letter PDF upload (stored on the private `local` disk at `storage/app/private/archive_letters/`, never publicly accessible; path recorded in `document_status_histories.metadata`); full audit trail with `actor_id` on all history rows
- **Scope-based upload permissions:** `division_id` FK added to `users`; `User::PRIVILEGES` constant as whitelist (prevents escalation); `User::canUploadTo()` / `canDeleteFrom()` helpers enforce scope in form request `authorize()` methods; division/section/department creation gated by `section.head`/`department.head`/`organization.head` privileges; admin user create/edit forms have cascading dept→section→division dropdowns and new privilege checkboxes; UI conditionally hides upload and creation buttons based on scope; legacy operators with no org assignment retain global access during initial data-entry phase

**Completed (M24 — 2026-06-24 · NIC Security Hardening):**
- **SVG upload blocked** — `image/svg+xml` removed from `StoreDocumentRequest::ACCEPTED_MIMETYPES`; SVG is XML with executable script content and has no valid government document use case
- **Security response headers** — new `SecurityHeaders` middleware (globally registered) sets `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy`, and `Strict-Transport-Security` (HTTPS only)
- **Direct storage access blocked** — `.htaccess` returns 403 for any direct request to `/storage/document_vault/` or `/storage/archive_letters/`; all document access must go through controller routes
- **Archive letters moved to private disk** — letter PDFs now stored via `Storage::disk('local')` (`storage/app/private/archive_letters/`), removing the public-URL exposure that existed when they were on the `public` disk
- **Bulk restore IDOR fixed** — `bulkRestore()` now checks `canDeleteFrom()` per document in the loop; division-scoped operators cannot restore documents from foreign departments
- **Bulk force-delete audit trail** — `BulkForceDestroyDocumentsRequest` now validates a mandatory `reason`; controller writes `DocumentStatusHistory` rows before each `forceDelete()`; UI collects reason via two-step Swal2 flow
- **Parsedown XSS closed** — `javascript:`/`data:`/`vbscript:` URIs stripped from Parsedown HTML output via `preg_replace` before `{!! !!}` rendering in `documents/show`
- **`original_filename` sanitized** — client-supplied filename scrubbed with `preg_replace` before storage to prevent header injection via `Content-Disposition`
- **Upload rate limit capped** — `throttle:uploads` reduced from 60/min to 20/min (max 1 GB/min disk I/O vs. previous 3 GB/min)
- **Department binding strict** — unknown `{level}` aliases now abort 404 instead of silently falling through to `department_level`

**Completed (M25 — 2026-06-24 · Activity Log):**
- **Append-only audit table** — `activity_logs` records user ID, IP address (IPv6-safe), user agent, action (route name), URL, and HTTP status for every authenticated mutation
- **Login tracking** — every successful Fortify login is captured via `Illuminate\Auth\Events\Login` listener with IP, user agent, and auth guard
- **`LogMutation` middleware** — registered globally; fires after the response (captures HTTP status); no-ops on GET/HEAD/OPTIONS and unauthenticated requests
- **Non-fatal logging** — `ActivityLog::record()` catches all exceptions internally; a log write failure never affects the user's actual operation
- **Admin audit view** — `GET /admin/activity-logs` (admin-only); filterable by user, action, and IP; color-coded action badges; 50 per page; linked from sidebar
- **Preserved on user deletion** — `user_id` is `nullOnDelete`; log rows survive account deletion and show "Deleted user" in the view

**Completed (M27 — 2026-06-24 · Archived Document File Isolation):**
- **Physical file move on archive** — soft-deleting a document moves its PDF and Markdown off the `public` disk into `storage/app/private/archived_documents/` so it is unreachable by any URL; files move back on restore
- **Direct URL access restored for active documents** — the blanket `.htaccess` 403 on `/storage/document_vault/` is removed; public documents are shareable by direct link and indexable by search engines
- **`trashedPdf` serves from private disk** — the archive PDF viewer streams from the local disk, not the public disk
- **`ManagesDocumentFiles` trait** — shared by `DocumentController` and `RuleSetController`; handles archive, restore, and permanent-delete file operations consistently

**Completed (M28 — 2026-06-26 · Maker-Checker Upload Approval Workflow):**
- **Pending approval status** — two independent triggers: `users.uploads_require_approval = true` (per-user bulk-mode) or `context.requires_approval = true` (per-section/division/rule_set policy)
- **Approval queue** at `GET /approvals` — three tabs (Pending / Rejected / My Submissions); approve, reject, reclassify, resubmit actions; slide-over PDF preview; bulk approve/reject
- **`->publishable()` scope** hides `pending_approval` and `rejected` docs from all regular browse views; visible only in the approvals queue

**Completed (M26 — 2026-06-24 · Auth/Fortify/Session Audit):**
- **Dual-key login rate limiter restored** — `FortifyServiceProvider` was silently overwriting the `AppServiceProvider` dual-key limiter; per-IP cap is now correctly enforced
- **`Password::defaults()` configured** — all Fortify actions now inherit the strong password policy (min 8, mixed case, numbers, symbols) instead of a bare min-8 fallback
- **Remember-me removed** — "Keep me signed in" checkbox eliminated; a 5-year token on a shared government workstation was a session hijack waiting to happen
- **Session hardened** — `SESSION_ENCRYPT=true`, `SESSION_EXPIRE_ON_CLOSE=true`, `SESSION_SAME_SITE=strict` applied; `SESSION_SECURE_COOKIE` documented (must be `true` on HTTPS SDC deployment)
- **`.env.example` annotated** — production guidance comments added to `APP_ENV`, `APP_DEBUG`, and all session security keys

## 👥 Demo Accounts

The `UserSeeder` ships with pre-built accounts covering every role and a representative set of privilege combinations. Run with:

```bash
php artisan db:seed --class=UserSeeder
```

The seeder is idempotent — uses `firstOrCreate` on email, so re-running it never duplicates or overwrites existing records.

| Role | Email | Password | Privileges |
|---|---|---|---|
| Admin | `shubhanraj2002@gmail.com` | `Admin@1234` | Full access (`*`) — primary dev account |
| Admin (demo) | `admin.demo@excise.up.gov.in` | `Admin@1234` | Full access (`*`) — Deputy Commissioner persona |
| Operator (full) | `operator.full@excise.up.gov.in` | `Operator@1234` | upload + edit + delete + restore + verify |
| Operator (upload-only) | `operator.upload@excise.up.gov.in` | `Operator@1234` | `documents.upload` only — junior clerk |
| Operator (review/verify) | `operator.review@excise.up.gov.in` | `Operator@1234` | edit + verify — QA reviewer |
| Viewer | `viewer@excise.up.gov.in` | `Viewer@1234` | None — read-only authenticated access |

**Role summary:**
- **Admin** — complete system access including user management (`/admin/users`). `isAdmin()` unconditionally returns `true` for all privilege checks.
- **Operator** — authenticated mutations only; specific capabilities controlled by `privileges` JSON array. No user management access.
- **Viewer** — can log in and view `authenticated`-visibility documents that guests cannot see, but cannot upload or mutate anything.

**Completed (M29 — 2026-07-04 · Folders / Patravali):**
- Physical file/dossier grouping (Patravali concept) for correspondence related to a specific matter — distinct from Sections/Divisions which are org units
- Folders belong to a Section or Division; have their own URL, show page (upload hub + doc list), and visibility gate
- Five-way document taxonomy: section doc, division doc, rule-set doc, section-folder doc, division-folder doc
- Amendment chains within folders via existing `parent_id`; `requires_approval` toggle; same archive cascade as rule sets
- Search extended with a Folders block
- `canUploadTo()`/`shouldRequireApproval()` on `User` extended to resolve a Folder to its owning division or section

**Next up:** Queue job for extraction via `markitdown`, OCR fallback for scanned PDFs, split-pane review UI (PDF embed + editable Markdown), vault path file resolution on verification.

## 🚀 Future Roadmap

Advanced enterprise features and security enhancements planned for SDC/NIC compliance and high-value bureaucratic workflows are documented in [ROADMAP.md](ROADMAP.md).

**Highlights:**
- Mandatory TOTP 2FA + concurrent session blocking (`Auth::logoutOtherDevices`)
- ClamAV anti-virus pipeline integration (queued scan before text extraction)
- Dynamic PDF watermarking for `authenticated`-visibility downloads (`setasign/fpdi`)
- Maker-Checker (E-File approval) workflow with `pending_approval` status stage
- Full-text Devanagari/English search via Meilisearch + Laravel Scout
- Non-destructive document versioning with SHA-256 hash audit trail

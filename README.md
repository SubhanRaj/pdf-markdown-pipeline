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
- **Full Audit Trail** — Every document state transition (`Uploaded → Processing → Review → Verified`) is logged with the acting user and timestamp.
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
| timestamps + softDeletes | | |

Unique constraint: `(department_id, wing, slug)`.

### `rule_sets`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `name` | string | Full name of the Act/Rule (e.g. *U.P. Excise Act 1910*) |
| `slug` | string | Auto-generated from name |
| `description` | text nullable | Optional summary |
| `metadata` | json nullable | Category, origin year, etc. |
| timestamps + softDeletes | | |

Unique constraint: `(department_id, slug)`.

### `documents`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `section_id` | FK → sections nullable | `restrictOnDelete` — null for rule-set docs |
| `rule_set_id` | FK → rule_sets nullable | `nullOnDelete` — null for section-based docs |
| `user_id` | FK → users nullable | `nullOnDelete` — uploader |
| `title` | string | Human-readable title / reference |
| `slug` | string | URL-safe; auto-generated from title; unique per section or rule set |
| `document_type` | string | `go` \| `policy` \| `notice` \| `court_order` \| `service_code` \| `rule` \| `rule_amendment` \| `other` |
| `original_filename` | string | |
| `original_pdf_path` | string | Full relative path on `public` disk |
| `markdown_path` | string nullable | Set after extraction job completes |
| `vault_path` | string nullable | Vault directory; set at upload |
| `status` | string | `uploaded → processing → ocr_pending → review → verified \| failed` — pipeline state only |
| `visibility` | string | `public` (default) \| `authenticated` — guest access gate, independent of status |
| `parent_id` | FK → documents nullable | `nullOnDelete` — links amendments to their parent rule document |
| `metadata` | json nullable | GO number, subject, dates, etc. |
| timestamps + softDeletes | | |

Unique constraint: `(section_id, slug)` for section documents. Slug generation for rule-set documents uses `uniqueSlugForRuleSet()`. Exactly one of `section_id` or `rule_set_id` is non-null per row.

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
Standard Laravel/Fortify users table extended with `username`, `mobile` (10 digits, nullable), `landline` (free-form STD+number, nullable), `post`, `role`, `privileges` (JSON — validated against `User::PRIVILEGES` whitelist), `department_id`, `section_id`, `division_id`. Public registration disabled — admin-created only.

**Privilege strings:** `documents.upload`, `documents.edit`, `documents.delete`, `documents.restore`, `documents.force-delete`, `documents.verify`, `organization.head`, `department.head`, `section.head`. Admins bypass all privilege checks unconditionally.

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
| `/documents/trash` | GET | `documents.trash` | Auth (all roles — UI calls this "Archive") |
| `/documents/trash/{id}/pdf` | GET | `documents.trashed.pdf` | Auth |
| `/documents/trash/{id}/restore` | POST | `documents.restore` | `documents.restore` privilege or admin |
| `/documents/trash/{id}` | DELETE | `documents.force-destroy` | `documents.force-delete` privilege or admin |
| `/documents/trash/bulk-restore` | POST | `documents.trash.bulk-restore` | `documents.restore` privilege or admin |
| `/documents/trash/bulk-force-destroy` | DELETE | `documents.trash.bulk-force-destroy` | `documents.force-delete` privilege or admin |
| `/documents/bulk-destroy` | POST | `documents.bulk-destroy` | Auth (scoped to user's upload/delete scope) |

*Public routes 403 on `visibility = authenticated` documents for guests.

### Departments, Sections, Divisions, Rule Sets

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
| `/departments/{level}/{dept}/rules` | POST | `departments.rules.store` | Auth |
| `/departments/{level}/{dept}/rules/{rule_set}` | GET | `departments.rules.show` | Public |
| `/departments/{level}/{dept}/rules/{rule_set}` | PATCH | `departments.rules.update` | Auth |
| `/departments/{level}/{dept}/rules/{rule_set}` | DELETE | `departments.rules.destroy` | Auth |

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
- File upload: accepts PDF, Word, Excel, PowerPoint, ODT, all image formats, RTF, TXT, CSV — validated against actual magic bytes; stored directly in the vault directory as `{slug}_{YmdHis}.pdf`
- Rate limiting: login brute-force (5/min per email+IP), general mutation cap (60/min/user), upload cap (60/min/user) — all named limiters; bulk multi-file uploads are bounded by the 50 MB file size cap rather than a tight request count
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
- **Archive module:** "Trash" renamed to "Archive" in all UI (route names/backend unchanged); archive page accessible to all authenticated users; document counts split into Active + Archived everywhere; restore gated by `documents.restore` privilege; permanent delete requires `documents.force-delete` privilege + reason + mandatory letter PDF upload (stored in `archive_letters/`, path recorded in `document_status_histories.metadata`); full audit trail with `actor_id` on all history rows
- **Scope-based upload permissions:** `division_id` FK added to `users`; `User::PRIVILEGES` constant as whitelist (prevents escalation); `User::canUploadTo()` / `canDeleteFrom()` helpers enforce scope in form request `authorize()` methods; division/section/department creation gated by `section.head`/`department.head`/`organization.head` privileges; admin user create/edit forms have cascading dept→section→division dropdowns and new privilege checkboxes; UI conditionally hides upload and creation buttons based on scope; legacy operators with no org assignment retain global access during initial data-entry phase

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

**Next up (after M23):** Queue job for extraction via `markitdown`, OCR fallback for scanned PDFs, split-pane review UI (PDF embed + editable Markdown), vault path file resolution on verification.

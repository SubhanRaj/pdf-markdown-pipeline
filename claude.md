# CLAUDE.md

Context file for Claude Code working in this repository. Read this fully before making changes.

## Who you're working with

**Subhan Raj** — Lead CSE Engineer, SIBIN Tech Solutions. BTech CSE (KMCLU). Handles full-stack development and DevOps/sysadmin (Windows/macOS/Linux) for this project, working with the UP Department of Excise on internal IT and AMC hardware matters.

**Operating mode for this repo:** Senior Full-Stack Engineer / Systems Architect pair-programming session. Skip basic conceptual explanations — assume strong familiarity with PHP, Laravel, server administration, and web architecture. Provide production-ready, modular code and direct CLI steps rather than tutorials. When changing `.env` values, DB connections, or Python venv setup, summarize the change before executing it.

**IDE diagnostics:** VSCode is configured with multiple Laravel-aware PHP plugins (Intelephense, Laravel Extra Intellisense, etc.) that produce false positives — `$level` "unused" (it's required by Laravel's route binding contract), `auth()->check()` "undefined method" (static analysis limitation on the auth facade), `Document` "unused" when used only as a closure parameter type hint. **Do not treat these as real errors.** Only act on diagnostics when there is genuine functional impact — wrong logic, missing imports, type mismatches that would cause a runtime exception.

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
| OCR | Selectable engine — Tesseract (`hin`+`eng`, default), EasyOCR, PaddleOCR, or Surya — invoked via `symfony/process`. **Never automatic** — triggered only by an explicit "Run OCR-Based Extraction" action (with an engine dropdown) from a human reviewer. See "Text Extraction & Markdown Conversion Pipeline" below and `config/ocr.php`. |
| Queue | Laravel **database** queue driver — deliberately no Redis, single-box local deployment |
| Disk | Single local filesystem disk (`public`); logical separation enforced by path convention, not multiple disks |
| Dev-only DB setup | [`subhanraj/laravel-db-provisioner`](https://github.com/SubhanRaj/laravel-db-provisioner) (`require-dev`) — `php artisan db:provision` generates a random per-project DB name/user/password rather than reusing a shared MariaDB admin account. Never used in production; see [DEPLOY.md](./DEPLOY.md#3-project-setup). |

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

**Division-based file path:** `document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/divisions/{division_slug}/{slug}_{YmdHis}.pdf`

**Rule-set-based file path:** `document_vault/{level}/{dept_slug}/rules/{rule_set_slug}/{slug}_{YmdHis}.pdf`

**Folder-based file path (section folder):** `document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/folders/{folder_slug}/{slug}_{YmdHis}.pdf`

**Folder-based file path (division folder):** `document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/divisions/{division_slug}/folders/{folder_slug}/{slug}_{YmdHis}.pdf`

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

### `divisions`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `section_id` | FK → sections | `restrictOnDelete` |
| `name` | string | Display name (free-form — e.g. "Pension Desk", "HRMS Cell") |
| `slug` | string | Auto-generated from name; unique per section |
| `description` | text nullable | Optional scope/function description (max 500 chars) |
| `timestamps` + `softDeletes` | | |

Unique constraint: `(section_id, slug)`. Slug generated via `Division::uniqueSlugForSection($name, $sectionId)` — checks `withTrashed()`. Slug is immutable after creation (vault paths depend on it).

### `folders`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `department_id` | FK → departments | `restrictOnDelete` |
| `section_id` | FK → sections | `restrictOnDelete` |
| `division_id` | FK → divisions **nullable** | `nullOnDelete` — non-null for division folders; null for direct section folders |
| `name` | string | Display name (e.g. "Court Case – Liquor License Appeal 2024") |
| `slug` | string | Auto-generated from name; uses `HasUnicodeSlug` trait |
| `description` | text nullable | Optional summary of the matter (max 500 chars) |
| `visibility` | string | `public` (default) \| `authenticated` — gates the folder page; contained docs keep their own visibility |
| `requires_approval` | boolean | default false — any upload to this folder triggers `pending_approval` |
| `metadata` | json nullable | Case number, year, tags, etc. |
| `timestamps` + `softDeletes` | | |

Unique constraint: `(section_id, division_id, slug)` — MySQL treats NULL as distinct, so section and division folders may share slugs.

Slug helpers:
- `Folder::uniqueSlugForSection($name, $sectionId, $exceptId?)` — unique within direct section folders (`division_id IS NULL`).
- `Folder::uniqueSlugForDivision($name, $divisionId, $exceptId?)` — unique within division folders.

Both check `withTrashed()` and append `-2`, `-3` on collision. **Folder slug is immutable after creation** — vault paths depend on it; `UpdateFolderRequest` does not accept a `slug` field.

**Archive cascade:** `FolderController@destroy` soft-deletes all contained documents (with `DocumentStatusHistory` rows) inside the same `DB::transaction()`, then soft-deletes the folder. `ManagesDocumentFiles::archiveFiles()` is called per document, physically moving files to the private disk. On folder restore, `restoreFiles()` is called for each document. Same pattern as `RuleSetController@destroy`.

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
| `section_id` | FK → sections **nullable** | `restrictOnDelete` — null for rule-set docs; always set for direct, division, and folder docs |
| `division_id` | FK → divisions **nullable** | `nullOnDelete` — non-null for division docs and division-folder docs; null otherwise |
| `rule_set_id` | FK → rule_sets **nullable** | `nullOnDelete` — non-null for rule-set docs only |
| `folder_id` | FK → folders **nullable** | `nullOnDelete` — non-null for folder docs (section-folder or division-folder); null for direct docs |
| `user_id` | FK → users nullable | `nullOnDelete` — uploader |
| `title` | string | human-readable document title / reference |
| `slug` | string | URL-safe; auto-generated from title at upload |
| `document_type` | string | `go` \| `policy` \| `notice` \| `court_order` \| `service_code` \| `rule` \| `rule_amendment` \| `other` |
| `original_filename` | string | |
| `original_pdf_path` | string | full relative path on `public` disk |
| `markdown_path` | string nullable | set after extraction job completes |
| `vault_path` | string nullable | vault directory path; set at upload |
| `status` | string | `uploaded` → `processing` → `ocr_pending` → `review` → `verified` \| `failed` |
| `visibility` | string | `public` (default) \| `authenticated` — controls guest access independently of status |
| `metadata` | json nullable | GO number, subject, dates, etc. |
| `timestamps` + `softDeletes` | | |

**Five-way FK exclusivity** — exactly one context group is active per row:

| Doc context | `section_id` | `division_id` | `rule_set_id` | `folder_id` |
|---|---|---|---|---|
| Direct section doc | non-null | null | null | null |
| Division doc | non-null | non-null | null | null |
| Rule-set doc | null | null | non-null | null |
| Section-folder doc | non-null | null | null | non-null |
| Division-folder doc | non-null | non-null | null | non-null |

Slug helpers:
- Section docs: `Document::uniqueSlugForSection($title, $sectionId)` — unique within direct section docs (`division_id IS NULL AND folder_id IS NULL`).
- Division docs: `Document::uniqueSlugForDivision($title, $divisionId)` — unique within the division (direct, no folder).
- Rule-set docs: `Document::uniqueSlugForRuleSet($title, $ruleSetId)` — unique within the rule set.
- Folder docs: `Document::uniqueSlugForFolder($title, $folderId)` — unique within the folder (both section-folder and division-folder docs use this).
All check `withTrashed()` and append `-2`, `-3` on collision. DB unique constraint remains `(section_id, division_id, slug)` — MySQL NULL-distinctness means folder docs don't collide with direct section or division docs sharing the same slug.

### `document_status_histories`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `document_id` | FK → documents | `cascadeOnDelete` |
| `actor_id` | FK → users nullable | `nullOnDelete` |
| `from_status` | string nullable | |
| `to_status` | string | |
| `note` | text nullable | |
| `metadata` | json nullable | Extra context per transition type. On `to_status = 'force_deleted'`: `{"letter_path": "archive_letters/...pdf", "reason": "..."}` |
| `created_at` | timestamp | append-only — no `updated_at` |

### `activity_logs`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `user_id` | FK → users nullable | `nullOnDelete` — null if user was later deleted; log rows are preserved |
| `action` | string | Route name (e.g. `documents.store`) or `auth.login` |
| `ip_address` | string(45) | IPv6-safe |
| `user_agent` | string(500) nullable | Browser/client UA, truncated to 500 chars |
| `metadata` | json nullable | `{"method": "POST", "url": "...", "status": 200}` — HTTP method, full URL, response status code. Login rows also include `"guard"`. |
| `created_at` | timestamp | append-only — no `updated_at` |

Append-only audit table. Only authenticated users are logged (guests are never recorded). Read-only from the application layer — no update/delete routes exist.

### `users`
Standard Laravel/Fortify users table extended with: `username` (unique), `mobile` (nullable, 10 digits, `+91`/`+91-` prefix stripped on save), `landline` (nullable, free-form STD+number e.g. `0522-223456`, max 20 chars), `post` (designation, nullable), `role` (`admin` | `operator` | `viewer`), `privileges` (JSON array of granular capability strings — see `User::PRIVILEGES` constant for the canonical whitelist), `uploads_require_approval` (boolean, default false — when true every document this user uploads goes to `pending_approval` regardless of context), `department_id` (FK → departments, nullable, `nullOnDelete`), `section_id` (FK → sections, nullable, `nullOnDelete`), `division_id` (FK → divisions, nullable, `nullOnDelete`). Public registration disabled — admin-created only. `User::isAdmin()` checks `role === 'admin'`; `User::hasPrivilege($key)` returns true for admins unconditionally.

**Privilege strings (canonical whitelist — `User::PRIVILEGES` constant):**
```php
'documents.upload'       // upload documents
'documents.edit'         // edit document metadata
'documents.delete'       // soft-delete (archive) documents
'documents.restore'      // restore documents from archive
'documents.force-delete' // permanently delete from archive (requires reason + letter upload)
'documents.verify'       // mark documents as verified
'documents.approve'      // approve/reject/reclassify pending uploads (scoped to upload boundary)
'organization.head'      // upload/delete anywhere across all departments
'department.head'        // scoped to their assigned department
'section.head'           // scoped to their assigned section
```

No `division.head` — division is the smallest unit; operators are scoped to a division via `division_id` assignment.

**Privilege escalation safety:** `StoreUserRequest` and `UpdateUserRequest` validate `privileges.*` as `in:` against `User::PRIVILEGES` — unknown strings are rejected. Privileges can only be set via `admin.*` routes (gated by `IsAdmin` middleware). `UpdateProfileRequest` has no privilege fields — self-escalation is impossible.

## What's built (as of 2026-07-13, updated)

### Modules / controllers

| Module | Controller | Notes |
|---|---|---|
| Dashboard | `FrontendController` | Public landing page with document stats; auth-aware recent feed; `pending_approval` count shown to admins/approvers |
| Documents | `DocumentController` | Full CRUD; AJAX-only store (handles section, division, rule-set, and folder uploads); PDF stream; hierarchical URLs; slug generation; soft-delete with reason; trash/restore/force-delete; `shouldRequireApproval()` check on every upload |
| Departments | `DepartmentController` | Full CRUD; slug-based route model binding; loads rule sets for show page |
| Sections | `SectionController` | Nested under departments; wing-aware; show page is the file browser + multi-file upload modal + folder cards; `requires_approval` toggle on edit page |
| Rule Sets | `RuleSetController` | Full CRUD; admin-only mutations; multi-file upload modal on show page pre-selects `rule_amendment` type; `requires_approval` toggle on edit page |
| Divisions | `DivisionController` | Full CRUD under sections; admin-only mutations; show page is division hub with multi-file upload modal, amendment hierarchy, and folder cards; `requires_approval` toggle on edit page |
| **Folders** | **`FolderController`** | **Full CRUD under sections (and optionally divisions); show page is a hub with upload modal + document list (amendment chain supported via `parent_id`); `requires_approval` toggle; archive cascades to all contained docs; visibility gate on folder page** |
| Search | `SearchController` | Public `GET /search?q=`; LIKE-based search across document titles, section names, rule set names/descriptions, folder names/descriptions; guests see `visibility = 'public'` docs and folders only; results capped at 50 docs + 20 sections + 20 rule sets + 20 folders; `->publishable()` scope hides pending/rejected |
| User management | `Admin\UserManagementController` | Admin-only CRUD + self-edit profile routes; `IsAdmin` middleware gates all `admin.*` routes; `editProfile`/`updateProfile` methods serve the `/profile` self-edit routes for non-admins; `division_id`, `uploads_require_approval` fields added; `documents.approve` privilege checkbox added |
| Archive | `DocumentController` (existing methods) | Soft-deleted documents accessible to all authenticated users; "Archive" in all UI; counts split active vs archived; restore gated by `documents.restore` privilege; permanent delete gated by `documents.force-delete` + requires reason + letter PDF upload — letter stored on the **private `local` disk** (`storage/app/private/archive_letters/`), never on public disk; letter path stored in `document_status_histories.metadata` |
| Activity Log | `Admin\ActivityLogController` | Admin-only audit view at `GET /admin/activity-logs`; filterable by user, action, and IP; paginates the `activity_logs` table (50/page); `LogMutation` middleware records all authenticated POST/PATCH/DELETE requests; `Login` event listener records every successful login with IP, UA, and guard |
| Approval Queue | `ApprovalController` | Maker-checker workflow at `GET /approvals`; three tabs (Pending / Rejected / My Submissions); approve, reject, reclassify, resubmit actions; scope-aware (approvers see only their org boundary); PDF preview via slide-over drawer; bulk approve/reject |
| **Text Extraction / OCR** | **`DocumentController` (`convert`, `convertOcr`, `conversionStatus`, `updateMarkdown`, `discardMarkdown`)** | **Button-triggered Markdown conversion (`ConvertDocumentToMarkdown` job) + on-demand OCR re-extraction (`RunOcrExtraction` job); Compare & Verify split-pane modal on `documents/show`; see dedicated section below** |
| **Bulk Upload** | **`DocumentController@bulkUploadForm`** | **`GET /documents/bulk-upload` — single page to upload multiple files to any department/section/division/folder/rule-set the user is scoped to, with optional auto-convert per file** |
| **Conversion Pipeline monitor** | **`DocumentController@pipeline`** | **`GET /documents/pipeline` — table of every document not yet verified/archived (`uploaded`/`processing`/`ocr_pending`/`review`/`failed`), status tabs, live polling, per-row Convert/Retry** |

### Text Extraction & Markdown Conversion Pipeline

**Implemented 2026-07-13.** Converts a document's original PDF into Markdown, with OCR available
as an explicit, human-triggered fallback — never automatic. This is a deliberate "man in the
middle" design: every upload defaults to the fast text-layer pass, a human decides whether the
result is good enough or needs OCR, nothing burns minutes of queue time until someone asks for it.

**Trigger — button, not automatic.** Conversion never auto-dispatches from `store()` or from
approval. A **Convert to Markdown** button on `documents/show` (and a per-row **Convert**/**Retry**
button on the Pipeline monitor, and an **auto-convert** checkbox on the Bulk Upload page) calls
`POST /documents/{id}/convert`, which dispatches `App\Jobs\ConvertDocumentToMarkdown`
(`ShouldQueue`, `$timeout = 900`). Both `convert()` and `convertOcr()` are gated by
`auth()->user()->isAdmin()` directly in the controller — same pattern as other admin-only
document mutations in this codebase (Form-Request-only or controller-only isAdmin() checks,
not the stricter `is_admin` route-group middleware, which is reserved for `admin.*` user
management routes).

**`ConvertDocumentToMarkdown` job — text-layer pass, always runs first:**
1. Runs `resources/python/pdf_structure_extractor.py --mode pdf` through the same venv Python
   `innobrain/markitdown:install` provisions (`vendor/innobrain/markitdown/python/venv/bin/python3`)
   — this script uses `pdfminer.six`'s low-level API (`extract_pages`, `LTChar`, `LTTextLine`)
   directly, not markitdown's own `pdfminer.high_level.extract_text()` converter, because the
   latter is plain-text only by its own documentation. The low-level API exposes per-character
   font size/name, which the script uses to infer heading levels and bold text.
   Also runs a geometric table-detection pass (`detect_tables()`/`TableBlock`/`render_table()`
   in the same script): lines are grouped into rows by y-position, and runs of ≥3 consecutive
   multi-cell rows with a well-filled grid (≥50% of cells non-empty — the guard against
   pdfminer sometimes splitting one justified body-text line into several fragments, which looks
   like a sparse 2-cell "table" otherwise) are rendered as real Markdown tables instead of being
   flattened into one paragraph. Applies uniformly across all extraction modes (`pdf`, `hocr`,
   `easyocr`, `paddleocr`; Surya gets its own path, see below), each populating `Line.x0/x1/y0`
   from whatever positional data that mode's source provides.
2. Quality-checks the output via `isGoodQuality()` — two independent failure signals, both
   meaning "don't trust this text layer":
   - `(cid:\d+)` glyph-ID fallback tokens (pdfminer couldn't resolve a character to Unicode
     because the embedded font has no usable ToUnicode CMap — very common in legacy
     non-Unicode Devanagari fonts like Kruti Dev/Chanakya/DevLys). More than 5 occurrences ⇒
     bad. Char-count alone doesn't catch this — a page full of `(cid:547)` garbage still has
     plenty of characters.
   - Near-empty text relative to page count (`char_count < page_count * 40`) — a real
     scanned/photographed page with no text layer at all.
3. Writes the Markdown regardless of quality (`status → review` either way) and sets
   `metadata.needs_ocr_review = true/false` — a bad text-layer result is still shown to the
   reviewer with a warning, not silently discarded, so nothing is stuck waiting on a human who
   doesn't know a document needs attention.

**`RunOcrExtraction` job — explicit, human-triggered only, never auto-dispatched, engine-selectable:**
1. `pdftoppm -png -r 300` rasterizes every page to PNG in a per-job temp dir under
   `storage/app/private/ocr_tmp/{uniqid}` (private disk, cleaned up in a `finally` block —
   page images are never retained after extraction).
2. Branches on `$this->engine` (constructor arg, from the review-modal dropdown, validated
   against `config('ocr.engines')` in `DocumentController::convertOcr()`):
   - **Tesseract** (default) — `tesseract <page> <outbase> -l hin+eng hocr` per page, hOCR
     output (not plain stdout text) because it carries per-line `x_size`/bbox, which
     `pdf_structure_extractor.py --mode hocr` needs for heading detection and table-row grouping
     on scanned documents. Uses the markitdown-provisioned venv Python.
   - **EasyOCR / PaddleOCR / Surya** — no separate raster→text step; each engine's own Python
     venv (`storage/app/private/ocr-engines/{engine}/`, provisioned once via `pip install`
     inside a pyenv 3.12.8 interpreter — Python 3.14 is too new for these engines' PyTorch/Paddle
     wheels) runs `pdf_structure_extractor.py --mode {engine}` directly against the page PNGs.
     PaddleOCR is pinned to `PP-OCRv5_mobile_det` + `devanagari_PP-OCRv5_mobile_rec` with
     `enable_mkldnn=False` (PaddleX's default oneDNN CPU backend crashes on this box's Paddle
     build with a `pir::ArrayAttribute` error — a Paddle/oneDNN compatibility bug, not something
     to chase further). Surya needs a `llama.cpp` binary + shared libs (not a pip dependency —
     see `OCR_RESEARCH.md`) pointed at via `LLAMA_CPP_BINARY`/`LD_LIBRARY_PATH`/
     `GGML_BACKEND_PATH` env vars passed through `Process::env()`, configured in
     `config('ocr.engines.surya.env')`.
3. Joins all pages and writes Markdown, `metadata.extraction_method = 'ocr'`,
   `metadata.ocr_engine = '<engine>'`, `metadata.needs_ocr_review = false`, `status → review`.
   Before overwriting, backs up the *current* Markdown to `{path}.pre-ocr.md` exactly once (never
   overwritten by later OCR re-runs) so a reviewer can revert.
4. All `Process::run()` calls use array-form arguments (no shell interpolation) — standard
   command-injection-safe pattern already used elsewhere in this codebase.

**Revert OCR back to text-layer extraction** — `POST /documents/{id}/revert-ocr`
(`revertOcr()`, admin-gated, 422 if the document isn't currently showing an OCR result or no
`.pre-ocr.md` backup exists). Restores that backup as the live Markdown, sets
`metadata.extraction_method = 'pdf-text'`, clears `metadata.ocr_engine`. Surfaced as a "Revert to
Text Extraction" button in the Compare & Verify modal, shown only when a backup is available
(`$canRevertOcr` in `show.blade.php`).

**Empirically tested and rejected: automatic OCR fallback.** An earlier iteration ran OCR
automatically whenever the text-layer pass looked low-quality. This was removed after two
concrete problems, both confirmed by testing, not assumed:
- With a single serial queue worker, one slow OCR job (minutes) blocked every document queued
  behind it — this is what caused the "stuck on converting" complaint that prompted the redesign.
- Running OCR on an already-good, native-text PDF *actively corrupts* correct text — verified by
  running Tesseract on `Haryana Excise Policy 2025-27.pdf` (page 1, already cleanly handled by
  the text-layer pass): **"150 meters" was silently changed to "50 meters" in four separate
  places**, plus `21 out of 22` → `2l out of 22` and dropped leading digits in section numbers.
  This is why OCR must never be allowed to override a working text layer without a human
  explicitly asking for it. See `OCR_RESEARCH.md` for the full write-up — PaddleOCR, EasyOCR,
  and Surya are now all actually wired in and selectable (2026-07-14), not just evaluated on the
  CLI; Tesseract remains the default. Surya is CPU-impractically slow for full pages on this
  hardware (see `OCR_RESEARCH.md`) but is left enabled for lighter documents.

**Compare & Verify modal (`documents/show`)** — split-pane review UI: original PDF (left) vs.
editable raw Markdown (right, `<textarea>`). Key behaviors:
- PDF `<iframe>` uses a deferred `data-src` attribute, assigned to `src` only when the modal is
  actually opened — a hidden (`display:none`) iframe gets a 0×0 viewport at load time and the
  browser's built-in PDF viewer never re-applies the `#view=FitH` zoom parameter once shown
  later, so the zoom silently failed until this was fixed.
- **Edit / Preview tabs** — the raw textarea is the source of truth (edits only happen there),
  but a **Preview** tab renders it client-side via `marked.js` (jsDelivr `marked@13`, page-scoped
  to this view via `@push('scripts')`, not loaded globally) into a `prose prose-sm dark:prose-invert`
  div — same rendered look as the verified-document view below. Rendered HTML is passed through
  the same `href`/`src` `javascript:`/`data:`/`vbscript:` strip used server-side (see
  `show.blade.php:254`) before being set via `innerHTML`, even though this is an admin-only,
  never-persisted preview — defense in depth over trusting `marked`'s own escaping.
  Reviewers previously only saw raw `**bold**`/`*italic*` markup while editing; this closes that
  gap without giving up the plain-textarea editing model (no CodeMirror/Monaco — not needed for
  the actual complaint, which was "I can't see formatting," not "I need a code editor").
- **Save & Verify** — `PATCH /documents/{id}/markdown` (`updateMarkdown()`, gated by
  `UpdateDocumentMarkdownRequest::authorize()` checking `isAdmin()`) saves edited Markdown and
  optionally marks the document `verified` in one action.
- **Discard Draft** — `DELETE /documents/{id}/markdown` (`discardMarkdown()`) is a one-time
  action: deletes the Markdown file, clears `extraction_method`/`needs_ocr_review`/
  `manually_edited` from metadata, resets `status → uploaded` so **Convert to Markdown**
  re-appears on the page. Blocked (422) once a document is `verified` — discarding an accepted
  record isn't a "draft rejection" at that point, it would destroy audit history.
- **Run OCR-Based Extraction** — lives inside this modal (not as a second banner/button on the
  page), always available (not gated on `needs_ocr_review` — reviewers can also just prefer a
  different engine's result). An engine `<select>` (populated from `config('ocr.engines')`,
  defaulting to `config('ocr.default')`) sits next to the button; the chosen engine key is sent
  as JSON body (`{ engine: ... }`) to `POST /documents/{id}/convert-ocr`. Shares the same polling
  helper (`startConversionPolling()`) as the page-level convert banner, parameterized by element
  ID so the two progress bars don't collide.
- **Revert to Text Extraction** — shown only when the current result is OCR-derived and a
  pre-OCR backup exists (`$canRevertOcr`). Calls `POST /documents/{id}/revert-ocr` and reloads on
  success; see the `RunOcrExtraction` section above for what it restores.
- The Markdown tab/card on `documents/show` is hidden entirely until `status = 'verified'` —
  pre-verification, only the amber "awaiting verification" banner + **Compare & Verify** button
  are shown above the PDF viewer (no separate OCR-recommended banner; the two were consolidated
  into one, with the OCR trigger moved inside the modal as above).
- Convert button never disappears on click — its icon swaps from `ti-markdown` to
  `ti-loader-2 animate-spin` (a spinning loader, deliberately **not** a spinning markdown logo)
  and the label changes to "Converting…", staying in place until the job completes.

**Bulk Upload (`GET /documents/bulk-upload`)** — one page to upload multiple files to any
department/section/division/folder/rule-set the user's `uploadScope()` permits, computed
server-side once (`DocumentController::buildUploadScopeTree()`) so the picker never offers a
context that would 403 on submit. Files upload sequentially (same one-`fetch`-per-file pattern
as the existing per-context upload modals); an **auto-convert** checkbox (checked by default)
fires `POST /documents/{id}/convert` immediately after each successful upload. **Known gap:**
`convert()` is admin-gated, so auto-convert silently no-ops (fails, caught by a `.catch()` that
only logs to console) for a non-admin operator with upload access — the UI doesn't yet surface
this. Not yet fixed; noted here so it isn't lost.

**Conversion Pipeline monitor (`GET /documents/pipeline`)** — a table of every document with
`status` in `uploaded`/`processing`/`ocr_pending`/`review`/`failed` (i.e. everything not yet
`verified` or archived), with status-filter tabs, a live count per status, and 5-second polling
on any row whose status is `processing`/`ocr_pending`. Viewing is unscoped (all authenticated
users see all departments' pipeline items) — consistent with this codebase's existing rule that
viewing is never scoped, only mutations are.

**Toolchain** (installed once; see `DEPLOY.md` for full reproducible setup):
```bash
composer require innobrain/markitdown erusev/parsedown
php artisan markitdown:install        # provisions its own venv
brew install tesseract tesseract-lang poppler   # hin+eng traineddata, pdftoppm/pdfinfo
```

### Route map

Routes have **no global prefix** — resources sit at the root. All models use `getRouteKeyName()` returning `'slug'` — IDs never appear in URLs.

**`{level}` URL segment** — departments share slugs across levels (e.g. `excise` exists at both `department_level` and `secretariat_level`). A `{level}` alias is inserted before `{department}` in every department/section/rule-set/document URL:
- `dept` → `department_level`
- `sectt` → `secretariat_level`

`Route::bind('department', ...)` in `AppServiceProvider::configureRouteBindings()` reads `request()->route('level')`, converts the alias to the DB value, and queries `WHERE slug = ? AND level = ?`.

`Route::bind('rule_set', ...)` scopes rule set lookups to `WHERE slug = ? AND department_id = ?` using the already-resolved `{department}` from the same request.

Controller method signatures **must** declare `string $level` as their first parameter (before model arguments) for any route containing `{level}`, or Laravel throws a `TypeError`.

`Department::levelAlias()` → URL alias for route helpers. `Department::levelLabel()` → human label for breadcrumbs.

**Documents**

| Method | URI | Route name | Auth |
|---|---|---|---|
| GET | `/documents` | `documents.index` | Public |
| POST | `/documents` | `documents.store` | Auth |
| GET | `/documents/{level}/{dept}/{section}/{doc}` | `documents.show` | Public* |
| PATCH | `/documents/{level}/{dept}/{section}/{doc}` | `documents.update` | Auth |
| DELETE | `/documents/{level}/{dept}/{section}/{doc}` | `documents.destroy` | Auth |
| GET | `/documents/{level}/{dept}/{section}/{doc}/pdf` | `documents.pdf` | Public* |
| GET | `/documents/{level}/{dept}/{section}/{doc}/review` | `documents.edit` | Auth |
| GET | `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}` | `documents.divisions.show` | Public* |
| PATCH | `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}` | `documents.divisions.update` | Auth |
| DELETE | `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}` | `documents.divisions.destroy` | Auth |
| GET | `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}/pdf` | `documents.divisions.pdf` | Public* |
| GET | `/documents/{level}/{dept}/{section}/divisions/{division}/{doc}/review` | `documents.divisions.edit` | Auth |
| GET | `/documents/{level}/{dept}/rules/{rule_set}/{doc}` | `documents.rules.show` | Public* |
| PATCH | `/documents/{level}/{dept}/rules/{rule_set}/{doc}` | `documents.rules.update` | Auth |
| DELETE | `/documents/{level}/{dept}/rules/{rule_set}/{doc}` | `documents.rules.destroy` | Auth |
| GET | `/documents/{level}/{dept}/rules/{rule_set}/{doc}/pdf` | `documents.rules.pdf` | Public* |
| GET | `/documents/{level}/{dept}/rules/{rule_set}/{doc}/review` | `documents.rules.edit` | Auth |
| GET | `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}` | `documents.folders.show` | Public* |
| PATCH | `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}` | `documents.folders.update` | Auth |
| DELETE | `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}` | `documents.folders.destroy` | Auth |
| GET | `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}/pdf` | `documents.folders.pdf` | Public* |
| GET | `/documents/{level}/{dept}/{section}/folders/{folder}/{doc}/review` | `documents.folders.edit` | Auth |
| GET | `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}` | `documents.divisions.folders.show` | Public* |
| PATCH | `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}` | `documents.divisions.folders.update` | Auth |
| DELETE | `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}` | `documents.divisions.folders.destroy` | Auth |
| GET | `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}/pdf` | `documents.divisions.folders.pdf` | Public* |
| GET | `/documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}/review` | `documents.divisions.folders.edit` | Auth |
| GET | `/documents/trash` | `documents.trash` | Auth |
| GET | `/documents/trash/{id}/pdf` | `documents.trashed.pdf` | Auth |
| POST | `/documents/trash/{id}/restore` | `documents.restore` | Auth |
| DELETE | `/documents/trash/{id}` | `documents.force-destroy` | Admin |
| POST | `/documents/trash/bulk-restore` | `documents.trash.bulk-restore` | Auth |
| DELETE | `/documents/trash/bulk-force-destroy` | `documents.trash.bulk-force-destroy` | Admin |
| POST | `/documents/bulk-destroy` | `documents.bulk-destroy` | Auth |
| GET | `/documents/bulk-upload` | `documents.bulk-upload` | Auth |
| GET | `/documents/pipeline` | `documents.pipeline` | Auth |
| POST | `/documents/{id}/convert` | `documents.convert` | Admin (controller check) |
| POST | `/documents/{id}/convert-ocr` | `documents.convert-ocr` | Admin (controller check) |
| POST | `/documents/{id}/revert-ocr` | `documents.revert-ocr` | Admin (controller check) |
| GET | `/documents/{id}/convert-status` | `documents.convert-status` | Auth (unscoped — see note) |
| PATCH | `/documents/{id}/markdown` | `documents.markdown.update` | Admin (Form Request check) |
| DELETE | `/documents/{id}/markdown` | `documents.markdown.discard` | Admin (controller check) |

*Public routes 403 on `visibility = authenticated` documents for guests. Folder doc routes additionally 403 if the containing folder's visibility is `authenticated` and the user is a guest.

**Note on `convert-status`:** any authenticated user can poll conversion status for any numeric document ID — it isn't scoped to visibility, department, or upload boundary. It only leaks processing metadata (`status`, `extraction_method`, `ocr_engine`, `needs_ocr_review`, `has_markdown`), never document content, but this is looser than every other document endpoint in this table. Flagged in `SECURITY.md` Pass 4 as a low-severity, not-yet-fixed information-disclosure gap.

**Departments, Sections, Divisions, Rule Sets, Folders**

| Method | URI | Route name | Auth |
|---|---|---|---|
| GET | `/departments` | `departments.index` | Public |
| POST | `/departments` | `departments.store` | Auth |
| GET | `/departments/{level}/{dept}` | `departments.show` | Public |
| PATCH | `/departments/{level}/{dept}` | `departments.update` | Auth |
| DELETE | `/departments/{level}/{dept}` | `departments.destroy` | Auth |
| GET | `/departments/{level}/{dept}/sections` | `departments.sections.index` | Public |
| POST | `/departments/{level}/{dept}/sections` | `departments.sections.store` | Auth |
| GET | `/departments/{level}/{dept}/sections/{section}` | `departments.sections.show` | Public |
| PATCH | `/departments/{level}/{dept}/sections/{section}` | `departments.sections.update` | Auth |
| DELETE | `/departments/{level}/{dept}/sections/{section}` | `departments.sections.destroy` | Auth |
| POST | `/departments/{level}/{dept}/sections/{section}/divisions` | `departments.sections.divisions.store` | Admin |
| GET | `/departments/{level}/{dept}/sections/{section}/divisions/{division}` | `departments.sections.divisions.show` | Public |
| PATCH | `/departments/{level}/{dept}/sections/{section}/divisions/{division}` | `departments.sections.divisions.update` | Admin |
| DELETE | `/departments/{level}/{dept}/sections/{section}/divisions/{division}` | `departments.sections.divisions.destroy` | Admin |
| POST | `/departments/{level}/{dept}/sections/{section}/folders` | `departments.sections.folders.store` | Auth |
| GET | `/departments/{level}/{dept}/sections/{section}/folders/{folder}` | `departments.sections.folders.show` | Public* |
| PATCH | `/departments/{level}/{dept}/sections/{section}/folders/{folder}` | `departments.sections.folders.update` | Auth |
| DELETE | `/departments/{level}/{dept}/sections/{section}/folders/{folder}` | `departments.sections.folders.destroy` | Auth |
| POST | `/departments/{level}/{dept}/sections/{section}/divisions/{division}/folders` | `departments.sections.divisions.folders.store` | Auth |
| GET | `/departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder}` | `departments.sections.divisions.folders.show` | Public* |
| PATCH | `/departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder}` | `departments.sections.divisions.folders.update` | Auth |
| DELETE | `/departments/{level}/{dept}/sections/{section}/divisions/{division}/folders/{folder}` | `departments.sections.divisions.folders.destroy` | Auth |
| POST | `/departments/{level}/{dept}/rules` | `departments.rules.store` | Auth |
| GET | `/departments/{level}/{dept}/rules/{rule_set}` | `departments.rules.show` | Public |
| PATCH | `/departments/{level}/{dept}/rules/{rule_set}` | `departments.rules.update` | Auth |
| DELETE | `/departments/{level}/{dept}/rules/{rule_set}` | `departments.rules.destroy` | Auth |

*Folder show routes 403 if `folder.visibility = 'authenticated'` and the user is a guest.

**Users & Profile**

| Method | URI | Route name | Auth |
|---|---|---|---|
| GET | `/admin/activity-logs` | `admin.activity.index` | Admin |
| GET | `/admin/users` | `admin.users.index` | Admin |
| POST | `/admin/users` | `admin.users.store` | Admin |
| GET | `/admin/users/create` | `admin.users.create` | Admin |
| GET | `/admin/users/{user}` | `admin.users.show` | Admin |
| PATCH | `/admin/users/{user}` | `admin.users.update` | Admin |
| DELETE | `/admin/users/{user}` | `admin.users.destroy` | Admin |
| GET | `/admin/users/{user}/edit` | `admin.users.edit` | Admin |
| GET | `/profile/edit` | `profile.edit` | Auth |
| PATCH | `/profile` | `profile.update` | Auth |

**Approval Queue**

| Method | URI | Route name | Auth |
|---|---|---|---|
| GET | `/approvals` | `approvals.index` | Auth |
| GET | `/approvals/{id}/pdf` | `approvals.pdf` | Auth |
| POST | `/approvals/{id}/approve` | `approvals.approve` | Auth + `documents.approve` privilege |
| POST | `/approvals/{id}/reject` | `approvals.reject` | Auth + `documents.approve` privilege |
| POST | `/approvals/{id}/reclassify` | `approvals.reclassify` | Auth + `documents.approve` privilege |
| POST | `/approvals/{id}/resubmit` | `approvals.resubmit` | Auth (own document only) |

Approval routes use **numeric `{id}`** not slugs — reclassification changes the document's context mid-flow, making slug-based URLs stale.

**Other**

| URI | Route name | Notes |
|---|---|---|
| `GET /` | `home` | Dashboard |
| `GET /search?q=` | `search.index` | Public full-text search |

### Slug-based routing (all models)

`Department`, `Section`, `Division`, `RuleSet`, `Folder`, and `Document` all override `getRouteKeyName()` to return `'slug'`. Route helpers accept model instances. Never pass `->id` manually to a route helper for these models.

Slug helpers:
- `Document::uniqueSlugForSection($title, $sectionId, $exceptId?)` — direct section docs (division_id IS NULL AND folder_id IS NULL)
- `Document::uniqueSlugForDivision($title, $divisionId, $exceptId?)` — direct division docs (folder_id IS NULL)
- `Document::uniqueSlugForRuleSet($title, $ruleSetId, $exceptId?)` — rule-set-scoped
- `Document::uniqueSlugForFolder($title, $folderId, $exceptId?)` — folder-scoped (both section-folder and division-folder docs)
- `Division::uniqueSlugForSection($name, $sectionId, $exceptId?)` — division slug within section
- `RuleSet::uniqueSlugForDepartment($name, $departmentId, $exceptId?)` — department-scoped
- `Folder::uniqueSlugForSection($name, $sectionId, $exceptId?)` — section-folder slug (division_id IS NULL)
- `Folder::uniqueSlugForDivision($name, $divisionId, $exceptId?)` — division-folder slug

All check `withTrashed()` and append `-2`, `-3` on collision.

**Section route binding** — `Route::bind('section', ...)` in `AppServiceProvider::configureRouteBindings()` scopes to `WHERE slug = ? AND department_id = ?` using the already-resolved `{department}`. This explicit binding is required so that `{section}` is guaranteed to be a `Section` model instance before the `{division}` and `{folder}` bindings fire.

**Division route binding** — `Route::bind('division', ...)` scopes to `WHERE slug = ? AND section_id = ?` using the already-resolved `{section}`.

**Folder route binding** — `Route::bind('folder', ...)` in `AppServiceProvider::configureRouteBindings()` scopes to `WHERE slug = ? AND section_id = ?`. If `{division}` is present in the route and already resolved, additionally scopes `AND division_id = ?`; otherwise `AND division_id IS NULL`. Declared after the `division` binding so the division model is available.

**Level-aware department binding** — see route map above. Controller methods must declare `string $level` as first parameter.

### Document upload flow

Upload is initiated from a section show page or rule set show page via a modal. The form POSTs to `POST /documents` via AJAX (`fetch`). The endpoint is **AJAX-only** and always returns JSON — `StoreDocumentRequest::failedValidation()` throws `HttpResponseException` with 422 JSON.

**Multi-file upload** — both modals support selecting multiple files at once (drag-and-drop or file picker with `multiple` attribute). Files are uploaded sequentially — one `fetch` per file, not in parallel — so the server never receives concurrent writes from the same session. Each file gets its own editable title input (pre-filled from the filename) in a scrollable queue panel on the left side of the modal. Document type and visibility are shared across the whole batch and set once in the right panel. Status badges on each queue row update in real time (`Pending → Uploading… → ✓ Done / ✗ error message`). After all files are processed: if all succeeded, redirect to the section/rule-set page; if some failed, show "N uploaded, M failed" with a "Go to page" button (navigates with the successful ones) or "Retry" if all failed. There is no server-side batching — `POST /documents` remains a single-document endpoint; the JS loop is the only batching layer.

**Initial status decision (applies to all upload paths):** After resolving the context (`$division ?? $section ?? $ruleSet`), `DocumentController@store` calls `$user->shouldRequireApproval($context)`. If true, `status = 'pending_approval'` and the document is hidden from all public/browse views until approved. If false, `status = 'uploaded'` (existing behaviour). The flash message adapts accordingly.

**Section-based upload (per file):**
1. Slug: `Document::uniqueSlugForSection($title, $section->id)`
2. Vault dir: `document_vault/{dept.level}/{dept.slug}/{section.wing?}/{section.slug}`
3. File stored: `{vaultDir}/{slug}_{YmdHis}.pdf` on `public` disk
4. DB transaction: `Document::create()` + `DocumentStatusHistory::create()`
5. On failure: delete orphaned PDF; return 500 JSON
6. On success: JSON `{'redirect': sections_url}`

**Division-based upload (per file):**
1. Slug: `Document::uniqueSlugForDivision($title, $division->id)`
2. Vault dir: `document_vault/{dept.level}/{dept.slug}/{section.wing?}/{section.slug}/divisions/{division.slug}`
3. Same file/DB/error flow as above; `section_id` AND `division_id` are both stored
4. On success: JSON `{'redirect': division_url}`
5. Parent options in the upload modal are all root docs in the **section** (not just the division) — cross-division amendments are permitted

**Rule-set-based upload (per file):**
1. Slug: `Document::uniqueSlugForRuleSet($title, $ruleSet->id)`
2. Vault dir: `document_vault/{dept.level}/{dept.slug}/rules/{ruleSet.slug}`
3. Same file/DB/error flow as above
4. On success: JSON `{'redirect': rule_set_url}`

**Folder-based upload (per file) — section folder:**
1. Slug: `Document::uniqueSlugForFolder($title, $folder->id)`
2. Vault dir: `document_vault/{dept.level}/{dept.slug}/{wing?}/{section.slug}/folders/{folder.slug}`
3. `section_id`, `folder_id` stored; `division_id` null
4. On success: JSON `{'redirect': folder_url}`

**Folder-based upload (per file) — division folder:**
1. Slug: `Document::uniqueSlugForFolder($title, $folder->id)`
2. Vault dir: `document_vault/{dept.level}/{dept.slug}/{wing?}/{section.slug}/divisions/{division.slug}/folders/{folder.slug}`
3. `section_id`, `division_id`, `folder_id` all stored
4. On success: JSON `{'redirect': folder_url}`
5. Parent options in the upload modal are all root docs in the **folder** (for amendment chains within the folder)

`StoreDocumentRequest` — `section_id`, `rule_set_id`, and `folder_id` are mutually exclusive contexts (`required_without_all:` group). `division_id` is optional, only valid alongside `section_id`. `folder_id` is optional, only valid alongside `section_id`; if the folder belongs to a division, `division_id` must also be provided. When `folder_id` is provided, the store branch uses `Folder::with('division.section.department')` to derive all parent context. Each fetch in the JS loop builds its own `FormData` with the per-file title and the shared type/visibility/context-ids — `FormData(form)` is **not** used because the file input is outside the `<form>` element (left vs right column layout).

**Converted Markdown** lands in the same vault directory, same base filename, `.md` extension. `markdown_path` stores the full relative path on `public` disk.

### PDF streaming

Section docs: `GET /documents/{level}/{dept}/{section}/{doc}/pdf` → `DocumentController@pdf`

Rule-set docs: `GET /documents/{level}/{dept}/rules/{rule_set}/{doc}/pdf` → `DocumentController@pdfRuleSetDoc`

Section-folder docs: `GET /documents/{level}/{dept}/{section}/folders/{folder}/{doc}/pdf` → `DocumentController@pdfSectionFolderDoc`

Division-folder docs: `GET /documents/{level}/{dept}/{section}/divisions/{division}/folders/{folder}/{doc}/pdf` → `DocumentController@pdfDivisionFolderDoc`

All stream from the `public` disk via `Storage::disk('public')->response(...)` with `Content-Disposition: inline`. Folder doc PDF routes additionally check `folder->visibility` — if `authenticated` and the user is a guest, abort 403. Always link via these routes — raw `Storage::url()` links bypass the auth gate.

### Document visibility

Documents carry a `visibility` column independent of the processing-status workflow:

| Value | Who can access |
|---|---|
| `public` (default) | All visitors, including unauthenticated guests |
| `authenticated` | Logged-in users only |

**Guest gate** — every public-facing query filters on `visibility = 'public'` for unauthenticated requests. The old `status = 'verified'` gate has been removed entirely. Applies to:
- `DocumentController@index/show/pdf` and `@showRuleSetDoc/@pdfRuleSetDoc` — `show` and `showRuleSetDoc` abort(403) if the document's visibility is `authenticated` and the user is a guest
- `SectionController@index/show` and `RuleSetController@show` — `withCount('documents')` and list queries are scoped to `visibility = 'public'` for guests
- `DepartmentController@index/show` — `withCount('documents')` on department, section, and rule-set rows scoped per auth state
- `FrontendController@dashboard` — stat counts (`total`, `uploaded`, `verified`) are scoped to public-only for guests; pipeline-only stats (`review`, `processing`, `failed`) return 0 to guests
- `SearchController@index` — filters on `visibility = 'public'` for guests

**Upload modals** — both section and rule-set upload modals include a visibility radio selector (defaults to Public). The `StoreDocumentRequest` validates and passes the value through to `Document::create()`.

**`documents/show`** — green "Public" or amber "Authenticated Only" badge shown in the document header.

**Key distinction:** `status` tracks the conversion pipeline (`uploaded → processing → review → verified`); `visibility` controls read access. A document can be `public` while still `uploaded` (guests can download the original PDF immediately), or `authenticated` while `verified` (internal-only even after full processing).

### Document views

- **`documents/show`** — context-aware: receives context flags `$isRuleSetDoc`, `$isDivisionDoc`, `$isSectionFolderDoc`, `$isDivisionFolderDoc`. Each flag switches breadcrumbs, page subtitle, vault path display, and all route helpers (PDF, edit, destroy). The "Section / Division / Rule Set / Folder" metadata label adapts accordingly. Visibility badge shown in header. When `$isSectionFolderDoc` or `$isDivisionFolderDoc`, the folder name + link are shown in the metadata sidebar.
- **`documents/index`** — tabbed by department; renders section, rule-set, and folder documents; row links follow routing priority: `$doc->folder ? ($doc->division ? documents.divisions.folders.show : documents.folders.show) : ($doc->division ? documents.divisions.show : ($doc->section ? documents.show : documents.rules.show))`. Display context name: `$doc->folder?->name ?? $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name`.

### Folder views

- **`folders/show`** — folder hub page: header (name, description, visibility badge, `requires_approval` status), action buttons (Edit, Archive), "Create Document" upload modal. Document list shows all docs in the folder with amendment hierarchy (parent_id chain). Supports same sort/filter (amendment number, effective year) as section/division show pages. Upload modal parent-selection lists root docs within the same folder (for intra-folder amendments). `->publishable()` scope applied to all document queries.
- **`folders/create`** and **`folders/edit`** — name + description + visibility radio + `requires_approval` toggle. Slug is auto-generated (create) and read-only (edit).

Folder pages respect `folder->visibility`: if `authenticated` and guest, abort 403.

### Search

`GET /search?q=` → `SearchController@index` → `search/index.blade.php`. Public route, no auth required.

**Query scope:** LIKE `%q%` on `documents.title`; also surfaces documents whose `section.name`, `rule_set.name`, or `folder.name` matches. Separate LIKE queries on `sections.name`, `rule_sets.name` + `description`, and `folders.name` + `description`. Guests see only `visibility = 'public'` documents and folders.

**Result ordering:** documents with a direct title match float first (via `CASE WHEN` `orderByRaw`), then by `created_at DESC`. Capped at 50 documents, 20 sections, 20 rule sets, 20 folders.

**View structure:** large search bar (autofocused, × clear button) → summary strip with total count → indigo callout explaining cross-taxonomy surfacing → Documents block (reuses `documents/index` row design) → Sections block (sky-accented) → Rule Sets block (violet-accented) → **Folders block (teal-accented; shows section/division context, description excerpt, visibility badge, link to folder show page)**.

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
- Each row has three actions: **View** (slide-over drawer), **Restore**, and **Delete Forever** (admin-only).
- Sidebar: "Trash" link (`ti-trash` icon) visible to all authenticated users. "All Documents" active-state check excludes `documents.trash` so it doesn't highlight incorrectly.

**Trash document slide-over drawer:**
- Opened by the "View" button on each row. A right-side panel slides in without leaving the trash page.
- Shows: title, department/context, document type, status badge, visibility badge, uploader + upload date, deletion reason, deleted-by + deleted-at.
- Embeds the PDF inline via `<iframe>` — PDF is served through `GET /documents/trash/{id}/pdf` → `DocumentController@trashedPdf`, which uses `Document::onlyTrashed()->findOrFail($id)` and streams from the **private `local` disk** at `archived_documents/{id}.pdf`. Route lives inside the `auth` middleware group — no raw `Storage::url()` links are used.
- For non-PDF uploads (or missing files) a "No PDF file attached" fallback is shown.
- Footer contains Restore and Delete Forever buttons with the same Swal2 confirmations as the row-level buttons.
- Drawer data is prepared server-side in `DocumentController@trash` as `$trashData` (a mapped collection) and passed to the view as a JSON data island (`<script id="trash-docs" type="application/json">`). The mapping must stay in the controller — Blade's parser mis-handles multi-line `fn()` arrow functions with bracket expressions inside `@json(...)`.
- Closes on backdrop click or Escape key.

**Trashed PDF route (`GET /documents/trash/{id}/pdf` → `documents.trashed.pdf`):**
- Auth-only. Resolves via `Document::onlyTrashed()->findOrFail($id)`. Streams from the **private `local` disk** at `archived_documents/{id}.pdf` — not the public disk. Aborts 404 if the file is missing.

**Soft delete from list views:**
- The delete button on `rule_sets/show` and `sections/show` document rows uses a `<button class="doc-delete-btn" data-action="..." data-title="...">` — no `<form>` is rendered inline. A JS handler fires a Swal2 modal that prompts for a reason, then dynamically builds and submits a hidden DELETE form. `Swal.escapeHtml` does not exist in Swal2 — use a local `esc()` helper for HTML-escaping user data in modal HTML.

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
- **`rule_sets/show`** — header + two state-aware upload buttons + two independent modals + hierarchy document list.

**Two upload modals (separate, not combined):**

- **`#modal-rule`** (Upload Rule Document) — indigo accent; type dropdown shows all types except `rule_amendment`, pre-selects `rule`; no parent field. Button is disabled once a rule-type doc exists for this rule set (`$rootDocuments->where('document_type', 'rule')->isNotEmpty()`).
- **`#modal-amendment`** (Upload Amendment) — amber accent; type is a fixed hidden input (`rule_amendment`) shown as read-only badge; requires parent document selection from `$parentOptions` dropdown (root docs only, auto-selects if exactly one exists). Button is disabled until a `rule` doc exists.

Both modals share a `makeQueue(ids)` JS factory function that handles multi-file queue, drag-and-drop, per-row title editing, sequential upload loop, and post-upload redirect/error handling. Each modal has its own set of element IDs passed via the `ids` object.

**Edit lock on rule docs with amendments:** `documents/show.blade.php` — if `$document->document_type === 'rule'` and `$document->amendments->isNotEmpty()`, the Edit button is replaced with a greyed-out disabled `<span>`.

**Cascade delete:** `RuleSetController@destroy` — before soft-deleting the rule set, iterates all documents via `$ruleSet->documents()->each(...)`, writes a `DocumentStatusHistory` row per doc, then soft-deletes each doc — all inside the same `DB::transaction()`. Users do not need to delete documents manually before deleting a rule set.

### Maker-Checker Approval Workflow

A two-stage upload approval system layered on top of the existing upload flow. Pending and rejected documents are completely hidden from all regular document lists (`sections/show`, `divisions/show`, `rule_sets/show`, `documents/index`, search, dashboard) via the `->publishable()` Eloquent scope on the `Document` model.

**Status flow:**
```
Upload → shouldRequireApproval()?
    YES → pending_approval ──→ approve   → uploaded  → (normal pipeline)
                            ↘ reject    → rejected  → resubmit → pending_approval (loop)
                            ↘ reclassify → moves file, updates FKs, optional approve
    NO  → uploaded → (normal pipeline, no change)
```

**Two independent triggers for `pending_approval`:**
1. `users.uploads_require_approval = true` — enables bulk-onboarding mode for that user; every document they upload goes to `pending_approval`, regardless of destination.
2. `sections/divisions/rule_sets.requires_approval = true` — any upload to that context is held, even from operators whose user flag is off. Useful for sensitive sections (Legal, Audit, etc.).

`User::shouldRequireApproval(Section|Division|RuleSet|Folder $context): bool` — returns true if either trigger applies.

**Approval scope:** Follows the existing upload scope hierarchy exactly — `canApprove($context)` wraps `canUploadTo($context)` with an additional `documents.approve` privilege gate. An approver can only act on documents within their own org boundary (same section/department they can upload to). Cross-boundary reclassification requires `global` scope (org.head or admin).

**`ApprovalController` methods:**
- `index(Request $request)` — renders the queue with three tabs: Pending / Rejected / My Submissions. Approvers see all docs in their scope; non-approvers see only their own submissions.
- `approve(int $id, ApproveDocumentRequest $request)` — validates `pending_approval`, checks `canApprove()`, writes history row, sets `status = 'uploaded'`.
- `reject(int $id, RejectDocumentRequest $request)` — validates `pending_approval`, checks `canApprove()`, writes history row with reason, sets `status = 'rejected'`.
- `reclassify(int $id, ReclassifyDocumentRequest $request)` — resolves new context, checks `canApprove()` on BOTH old AND new context, computes new vault path + slug, moves PDF (and markdown if present) on the `public` disk via `Storage::disk('public')->move()` (atomic same-disk rename), updates all FKs + paths in a transaction, optionally approves in the same step.
- `resubmit(int $id)` — operator can resubmit their own rejected document back to `pending_approval`.
- `pdf(int $id)` — streams the PDF for a pending/rejected document (auth-only, stays on public disk since these files haven't been archived).

**`->publishable()` scope** (`Document::scopePublishable`) — `whereNotIn('status', ['pending_approval', 'rejected'])`. Applied in: `SectionController@show`, `DivisionController@show`, `RuleSetController@show`, `SearchController@index`, `FrontendController@dashboard`.

**New `requires_approval` toggle** — visible on `sections/edit`, `divisions/edit`, `rule_sets/edit`. Gated: admin or `department.head` for sections/rule_sets; admin, `department.head`, or `section.head` for divisions.

**`uploads_require_approval` toggle** — added to `admin/users/create` and `admin/users/edit` forms. `StoreUserRequest` and `UpdateUserRequest` both validate it as `nullable boolean`.

**Approval queue UI (`resources/views/approvals/index.blade.php`):**
- Three tab pills: Pending Approval (amber count) / Rejected (red count) / My Submissions (slate count)
- Table rows built from JSON data islands (same pattern as trash view) via JS `buildRows()` function
- Slide-over drawer — PDF preview via `approvals.pdf` route + metadata strip + rejection reason + action buttons
- Approve: Swal2 confirmation with optional note field
- Reject: Swal2 with required reason textarea (min 5 chars validated client-side)
- Reclassify: dedicated Blade modal with cascading section → division OR rule set selects, populated from JSON data islands; "Approve after reclassifying" checkbox
- Resubmit: Swal2 confirmation (My Submissions tab only)
- Bulk approve + bulk reject via action bar that appears when checkboxes are selected (Pending tab only)

**Sidebar:** "Approval Queue" nav link with amber badge. Badge count: approvers see all pending in their scope; non-approvers see own pending+rejected count. Always visible to all authenticated users.

### Archive module (formerly Trash)

**Terminology:** The feature is called "Archive" in all UI text. Backend route names, controller method names, and DB mechanism (`SoftDeletes`, `onlyTrashed()`, `withTrashed()`, `deleted_at`) are intentionally unchanged to avoid breaking changes.

**Visibility:** Archive page (`GET /documents/trash` → `documents.trash`) is accessible to all authenticated users — not guests, but any role (viewer, operator, admin). Guests cannot access the archive.

**Document counts:** All places that show document counts (dashboard, department show, section show, rule set show) display two figures: **Active** (non-deleted, `Document::count()`) and **Archived** (`Document::onlyTrashed()->count()`). The `withCount('documents')` relation on departments/sections/rule sets already excludes soft-deleted records via `SoftDeletes` — active count is automatic. Archived count requires a separate `withCount(['documents as archived_documents_count' => fn($q) => $q->onlyTrashed()])`.

**Restore permission:** Gated by `documents.restore` privilege. `DocumentController@restore` checks `auth()->user()->hasPrivilege('documents.restore')` before proceeding. Admins always pass.

**Permanent delete (force-delete) permission + letter:**
- Gated by `documents.force-delete` privilege (admins always pass).
- Requires: reason text (5–500 chars) + a letter PDF upload confirming the deletion authority.
- Letter stored on the **`local` (private) disk** at `archive_letters/{document_id}_{YmdHis}.pdf` — `Storage::disk('local')` not `public`, so the letter is never web-accessible via the storage symlink.
- A `DocumentStatusHistory` row is written with `to_status = 'force_deleted'`, `note` = reason, `metadata` = `{"letter_path": "archive_letters/...pdf"}`.
- Then `$document->forceDelete()` physically removes the original PDF and Markdown from disk, and hard-deletes the DB record. `document_status_histories` rows (including the letter row) cascade-delete.
- The `archive_letters/` directory lives at `storage/app/private/archive_letters/` (local disk, no symlink). Letter PDFs are internal admin records; back up this directory separately. To retrieve a specific letter, an admin-only download route can be added — current access is filesystem-only.

**Permanent delete modal:** SweetAlert2 is not used for permanent delete (because file upload is required). Instead, a separate Blade modal (`#modal-force-delete`) handles: reason textarea + letter file input + confirmation checkbox before the form submits. The modal is triggered by the "Delete Permanently" button in the archive view.

### Scope-Based Upload & Delete Permissions

Every mutating action (upload, delete/archive, restore, force-delete) is scoped to the user's organisational assignment. Viewing is never scoped — all authenticated users can see all documents.

**User assignment → scope:**

| User has | Can upload to | Can archive (delete) from |
|---|---|---|
| `division_id` set | That division only | That division only |
| `section_id` set, no `division_id` | All of that section (direct docs + all its divisions) | Same |
| `department_id` set, no `section_id` | All sections + divisions in that department | Same |
| `department.head` privilege + `department_id` | Entire assigned department | Same |
| `organization.head` privilege | Anywhere across all departments | Same |
| Admin | Anywhere | Anywhere |
| Operator with `documents.upload` and no dept/section/division | Anywhere (legacy mode — for initial data entry; scope to be tightened by revoking `documents.upload` once the initial load is complete) | Anywhere if also has `documents.delete` |

Cross-section and cross-division mutations are blocked — a division user cannot touch another division's documents even within the same section.

**Helper methods on `User`:**
```php
User::canUploadTo(Section|Division|RuleSet|Folder $context): bool
User::canDeleteFrom(Section|Division|RuleSet|Folder $context): bool
User::uploadScope(): string  // 'global'|'department'|'section'|'division'|'none'
```

For `Folder` contexts, `canUploadTo()` resolves the folder's owning section (or division) and applies the same scope rules as if that section/division were passed directly.

**Form Request `authorize()` gates:**
- `StoreDocumentRequest::authorize()` — resolves context from validated `section_id`/`division_id`/`rule_set_id`, calls `canUploadTo()`
- `DeleteDocumentRequest::authorize()` — resolves context from the route-bound document, calls `canDeleteFrom()`
- `StoreDivisionRequest::authorize()` — `section.head` (matching parent section) OR `department.head`/admin
- `UpdateDivisionRequest::authorize()` — same
- `StoreSectionRequest::authorize()` — `department.head` (matching parent department) OR admin
- `UpdateSectionRequest::authorize()` — same
- `StoreDepartmentRequest::authorize()` — `organization.head` OR admin
- `UpdateDepartmentRequest::authorize()` — same

**UI gating (Blade conditionals):**
- Upload buttons on `sections/show`, `divisions/show`, `rule_sets/show` — wrapped in `@can`-style check using `$user->canUploadTo($context)`
- "Add Division" button on `sections/show` — visible to `section.head` for that section, or `department.head`, or admin
- "Add Section" button and "Add Rule Set" button on `departments/show` — visible to `department.head` for that department, or admin
- "Add Department" button on `departments/index` — visible to `organization.head`, or admin
- Restore button on archive page — visible only if `hasPrivilege('documents.restore')` or admin
- Permanent delete button on archive page — visible only if `hasPrivilege('documents.force-delete')` or admin

### User management & profile

**Security model** — two distinct access tiers enforced at the route layer, not just in Form Requests:

| Tier | Routes | Middleware | What's accessible |
|---|---|---|---|
| Admin CRUD | `admin.*` | `auth` + `is_admin` | Full user list, create, show, edit any user, delete, role/privilege assignment |
| Self-edit profile | `profile.*` | `auth` | Own name/username/email/mobile/post/password only — no role or privilege fields |

`admin.*` routes are gated by `IsAdmin` middleware (`app/Http/Middleware/IsAdmin.php`, alias `is_admin`, registered in `bootstrap/app.php`). This was the critical fix: previously only `auth` middleware was applied, allowing any authenticated user to list all accounts, access the create form, and delete other users.

**Form Requests:**
- `StoreUserRequest` — `authorize()` requires `isAdmin()`; validates all user fields including role.
- `UpdateUserRequest` — `authorize()` requires `isAdmin()`; validates all fields including role/privileges/dept/section. Used only by `admin.users.update`.
- `UpdateProfileRequest` — `authorize()` requires any authenticated user (`$this->user() !== null`); validates name/username/email/mobile/post/password only. Scopes `unique` checks to `auth()->user()->id`. No role, privilege, department, or section fields — those cannot be self-assigned.

**Views:**
- `admin/users/index.blade.php` — paginated user table (admin-only).
- `admin/users/create.blade.php` — account creation form with full role/privilege/dept/section fields (admin-only).
- `admin/users/edit.blade.php` — full edit form including role, privileges, department, section (admin-only route).
- `admin/users/show.blade.php` — read-only user profile card (admin-only).
- `profile/edit.blade.php` — self-edit form: name/username/email/mobile/post/password. Role, department, and section shown as read-only display values. No role or privilege inputs rendered. JS validation identical to admin edit (same regex ruleset, password strength meter, toggle visibility).

**Controller methods:**
- `UserManagementController@editProfile` — resolves `auth()->user()`, passes to `profile.edit` view with departments/sections for display.
- `UserManagementController@updateProfile` — uses `UpdateProfileRequest`; updates only the allowed fields; never touches role/privileges/dept/section.
- `UserManagementController@destroy` — self-delete guard uses `auth()->id()` (not `auth()->user()->id`) to avoid the nullable dereference.

**Demo seeder accounts (`database/seeders/UserSeeder.php`):**

Seeder is idempotent (`firstOrCreate` on email). Run with `php artisan db:seed --class=UserSeeder`.

| Role | Email | Password | Privileges |
|---|---|---|---|
| Admin | `shubhanraj2002@gmail.com` | `Admin@1234` | `['*']` — primary dev account |
| Admin (demo) | `admin.demo@excise.up.gov.in` | `Admin@1234` | `['*']` — Deputy Commissioner persona |
| Operator (full) | `operator.full@excise.up.gov.in` | `Operator@1234` | upload + edit + delete + restore + verify |
| Operator (upload-only) | `operator.upload@excise.up.gov.in` | `Operator@1234` | `['documents.upload']` only |
| Operator (review/verify) | `operator.review@excise.up.gov.in` | `Operator@1234` | edit + verify only |
| Viewer | `viewer@excise.up.gov.in` | `Viewer@1234` | `[]` — read-only authenticated |

**Previously identified vulnerabilities (now fixed):**
1. All `admin.*` routes had only `auth` middleware — any logged-in user could view the full user list, access the create form, and delete other accounts. Fixed by adding `is_admin` middleware to the entire `admin.*` group.
2. `UpdateUserRequest::authorize()` was the only admin gate for updates — the GET routes (index, create, show, edit) had no gate at all. Fixed by middleware.
3. `destroy` had a self-delete guard but no admin check — any authenticated user could delete any other user's account. Fixed by middleware.
4. No self-edit path existed for non-admin users — attempting to use `admin.users.edit` on own record with non-admin credentials would 403 on save even though the GET succeeded. Fixed by adding dedicated `profile.*` routes.

### Sidebar auth states

| State | Sections shown |
|---|---|
| Guest | Browse Vault + Departments (→ `departments.index`) |
| Authenticated | Browse Vault + Manage → Departments |
| Admin | Browse Vault + Manage → Departments + Users + Activity Log |

**Sidebar user strip (bottom)** — the avatar initial and display name are clickable links for all authenticated users. Admins are linked to `admin.users.edit` (their own record); non-admins are linked to `profile.edit`. Guests see a static "G" avatar with a login icon.

**Browse Vault is fully dynamic** — `sidebar.blade.php` queries all `Department` records ordered by level then name. Icon and color resolved from a `$deptMeta` slug → `[icon, color]` map; unknown slugs fall back to a cycling palette. Slug keys use underscores (matching DB slugs), e.g. `sugarcane_sugar`.

**Pipeline / Bulk Upload nav links** — `Pipeline` (linking to `documents.pipeline`) sits under the main document nav with an unscoped live count badge (`Document::whereIn('status', [...])->count()`, all departments, matching the "viewing is never scoped" rule). `Bulk Upload & Convert` (linking to `documents.bulk-upload`) sits under "Tools", visible only when `auth()->user()->uploadScope() !== 'none'`; the header's "New Conversion" CTA button links to the same route under the same gate. Both replace what were previously placeholder/"Coming soon" entries.

### Rate limiting

Named limiters defined in `AppServiceProvider::boot()`. Never use anonymous `throttle:60,1` inline.

| Limiter name | Limit | Key |
|---|---|---|
| `login` | 5/min per email+IP + 10/min per IP | Fortify brute-force |
| `two-factor` | 5/min per session+IP | Fortify 2FA |
| `mutations` | 60/min | user ID or IP — all auth POST/PATCH/DELETE groups |
| `uploads` | 20/min | user ID or IP — `POST /documents` only (on top of mutations) |

### File upload validation

Always use `mimetypes:` (not `mimes:`) — reads actual file bytes via PHP Fileinfo (magic-byte check); `mimes:` only checks extension. Accepted types defined as `StoreDocumentRequest::ACCEPTED_MIMETYPES` — reference this constant from tests or other Form Requests rather than duplicating the list.

Current accepted types: PDF, Word (doc/docx), Excel (xls/xlsx), PowerPoint (ppt/pptx), ODT/ODS/ODP, RTF, TXT, CSV, JPEG, PNG, WebP, GIF, TIFF, BMP, HEIC/HEIF. **SVG is explicitly excluded** — it is XML with executable script content and has no valid use case in a government document vault. Max size: 50 MB.

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
10. **Five-way document taxonomy** — documents belong to one of five contexts: a direct `Section` (GOs, notices, circulars), an `Internal Division` (desk/cell-issued orders), a `RuleSet` (Acts, Rules, amendments), a `Section Folder` (patravali/case file under a section), or a `Division Folder` (patravali/case file under a division). FK layout: `folder_id` non-null = folder doc (also has `section_id`; may have `division_id`); `rule_set_id` non-null = rule-set doc; `section_id` + `division_id` both non-null + `folder_id` null = direct division doc; `section_id` non-null + `division_id` null + `folder_id` null = direct section doc. The `documents/show` view handles all five contexts via flags — no template duplication. Routing priority when iterating: `$doc->folder ? ($doc->division ? documents.divisions.folders.show : documents.folders.show) : ($doc->division ? documents.divisions.show : ($doc->section ? documents.show : documents.rules.show))`. Display context name: `$doc->folder?->name ?? $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name`.
11. **Internal divisions are sub-entities of sections, not replacements** — a `Division` belongs to a `Section`. Division docs carry both `section_id` (always set — the issuing authority) and `division_id` (the internal grouping). This models the real-world situation where every letter is issued by the section regardless of which internal desk handles the matter. Sections can have both direct docs and divisions simultaneously. Amendments can cross division boundaries — parent options on the division upload modal list all root docs in the section, not just the division.
11a. **Division slug is immutable after creation** — `UpdateDivisionRequest` does not accept a `slug` field; the edit form shows slug as read-only. Changing the slug would break all existing vault file paths under `divisions/{slug}/`.
12. **Rule-set slug is immutable after creation** — `UpdateRuleSetRequest` does not accept a `slug` field; the edit form shows slug as read-only. Changing the slug would break all existing vault file paths.
12. **Two-stage document deletion** — `DELETE /documents/…` soft-deletes only (sets `deleted_at`). Physical files are never removed at this stage. Permanent file+record removal requires a second explicit action from the trash view (`DELETE /documents/trash/{id}`). This preserves recoverability and the full audit trail until an admin consciously decides to purge. The deletion reason is always captured and stored in `document_status_histories` before the soft-delete occurs.
13. **SweetAlert2 for all confirmations** — all destructive-action confirmations use `Swal.fire()` (loaded globally via jsDelivr `sweetalert2@11`). Never use `window.confirm()` or inline `onsubmit` confirm checks. Respect dark mode by passing `background` and `color` based on `document.documentElement.classList.contains('dark')`.
15. **Archive = Trash in UI only** — "Trash" is renamed to "Archive" across all Blade views. Route names (`documents.trash`, `documents.restore`, `documents.force-destroy`), controller method names (`trash()`, `restore()`, `forceDestroy()`), and the soft-delete mechanism (`SoftDeletes`, `deleted_at`, `onlyTrashed()`, `withTrashed()`) are intentionally unchanged. Renaming them would require updating every route reference across dozens of views and routes files for zero functional gain.

16. **Scope-based permissions use `division_id` on `users`, not a pivot table** — a pivot table (`user_upload_scopes`) would be more flexible but is premature here. Each user has one organisational home (department → section → division). A single FK chain is sufficient for the government hierarchy modelled here and avoids the JOIN complexity of a pivot. Pivot can be introduced later if multi-scope assignments become necessary.

17. **Legacy operator "anywhere" upload** — operators with `documents.upload` and no `department_id`/`section_id`/`division_id` assigned can upload anywhere. This is deliberate for the initial data-entry phase (all legacy documents need to be digitised before per-scope restrictions make sense). Once the initial load is done, revoke `documents.upload` from these accounts or assign them a scope.

18. **Permanent delete requires a letter PDF stored on the private disk** — permanently removing an archived document is an irreversible administrative action. A formal letter (upload authority, reason, date) must accompany the action. The letter is stored via `Storage::disk('local')` (the private disk at `storage/app/private/archive_letters/`) — **never on the `public` disk** — so it is never web-accessible via the storage symlink. Its path is written to `document_status_histories.metadata` before the hard delete executes. Back up `storage/app/private/archive_letters/` separately; it is the only surviving paper trail after the record is hard-deleted.

19. **Archived document files are physically moved to the private disk on soft-delete** — `ManagesDocumentFiles` trait (`app/Http/Controllers/Concerns/`) provides `archiveFiles()`, `restoreFiles()`, and `deleteArchivedFiles()` methods used by `DocumentController` and `RuleSetController`. On soft-delete, PDF and Markdown files are moved from the `public` disk to `storage/app/private/archived_documents/{id}.pdf`. On restore, they move back to their original vault path on the `public` disk. On permanent delete, they are deleted from the private disk. This means: (a) active public documents are directly accessible via `/storage/document_vault/…` URLs — by design, for sharing and search indexing; (b) archived documents are physically off the public disk and unreachable by any URL. `public/.htaccess` retains a 403 block only for `/storage/archive_letters/` as defence-in-depth. The `document_vault` block was intentionally removed to allow direct URL access to active documents.

20. **SVG files are permanently excluded from accepted upload types** — `image/svg+xml` is not in `StoreDocumentRequest::ACCEPTED_MIMETYPES` and must not be added. SVG is XML that can contain executable `<script>` elements and event handlers. Even with the forced `.pdf` storage extension, accepting SVG creates a markitdown-extraction attack chain that could introduce stored XSS via the Parsedown rendering path.

21. **Security response headers on every response** — `app/Http/Middleware/SecurityHeaders.php` is registered globally via `$middleware->append(...)` in `bootstrap/app.php`. It sets `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, and `Content-Security-Policy` on every response. HSTS is sent only when the request is over HTTPS. Never remove this middleware.

22. **Department level binding is strict — unknown aliases abort 404** — `Route::bind('department', ...)` in `AppServiceProvider` uses an explicit two-branch `match` (`'dept'` → `department_level`, `'sectt'` → `secretariat_level`, `default` → `abort(404)`). There is no silent fallthrough. Do not add a `default =>` case that resolves to a level value.

23. **Bulk restore enforces the same per-document scope as single restore** — `DocumentController@bulkRestore()` checks `canDeleteFrom($context)` for each document in the loop. Out-of-scope documents are skipped silently. This prevents a division-scoped operator from bulk-restoring documents from other departments by sending foreign IDs.

24. **Bulk force-delete requires a reason and writes per-document audit rows** — `BulkForceDestroyDocumentsRequest` validates a mandatory `reason` field. `DocumentController@bulkForceDestroy()` writes a `DocumentStatusHistory` row per document before `forceDelete()`. The UI collects the reason via a two-step Swal2 flow (textarea prompt → final confirmation).

14. **`visibility` is the sole guest access gate** — the old `status = 'verified'` filter for guests has been removed. Access control for unauthenticated users is now exclusively determined by `documents.visibility` (`public` | `authenticated`). The `status` column tracks only the conversion pipeline state and must never be used as an access gate. When writing any query that serves public-facing views, filter on `visibility = 'public'` for guests — never on `status`.

25. **`pending_approval` and `rejected` are hidden from all regular document views via `->publishable()` scope** — `Document::scopePublishable()` applies `whereNotIn('status', ['pending_approval', 'rejected'])`. Must be added to every controller query that populates a regular browse/list view. The only place these statuses appear is `GET /approvals`. Never skip this scope in public-facing queries.

26. **Approval scope equals upload scope** — `canApprove($context)` is `canUploadTo($context)` plus the `documents.approve` privilege gate. The same org-boundary rules (global / department / section / division) apply to approval as to upload. This is intentional: an officer who can upload to a section is the right person to also approve uploads in that section. Do not introduce a separate approval-scope mechanism.

27. **Reclassification moves files on the public disk, not across disks** — pending/rejected documents have NOT been archived (they stay on the `public` disk). Reclassification uses `Storage::disk('public')->move($from, $to)` — an atomic filesystem rename when source and destination are on the same volume. This is different from the archive flow which moves across disks (public → local private). Do not use `archiveFiles()` / `restoreFiles()` for reclassification.

28. **`uploads_require_approval` is a per-user bulk-mode flag, not a permanent restriction** — it is designed to be toggled on during initial legacy-document onboarding and turned off once done. It is independent of the context-level `requires_approval` flag on sections/divisions/rule_sets/folders, which is a permanent per-context policy. Either flag alone is sufficient to trigger `pending_approval`.

29. **Folders (Patravalis) are physical-file groupings, not organizational units** — `Section` and `Division` model the org chart (who issues the letter). `Folder` models the physical filing concept (a named dossier grouping all correspondence on a specific matter — court case, license dispute, audit query, service matter). Folders live under a section or division. A section/division can have both direct docs and folders simultaneously. Folders are not nested. Folder slug is immutable after creation (vault paths depend on it). `UpdateFolderRequest` does not accept a `slug` field. `shouldRequireApproval()` accepts `Folder` as a valid context type alongside `Section|Division|RuleSet`.

30. **Folder visibility gates the folder page; contained doc visibility is independent** — if `folder.visibility = 'authenticated'`, the folder show page and its document PDF routes abort 403 for guests. Individual documents within the folder still carry their own `visibility` field — a public document inside an authenticated folder is reachable by direct URL (since the vault path is not secret once you know it), but you cannot browse to it via the folder. Do not cascade folder visibility to documents; enforce it only at the folder page and folder-doc route level.

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
| Tailwind CSS (Play CDN, `typography` plugin) | `https://cdn.tailwindcss.com?plugins=typography` — the `typography` plugin is required; it's what makes the `prose`/`prose-invert` classes actually render (they're inert without it) |
| Tabler Icons (webfont) | jsDelivr — `@tabler/icons-webfont@3.30.0` |
| Chart.js | jsDelivr — `chart.js@4.4.7` |
| SweetAlert2 | jsDelivr — `sweetalert2@11` |
| marked.js | jsDelivr — `marked@13` — page-scoped (`@push('scripts')` in `documents/show.blade.php` only, not global); client-side Markdown→HTML for the Compare & Verify editor's live Preview tab |

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

#### Unicode / Rajbhasha (Devanagari) regex policy

All non-user human-readable text fields (`title`, `name`, `description` free-text) use the Unicode category class pattern:

```
/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u
```

`\p{L}` = letters, `\p{M}` = combining marks (Devanagari matras/halant — **critical** for Hindi), `\p{N}` = numbers, `\p{P}` = punctuation (covers `।`, `॥`, `-`, `.`, `()` etc.), `\p{Z}` = Unicode separators. This covers entirely Devanagari titles, mixed Hindi-English, and English-only without script-specific hardcoding.

**User model fields are explicitly excluded from this pattern** — `name`, `username`, `email`, `mobile`, `post` on `users` stay Latin-only:
- Person names and designations are recorded in English (standard government nomenclature for this system).
- Allowing Unicode in `username`/`email` opens homoglyph attack surface (e.g. Cyrillic `а` vs Latin `a`) and normalisation mismatches between login entry and stored value.
- `username` keeps `[a-zA-Z0-9_]` — system identifiers must be ASCII.

Both the PHP Form Request regex AND the matching JS `pattern` in the Blade view must use `\p{M}` — the browser JS `u` flag has the same combining-mark gap as PCRE. Apply them in sync whenever a field is updated.

**Unicode-aware slug generation** — `Str::slug()` must NOT be used on user-supplied text. It pipes text through ICU transliteration, which turns `शुद्धिपत्र` into a mangled Latin approximation (`shathathhapatara`). All model slug helpers (`Document`, `RuleSet`, `Division`) use `static::makeSlug()` from the `HasUnicodeSlug` trait (`app/Models/Concerns/HasUnicodeSlug.php`) instead:

```php
protected static function makeSlug(string $text): string
{
    $slug = mb_strtolower($text);
    $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $slug);
    return trim($slug, '-');
}
```

This keeps Unicode letters + combining marks intact and collapses everything else (spaces, brackets, punctuation) to hyphens. Result: `fl-bottling-rules-2011-16th-amendment-शुद्धिपत्र`. Modern browsers display percent-decoded Devanagari in the address bar, so the URL reads naturally. Never add `Str::slug()` calls to model slug helpers.

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
- Admin-only routes are gated by **both** `middleware('is_admin')` on the route group AND `$user->isAdmin()` in each Form Request's `authorize()`. Defense in depth — never rely on Form Request alone for route-level access control.
- `IsAdmin` middleware (`app/Http/Middleware/IsAdmin.php`) is registered as the `is_admin` alias in `bootstrap/app.php`. It aborts 403 for any non-admin. Applied to the entire `admin.*` route group.
- Use `$request->user()?->isAdmin()` (nullable-safe) in `authorize()` — never assume the user is logged in inside a Form Request.
- Self-deletion must be blocked explicitly in controllers (see `UserManagementController@destroy` using `auth()->id()`).
- Fortify's public registration is **disabled** — accounts are admin-created only.
- **Profile self-edit** (`GET /profile/edit`, `PATCH /profile`) — any authenticated user may edit their own name, username, email, mobile, post, and password. Role, privileges, department, and section are read-only (admin-assigned). Validated by `UpdateProfileRequest` which scopes uniqueness checks to `auth()->user()->id` and has no role/privilege fields. The `admin.users.edit` / `admin.users.update` routes are strictly admin-only and must not be used for self-editing by non-admins.
- Sidebar avatar and name are clickable links: admins → `admin.users.edit` for their own record; non-admins → `profile.edit`.

### Rate limiting
- All auth mutation route groups carry `throttle:mutations` middleware (60/min/user).
- `POST /documents` additionally carries `throttle:uploads` (20/min/user); disk exhaustion is guarded by the 50 MB file size cap and the mutations limiter. Once the initial legacy-document bulk load is complete, reduce to 5–10/min.
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
- The file input in upload forms must have `name="file"`. For multi-file upload modals the JS loop builds `FormData` manually (`fd.append('file', item.file)`) because the file input lives in a different column from the `<form>` element — `new FormData(form)` would not capture it. Do not change this to `new FormData(form)` without moving the input inside the form.
- Always wrap the JS init block (the IIFE that attaches event listeners) in a `try/catch` so that a parse or runtime error during setup does not silently leave forms unprotected.
- Controllers that serve both AJAX and non-AJAX callers must use `$request->expectsJson()` to switch between `response()->json(...)` and `redirect(...)`.

### General
- Never log passwords, tokens, or full request bodies — always `$request->except(['password', 'password_confirmation'])`.
- **Activity logging** — `LogMutation` middleware (registered globally) records every authenticated POST/PATCH/DELETE with user ID, IP, user agent, route name, and HTTP status into `activity_logs`. The `Login` event listener records every successful login. Guests are never logged. `ActivityLog::record()` is non-fatal — logging failures are caught and written to Laravel's application log, never propagated to the user. The `activity_logs` table is append-only; no application route deletes or updates these rows.
- Sensitive config (DB credentials, mail passwords) belongs in `.env` only — never hardcoded.
- `.env.example` must have blank values for all secrets.

## Conventions

- Bridge any new Python dependency through a Composer/Laravel package where one exists (as with `markitdown`) rather than raw `Process::run()` calls, unless no package exists.
- Long-running or potentially slow operations (extraction, OCR) must be dispatched as queued jobs — never run synchronously in a request/controller, to avoid browser timeouts.
- When generating migrations, prefer updating the original migration file directly for schema-in-flux tables rather than creating alter migrations — migration files are the single source of truth for table shape.

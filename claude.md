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
| Web server | Apache (mod_php or php-fpm via mod_proxy_fcgi) — **no Nginx**. Live at `docsrepo.exciseup.in` via a vhost on `127.0.0.1:8080` + a named Cloudflare Tunnel (`pdf-pipeline-tunnel.service`); `pdf-pipeline-queue`/`queue2.service` run the queue workers. The old `pdf-pipeline-app.service` (`php artisan serve`) systemd --user unit is retired — Apache's system-level `apache2.service` owns the app process now, matching `excise-budget-tracker`'s setup (switched the same day, 2026-08-13). This app is served straight from this working directory: `git checkout`/`git pull` on the live checkout can 500 every page with `touch(): Utime failed` until `php artisan view:clear` runs — see DEPLOY.md's "Blade view-cache gotcha". |
| Frontend | Blade templates, Tailwind CSS v4 (Play CDN), Alpine.js (self-hosted, `public/vendor/alpinejs/`, `defer`) for client-side interactivity/polling, Parsedown (markdown render) — **no Node, no npm, no build step**. Livewire evaluated 2026-07-26 and deliberately rejected — see "Frontend interactivity: Alpine, not Livewire" below. |
| Text extraction | Python `markitdown` (Microsoft, MIT), via [`innobrain/markitdown`](https://github.com/innobraingmbh/markitdown) Laravel package (self-managed venv, `php artisan markitdown:install`) |
| Structure detection | [Docling](https://github.com/docling-project/docling) (IBM, Apache 2.0), own venv (`storage/app/private/ocr-engines/docling/`) — layout/table-structure model, runs automatically as Pass 0 of every "Convert to Markdown" click, after the quick text-layer pass (reordered M34). Detects headings and table cells with bounding boxes; stored as a compact sibling `.structure.json` and spliced into the rendered Markdown wherever the geometric heuristic missed a table (M33) or heading (M34) — see `STRUCTURE_RESEARCH.md`. |
| OCR | Selectable engine — Tesseract (Google/HP, `hin`+`eng`, default), EasyOCR (JaidedAI), PaddleOCR (Baidu), or Surya (VikParuchuri, open source) — invoked via `symfony/process`. Triggered either automatically (M34: when the text-layer pass looks unreadable, `RunOcrExtraction` is auto-dispatched with no click needed) or manually via "Run OCR-Based Extraction" (with an engine dropdown) from a human reviewer. See "Text Extraction & Markdown Conversion Pipeline" below and `config/ocr.php`. |
| Queue | Laravel **database** queue driver — deliberately no Redis, single-box local deployment |
| Disk | Single local filesystem disk (`public`); logical separation enforced by path convention, not multiple disks |
| Dev-only DB setup | [`subhanraj/laravel-db-provisioner`](https://github.com/SubhanRaj/laravel-db-provisioner) (`require-dev`) — `php artisan db:provision` generates a random per-project DB name/user/password rather than reusing a shared MariaDB admin account. Never used in production; see [DEPLOY.md](./DEPLOY.md#3-project-setup). |
| App monitoring | Custom `documents.pipeline.health` dashboard only (load/memory/CPU temp from `/proc`+`/sys`, queue counts, and native slow-query/exception log counts — see below). Laravel Pulse was tried 2026-07-25 and removed 2026-07-26 after repeated Livewire compatibility bugs; see the Pipeline / Server Health section. |

## PHP upload limits

PHP's defaults (2 MB upload, 8 MB POST) block real document uploads. Four directives must be raised. Three options, in order of preference for this project:

**Option A — `public/.htaccess`** (already in the repo, works immediately for Apache + mod_php, no restart needed)
```apache
<IfModule mod_php.c>
    php_value upload_max_filesize 300M
    php_value post_max_size       300M
    php_value max_execution_time  300
    php_value max_input_time      300
</IfModule>
```
Requires `AllowOverride All` (or `AllowOverride Options FileInfo`) in the Apache vhost/Directory block — otherwise `.htaccess` is silently ignored.

**Option B — `public/.user.ini`** (works for both mod_php and php-fpm, no Apache directive needed, ~5 min TTL)
```ini
upload_max_filesize = 300M
post_max_size       = 300M
max_execution_time  = 300
max_input_time      = 300
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
| `visibility` | string | `public` (default) \| `authenticated` (M84, 2026-08-22) — gates the section page and is a hard ceiling on every contained document's effective visibility, same pattern as Folder's `visibility` below |
| `timestamps` + `softDeletes` | | |

Unique constraint: `(department_id, wing, slug)`.

### `divisions`
| Column | Type | Notes |
|---|---|---|
| `section_id` | FK → sections | `restrictOnDelete` |
| `name` | string | Display name (free-form — e.g. "Pension Desk", "HRMS Cell") |
| `slug` | string | Auto-generated from name; unique per section |
| `description` | text nullable | Optional scope/function description (max 500 chars) |
| `visibility` | string | `public` (default) \| `authenticated` (M84, 2026-08-22) — same pattern as Section/Folder |
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
| `visibility` | string | `public` (default) \| `authenticated` — gates the folder page, and is a hard ceiling on every contained document's effective visibility regardless of the document's own flag (see "Document visibility" below) |
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
| `name` | string | Full name of the Act/Rule, the policy container's name (e.g. *UP Excise Policy* — no year), or a policy document's name (e.g. *Excise Policy 2025-26*) |
| `slug` | string | Auto-generated from name; unique per department |
| `description` | text nullable | Optional summary (max 500 chars) |
| `kind` | enum | `rules` (default) \| `policy` — discriminates Acts/Rules containers from Policy rows (containers AND policy documents are both `kind=policy`); see "Policy Taxonomy" below |
| `state` | string nullable | Only meaningful when `kind=policy` — e.g. `Uttar Pradesh`, `Odisha`; dropdown-controlled (`RuleSet::STATES`), with a sanitized free-text fallback for `other`. Copied from container to policy document at creation time |
| `policy_type` | string nullable | Only meaningful when `kind=policy` — dropdown-controlled (`RuleSet::POLICY_TYPES`: `excise_policy`, `cane_policy`, `sugar_policy`, `import_policy`, `export_policy`, `other`), same free-text fallback. Copied from container to policy document |
| `container_id` | FK → rule_sets nullable | `restrictOnDelete` — self-referencing. `null` = this row **is** a policy container (state + policy_type, created once); set = this row is a **policy document** underneath that container — "period" refers only to its timeframe (e.g. "2025-26"), never to the row itself. Never set for `kind=rules` |
| `effective_start_date` / `effective_end_date` | date nullable | Only meaningful on a **policy document** row — descriptive only; does **not** drive whether it is "current" (see `policy_status` below) |
| `policy_status` | enum | Only meaningful on a **policy document** row — `current` (default) \| `superseded` |
| `previous_policy_id` | FK → rule_sets nullable | `nullOnDelete` — self-referencing; set on the *new* policy document when it supersedes an older one under the same container |
| `metadata` | json nullable | Category, origin year, etc. |
| `timestamps` + `softDeletes` | | |

Unique constraint: `(department_id, slug)`. Slug generated via `RuleSet::uniqueSlugForDepartment($name, $departmentId)` — checks `withTrashed()` to avoid reusing slugs of soft-deleted rule sets. Composite index `(department_id, kind, state, policy_type, policy_status)` backs the supersession lookup in `PolicyDocumentController::store()`.

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

**Human-readable action labels (2026-07-26).** `Admin\ActivityLogController::ACTION_LABELS`/
`ACTION_COLORS` map raw route names (`action` column) to short phrases ("Login", "Upload
Document", "Convert to Markdown") and a Tailwind badge color — the same lookup-table-with-
fallback-to-raw-string pattern the `~/Projects` sibling apps use for their own audit logs
(checked directly before building this). **Whenever a new POST/PATCH/DELETE route is added,
add its label here too** — an unmapped action still works (falls back to the raw route name)
but defeats the point of this table. `LogMutation::SKIP_ROUTES` excludes routes that either
already get a dedicated, more accurate entry via an event listener (`otp.verify`, `logout` —
see below) or aren't a meaningful state change on their own (`otp.resend`, `login.attempt` —
a password check, not a mutation).
- **Login** is recorded via the `Illuminate\Auth\Events\Login` listener (`AppServiceProvider`),
  not `LogMutation` — this fires exactly once per real login regardless of which controller
  called `Auth::login()`, so the custom OTP-based `LoginController` needed no logging code of
  its own to get this right.
- **Logout** needed its own `Illuminate\Auth\Events\Logout` listener for a reason that isn't
  obvious: by the time `LogMutation`'s post-response `auth()->check()` runs on the logout route,
  the guard has already cleared the user, so it's always `false` there — `LogMutation` can
  never see logout as "an authenticated request." The `Logout` event fires *during*
  `Auth::logout()`, still carrying `$event->user` explicitly, which is why
  `ActivityLog::record()` takes an optional `$userId` override parameter (defaults to
  `auth()->id()`) — the only caller that ever needs it is this listener.

**Timezone note (2026-07-25, revised 2026-08-05):** `document_status_histories.created_at`, `activity_logs.created_at`, and `failed_jobs.failed_at` all use a MariaDB-level `useCurrent()` column default rather than Eloquent's own timestamp assignment (the first two have `$timestamps = false` on their models). MariaDB's `CURRENT_TIMESTAMP` evaluates using **whatever timezone the current DB session is set to**, while Eloquent's `datetime` cast reads the raw string back assuming `config('app.timezone')` — those two must always agree, or every diff/comparison against `now()` (elapsed-time timers, activity log times, pipeline "last activity") skews by the difference between them. The `'timezone'` entry on the `mariadb` connection in `config/database.php` pins the DB session to match. This was first fixed 2026-07-25 by hardcoding it to `'+00:00'`, correct at the time because `app.timezone` was `UTC` — then `APP_TIMEZONE` was changed to `Asia/Kolkata` in `.env` without updating this to match, silently reintroducing the exact same ~5.5h skew (caught 2026-08-05 via a document conversion's "Elapsed" timer reading 336 minutes instead of ~6). Fixed properly this time: `'timezone' => (new DateTime('now', new DateTimeZone(env('APP_TIMEZONE', 'UTC'))))->format('P')` derives the DB session timezone from `APP_TIMEZONE` directly, so the two can't drift out of sync again regardless of what `APP_TIMEZONE` is set to. Historical rows written under either mismatch remain skewed — no retroactive fix, same tradeoff as the original fix.

### `users`
Standard Laravel/Fortify users table extended with: `username` (unique), `mobile` (nullable, 10 digits, `+91`/`+91-` prefix stripped on save), `landline` (nullable, free-form STD+number e.g. `0522-223456`, max 20 chars), `post` (free-text supplementary posting, nullable, shown alongside `designation_id` — see `designations` below), `designation_id` (FK → designations, nullable, `nullOnDelete`, added M74), `role` (`system_admin` | `admin` | `operator` | `viewer`), `privileges` (JSON array of granular capability strings — see `User::PRIVILEGES` constant for the canonical whitelist), `uploads_require_approval` (boolean, default false — when true every document this user uploads goes to `pending_approval` regardless of context), `department_id` (FK → departments, nullable, `nullOnDelete`), `section_id` (FK → sections, nullable, `nullOnDelete`), `division_id` (FK → divisions, nullable, `nullOnDelete`). Public registration disabled — admin-created only. `User::isAdmin()` checks `role === 'system_admin'`; `User::isOrgAdmin()` checks `role === 'admin'`; `User::hasPrivilege($key)` returns true unconditionally for `isAdmin()`, and true for `isOrgAdmin()` on any privilege in `User::ORG_ADMIN_PRIVILEGES`.

**`role = system_admin` is reserved for the site-manager/IT-dev account(s) only (M74 convention,
split further post-M74 after the same misuse pattern recurred with real officer accounts).** It is
the only role that bypasses every privilege/scope check and reaches `/admin/users`,
`/admin/designations`, `/admin/activity-logs`, and `documents.pipeline.health` (all still gated by
the `IsAdmin` middleware, which calls `isAdmin()` — automatically system_admin-only). Real-world
seniority (Commissioner, ACS, Secretary, etc.) gets `role = admin` instead — an org-scoped tier
that auto-grants the full document-action bundle (`ORG_ADMIN_PRIVILEGES`: upload/edit/delete/
restore/verify/approve) via `hasPrivilege()`, but *never* bypasses scope — a `role=admin` user
still only acts within their own `department_id`/`section_id`/`division_id` (see `canUploadTo()`),
and never sees the site console. This exists because the original M74 fix (Designation presets)
kept under-granting privileges in practice — e.g. "Deputy Commissioner (P&E)" only ever granted
`documents.verify`, not `upload`/`edit` — which pushed real accounts back toward `role=system_admin`
as a shortcut the moment they hit a wall. `role = admin`/`operator`/`viewer` plus a `Designation`
(see below) still carries whatever `department.head`/`section.head`/`organization.head` privilege
(and hence scope) their post needs — `ORG_ADMIN_PRIVILEGES` only covers "can they act on documents
at all," never "which department/section."

### `designations` (M74, 2026-08-03)
A named, admin-managed preset mapping a real-world government post to a default scope + privilege
bundle, so creating a user account means picking "Additional Excise Commissioner" from a dropdown
instead of reverse-engineering which checkboxes it should imply. Columns: `id`, `department_id`
(FK → departments, nullable — null means generic/selectable under any department, non-null locks
it to that one department), `name`, `slug` (unique per `department_id`, mirrors the `rule_sets`
uniqueness pattern), `default_scope` (enum `global`|`department`|`section`|`division`|`none` —
informational, drives which fields the preset pre-fills, not itself enforced anywhere),
`default_privileges` (json, subset of `User::PRIVILEGES`), `sort_order`, timestamps + soft-deletes
(so a retired designation still resolves to a readable name for users who already hold it).

**Preset, not a synced rule.** Selecting a Designation on the user create/edit form fires
`applyDesignationPreset()` (JS, `resources/views/admin/users/create.blade.php` /
`edit.blade.php`) once: it sets Department (if the designation is department-locked) and
pre-checks the privilege checkboxes from that option's `data-privileges` JSON attribute. Nothing
is locked — the admin can immediately uncheck/adjust anything, and editing a Designation's
defaults later does **not** retroactively touch any user who already picked it. `User::PRIVILEGES`,
`uploadScope()`, `canUploadTo()`, etc. are completely unchanged — Designations are purely a preset
layer on top of the existing scope/privilege machinery, not a new authorization primitive.

`Designation` model (`app/Models/Designation.php`) — `belongsTo(Department)`, `hasMany(User)`,
`appliesToDepartment(?int $departmentId)` helper (`department_id === null || department_id ===
$departmentId`) used to filter the dropdown once a Department is chosen. `DesignationSeeder`
ships 16 generic posts (Officer, Section Officer, HoD, Chief Secretary … Accounting Officer) plus
department-specific variants for Excise (Excise Commissioner, Additional Excise Commissioner,
Deputy Commissioner (P&E), Deputy Excise Commissioner (P)) and Sugarcane & Sugar Industries (Cane
Commissioner, Additional Cane Commissioner) — the CRUD screen at `/admin/designations` is how more
get added later, no code change needed. See [DESIGNATIONS_PLAN.md](DESIGNATIONS_PLAN.md) for the
full design rationale (the incident that motivated it, decisions confirmed before building).

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

## What's built (as of 2026-07-15, updated)

### Modules / controllers

| Module | Controller | Notes |
|---|---|---|
| Dashboard | `FrontendController` | Public landing page with document stats; auth-aware recent feed; `pending_approval` count shown to admins/approvers |
| Documents | `DocumentController` | Full CRUD; AJAX-only store (handles section, division, rule-set, and folder uploads); PDF stream; hierarchical URLs; slug generation; soft-delete with reason; trash/restore/force-delete; `shouldRequireApproval()` check on every upload |
| Departments | `DepartmentController` | Full CRUD; slug-based route model binding; show page is 3 summary cards (Sections/Rules & Regulations/Policies, each with a count) linking out to each category's own index page — no longer renders the full lists inline |
| Sections | `SectionController` | Nested under departments; wing-aware; `index()` is the full sections list page (linked from the department show cards); show page is the file browser + multi-file upload modal + folder cards; `requires_approval` toggle on edit page |
| Rule Sets | `RuleSetController` | Full CRUD; admin-only mutations; `index()` is the full list page (linked from the department show cards); multi-file upload modal on show page pre-selects `rule_amendment` type; `requires_approval` toggle on edit page |
| **Policy (container)** | **`RuleSetController` (same class, `kind='policy'`, `container_id=null`)** | **Created once per department+state+policy_type. `show()` lists its policy documents (`rule_sets.policy_container` view) instead of documents. Mutations gated to admin or the owning department's `department.head` via `User::canManagePolicyForDepartment()`/`canManagePolicy()`; see [POLICY_PERIODS.md](POLICY_PERIODS.md)** |
| **Policy browsing (2026-07-26)** | **`RuleSetController::policyState()`/`policyOtherStates()`** | **`/policy` is a 2-card landing (Uttar Pradesh / Other States), `/policy/state/{state}` is one page shared by both (filtered by state — no UP-specific code path), `/policy/other-states` lists every `RuleSet::STATES` entry as a card, split into two labeled
sections (States / Union Territories via `RuleSet::UNION_TERRITORIES`, see POLICY_PERIODS.md §6).
`{state}` is a slug (`RuleSet::stateSlug()`/`stateFromSlug()`), and both new routes are registered before `/policy/{rule_set}` — route order matters, see POLICY_PERIODS.md §4. State cards get a real per-state shape icon, client-side via `@svg-maps/india` over jsDelivr (CDN, CC-BY-4.0, pinned version) — `resources/views/rule_sets/_state_icon_loader.blade.php`, requires `cdn.jsdelivr.net` in `connect-src` (see SecurityHeaders CSP note), see POLICY_PERIODS.md §5. Every policy page shares one breadcrumb chain via `RuleSet::policyBreadcrumb()` (`Policies > {state}` prefix, reused by container/policy document create/edit/show and the document page) — see POLICY_PERIODS.md §7** |
| **Policy (document)** | **`PolicyDocumentController` (nested under a container; URL/route-name segment stays `/periods/` — that names the timeframe scoping, not the entity, see POLICY_PERIODS.md §8)** | **One per year/cycle (e.g. "Excise Policy 2025-26"); holds its own root document + amendments exactly like a rule set does, via the shared `ListsRuleSetDocuments` trait; year-over-year supersession (`policy_status`, `previous_policy_id`) scoped to `container_id`; see [POLICY_PERIODS.md](POLICY_PERIODS.md)** |
| Divisions | `DivisionController` | Full CRUD under sections; admin-only mutations; show page is division hub with multi-file upload modal, amendment hierarchy, and folder cards; `requires_approval` toggle on edit page |
| **Folders** | **`FolderController`** | **Full CRUD under sections (and optionally divisions); show page is a hub with upload modal + document list (amendment chain supported via `parent_id`); `requires_approval` toggle; archive cascades to all contained docs; visibility gate on folder page** |
| Search | `SearchController` | Public `GET /search?q=`; LIKE-based search across document titles, section names, rule set names/descriptions, folder names/descriptions; guests see `visibility = 'public'` docs and folders only; results capped at 50 docs + 20 sections + 20 rule sets + 20 folders; `->publishable()` scope hides pending/rejected |
| User management | `Admin\UserManagementController` | Admin-only CRUD + self-edit profile routes; `IsAdmin` middleware gates all `admin.*` routes; `editProfile`/`updateProfile` methods serve the `/profile` self-edit routes for non-admins; `division_id`, `uploads_require_approval` fields added; `documents.approve` privilege checkbox added; `designation_id` persisted alongside `post` (M74) |
| **Designations (M74)** | **`Admin\DesignationController`** | **Admin-only CRUD at `/admin/designations`; named presets (default scope + privilege bundle) selectable on the user create/edit form — see "`designations`" table notes above** |
| Archive | `DocumentController` (existing methods) | Soft-deleted documents accessible to all authenticated users; "Archive" in all UI; counts split active vs archived; restore gated by `documents.restore` privilege; permanent delete gated by `documents.force-delete` + requires reason + letter PDF upload — letter stored on the **private `local` disk** (`storage/app/private/archive_letters/`), never on public disk; letter path stored in `document_status_histories.metadata` |
| Activity Log | `Admin\ActivityLogController` | Admin-only audit view at `GET /admin/activity-logs`; filterable by user, action, and IP; paginates the `activity_logs` table (50/page); `LogMutation` middleware records all authenticated POST/PATCH/DELETE requests (except a short skip-list — see "`activity_logs`" table notes); `Login`/`Logout` event listeners record every session start/end with IP, UA, and guard; raw route names shown as human-readable labels via `ACTION_LABELS` |
| Approval Queue | `ApprovalController` | Maker-checker workflow at `GET /approvals`; three tabs (Pending / Rejected / My Submissions); approve, reject, reclassify, resubmit actions; scope-aware (approvers see only their org boundary); PDF preview via slide-over drawer; bulk approve/reject |
| **Text Extraction / OCR / Structure** | **`DocumentController` (`convert`, `convertOcr`, `conversionStatus`, `structureJson`, `updateMarkdown`, `discardMarkdown`)** | **Button-triggered Markdown conversion (`ConvertDocumentToMarkdown` job, now also runs a Docling structure-detection Pass 0) + on-demand OCR re-extraction (`RunOcrExtraction` job); Compare & Verify split-pane modal on `documents/show`; see dedicated section below** |
| **Bulk Upload** | **`DocumentController@bulkUploadForm`** | **`GET /documents/bulk-upload` — single page to upload multiple files to any department/section/division/folder/rule-set the user is scoped to, with optional auto-convert per file** |
| **New Conversion (standalone, M70)** | **`QuickConversionController`** | **`GET /conversions/new` — drop one file, no destination picked upfront; auto-converts via the same `PdfConversionEngine`; review page offers Save to… (promotes to a real `Document`), Download Markdown, or Discard; ephemeral `QuickConversion` row auto-expires (`PruneQuickConversion`, delayed job, 48h default) if left untouched — see the Text Extraction section below** |
| **Conversion Pipeline monitor** | **`DocumentController@pipeline`** | **`GET /documents/pipeline` — table of every document not yet verified/archived (`uploaded`/`processing`/`ocr_pending`/`review`/`failed`), status tabs, live polling, per-row and bulk-select Convert/Retry (updates rows in place, no page reload)** |

### Text Extraction & Markdown Conversion Pipeline

**Implemented 2026-07-13, pass order + OCR auto-trigger changed 2026-07-17 (M34).** Converts a
document's original PDF into Markdown. Conversion itself is still human-triggered (a button
click, never automatic on upload); OCR *within* a conversion is no longer purely a manual
fallback — since M34, the text-layer pass runs first and, if it flags the result unreadable, OCR
is auto-dispatched right there without waiting for a reviewer to click anything. A reviewer can
still trigger OCR manually too (e.g. to retry with a different engine).

**Trigger — button, not automatic on upload.** Conversion never auto-dispatches from `store()` or
from approval. A **Convert to Markdown** button on `documents/show` (and a per-row **Convert**/**Retry**
button on the Pipeline monitor, and an **auto-convert** checkbox on the Bulk Upload page) calls
`POST /documents/{id}/convert`, which dispatches `App\Jobs\ConvertDocumentToMarkdown`
(`ShouldQueue`, `$timeout = 1200` — bumped from 900 to give the Docling structure pass below
headroom). `convert()`, `convertOcr()`, `revertOcr()`, `discardMarkdown()`, and `structureJson()` —
everything that produces or refines the Markdown *draft* — are gated by the shared private
`canConvertDocument()` helper (controller-only check, not the stricter `is_admin` route-group
middleware, which is reserved for `admin.*` user management routes): `system_admin`
unconditionally, a policy document's `department.head`, or (M79, then loosened M82, 2026-08-19) any
user holding **`documents.upload`** scoped via `canUploadTo()` against the document's own
division/section/rule-set context. See "View-scoping" above and `SECURITY.md` Pass 7 H-07 for why
the scoped branch was added — before it, only a true admin could ever convert a normal (non-policy)
document, which is what pushed real officer accounts toward `role=system_admin` as a workaround.

**`documents.verify` stays the gate for the actual approval step, not conversion (M82, 2026-08-19).**
Originally (M79) the scoped branch above required `documents.verify`, on the theory that
convert/verify were one lifecycle. Reported live: an upload-only account (no `documents.verify`)
couldn't even run Convert on their own upload. Split into two private helpers —
`canManageDocument()` (unchanged: `documents.verify`, used only by `verify()`, the "Accept as-is"
quick-approve action) and `canConvertDocument()` (`documents.upload`, used by the five draft-
producing actions above). `UpdateDocumentMarkdownRequest`'s Save & Verify keeps its own
`documents.verify` check, untouched. Net effect: anyone who can upload into a section can also run
that upload through OCR/Markdown conversion; turning the draft into a `verified` document still
needs the separate privilege. `documents/show.blade.php` mirrors this as two variables,
`$canConvertDoc` (Convert/Retry button) and `$canManageDoc` (Edit/Delete buttons, Compare & Verify
modal) — kept in sync with the controller manually, same caveat as the M81 note below.

**Fixed 2026-07-16 — status wasn't persisted before dispatch.** Both `convert()` and
`convertOcr()` used to only fake `status: 'processing'`/`'ocr_pending'` in their JSON response,
without saving it to the document — invisible when jobs ran within a second or two, but with the
single serial queue worker backed up behind a slow job (a 14-minute PaddleOCR run on a 54-page
scan), a newly-queued document's `status` column stayed at its old value (`uploaded`/`review`)
for the whole wait. The polling JS (`startConversionPolling()`) treats any status other than
`processing`/`ocr_pending` as "done" and reloads the page — which then showed "not yet converted"
since nothing had actually run yet, looking exactly like conversion had silently failed. Both
endpoints now persist the real status (+ a `DocumentStatusHistory` entry) before dispatching.
`conversionStatus()` also now returns `queued_behind_other_job` (checks the `jobs` table for
another `reserved_at`'d job that isn't this document's), so the UI can show "waiting in queue —
another document is currently processing" instead of looking stuck.

**Fixed 2026-07-26 — elapsed timer reset to 0:00 on every page refresh.** `startConversionPolling()`
always seeded its elapsed-time counter from `Date.now()` — correct right after clicking Convert
(nothing has elapsed yet), wrong if the user reloads the page mid-conversion, since it then reads
as the job having silently restarted. Fixed by seeding from the real conversion-start time instead:
`show.blade.php` reads the latest `DocumentStatusHistory` row's `created_at` for the document into
a `data-started-at` attribute on `#markdown-card`, and `startConversionPolling()` uses that when
present, falling back to `Date.now()` only for the two cases where "now" is actually correct — the
AJAX-triggered convert-button click and the OCR-compare panel, both of which start the timer at
the moment the user just initiated the action.

**Pass 0 — Docling structure detection, runs automatically before text extraction:**
Every "Convert to Markdown" click also runs Docling (`storage/app/private/ocr-engines/docling/`
venv) against the original PDF via its own CLI (`docling convert --to json`), before the
text-layer pass below. Always uses `config('docling.default_ocr_engine')` (Tesseract) as the
backend Docling reads scanned regions with internally — no UI control for this; an earlier
version exposed a "Structure OCR engine" dropdown next to the Convert button, but that broke the
established pattern of never surfacing an engine choice before there's a result to react to (the
main OCR-engine dropdown in Compare & Verify only appears *after* conversion, once quality is
known) — removed after review, see `config/docling.php` if the engine ever needs changing.
Docling's own OCR text for headings/body text is discarded — only the detected region/table
*shape* (with bounding boxes) is kept there; table *cell* text is the exception, see below.
Docling's huge raw export (confirmed 100MB+ per document during evaluation) is trimmed down to
headings + table cells and written as a compact sibling file, `{slug}.structure.json`, on the
same `public` disk as the PDF/Markdown — never in the database. This is additive and non-fatal:
any Docling failure is logged and the rest of the pipeline proceeds unaffected.

The structure map is shown to reviewers inside the Compare & Verify modal (a collapsible
"Structure detected: N headings, M tables" panel, headings as a list and tables rendered via
Grid.js — see `STRUCTURE_RESEARCH.md`'s "Review UI changes" section) and is **also spliced into
the rendered Markdown itself** for both tables and headings: `pdf_structure_extractor.py`'s
`classify_and_render()` takes an optional Docling table list and heading list and, on any page
where its own geometric heuristic found none of its own, inserts Docling's already-recognized
text there instead of leaving that page's table flattened into a paragraph or its heading
demoted to plain text. Table splice is M33, heading splice is M34 — see `STRUCTURE_RESEARCH.md`
for the known limitation (duplicate garbled table text can still appear on some OCR-derived
documents; full de-duplication needs bbox coordinate reconciliation between Docling and the OCR
engine, not done this round). `discardMarkdown()` deletes the `.structure.json` sibling alongside
the Markdown draft it was produced with, same lifecycle as the Markdown itself.

**Fixed 2026-07-17 (M34) — pass order + auto-OCR-trigger.** The text-layer pass (Pass 1) now runs
*before* Docling's structure pass (Pass 0), not after — it's the fast half of the job, so the
quality/legacy-font check result is known before spending Docling's per-page time. Docling still
always runs afterward (structure detection is useful either way), and the text is re-rendered
once `structure.json` exists so table/heading splicing applies. The practical payoff: if the
quality check says OCR is needed, `RunOcrExtraction` is now dispatched automatically at the end
of the job (`config('ocr.default')` engine), and status goes straight to `ocr_pending` instead of
parking at `review` waiting for a reviewer to notice `needs_ocr_review` and click "Run OCR"
themselves. A reviewer can still manually re-run OCR with a different engine afterward.

**Fixed 2026-07-24 (M37) — legacy-font tables were still broken after M32.** M32's legacy-font
detection (below) only ever protected body text. Docling's own structure pass
(`runDoclingStructureAnalysis()`) trusts the PDF's native text layer for any region it doesn't
detect as a scanned bitmap — a Kruti Dev PDF's text is technically "selectable" (not an image), so
Docling never OCR'd those regions either, and spliced table cells came out with the same garbled
codepoints the legacy-font check was supposed to catch. `RunOcrExtraction` then reused that same
unfixed `structure.json`, so even after full OCR, tables in the final Markdown stayed garbage
while paragraphs were correct. Fix: `runDoclingStructureAnalysis()` now takes `bool $forceOcr`,
passed `true` whenever `$legacyFont !== null` (already known before Docling runs, from Pass 1's
output) — Docling then gets `--force-ocr`, re-reading every region including tables from rendered
pixels instead of the broken text layer, scoped to only this one case (never a blanket
`--force-ocr`, which STRUCTURE_RESEARCH.md's Finding 3 confirms is impractical at real page
counts). Timeout for this path bumped to 900s (was 600s). See `STRUCTURE_RESEARCH.md`'s M37 entry
for the before/after verification against a real document.

**`ConvertDocumentToMarkdown` job — text-layer pass, now runs first (see M34 above):**
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
2. Quality-checks the output via `isGoodQuality()` plus a separate legacy-font check, three
   independent failure signals, all meaning "don't trust this text layer":
   - `(cid:\d+)` glyph-ID fallback tokens (pdfminer couldn't resolve a character to Unicode
     because the embedded font has no usable ToUnicode CMap). More than 5 occurrences ⇒ bad.
     Char-count alone doesn't catch this — a page full of `(cid:547)` garbage still has
     plenty of characters.
   - Near-empty text relative to page count (`char_count < page_count * 40`) — a real
     scanned/photographed page with no text layer at all.
   - **Legacy non-Unicode font detected by name** (Kruti Dev, Chanakya, DevLys, Shusha,
     Walkman, etc. — `LEGACY_HINDI_FONT_RE` in `pdf_structure_extractor.py`, checked against
     pdfminer's per-character `fontname`). These fonts remap Devanagari glyphs into the
     Latin/ASCII range with no real CMap, so pdfminer extracts *readable-looking but wrong*
     text (`Hkkjr` instead of `भारत`) — neither the cid-token nor char-count check catches
     this, since the output has plenty of real-looking characters. Found during the Docling
     evaluation against a real UP policy document — see `STRUCTURE_RESEARCH.md`. Detected
     via a sentinel marker (`<!-- LEGACY_FONT_DETECTED:{fontname} -->`) prepended to the
     script's stdout output and stripped by `ConvertDocumentToMarkdown` before saving —
     deliberately not a character-remapping table, which risks silently producing
     subtly-wrong text in a legal document; flagging for human review matches this
     pipeline's existing quality philosophy.
3. Writes the Markdown regardless of quality and sets `metadata.needs_ocr_review = true/false`.
   Status goes to `review` if quality is good, or straight to `ocr_pending` (with
   `RunOcrExtraction` auto-dispatched, see M34 above) if not — either way the bad text-layer
   result is still saved and visible with a warning, never silently discarded.

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

**Compare & Verify modal (`documents/show`)** — full-screen (fills the viewport; was a centered
`min(1400px, 96vw)` box until 2026-07-16 — there's enough on screen now between the PDF, the
Markdown, and the structure panel below to earn it) split-pane review UI: original PDF (left) vs.
editable raw Markdown (right, `<textarea>`). Key behaviors:
- **Structure panel** — a collapsible "Structure detected: N headings, M tables" strip at the
  top of the modal (above the OCR-quality warning), shown whenever `metadata.structure_analyzed`
  is true. Fetches the compact `.structure.json` via `GET /documents/{id}/structure` on first
  expand and renders headings as a list, tables via Grid.js (CDN). This used to be a page-level
  banner outside the modal with a raw-JSON link — moved inside the modal (2026-07-16) since
  reviewers need it in the same place they decide Markdown-vs-OCR, not on a separate surface; see
  `STRUCTURE_RESEARCH.md`.
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

Convert/Retry (single-row and bulk, both admin-gated the same as `convert()` itself) update the
row in place instead of reloading the page (2026-08-13) — clicking Convert used to call
`window.location.reload()` on success, which resets scroll position to the top of the page, a
real problem on a long pipeline list where you'd have to scroll back down after every click.
`markRowConverting()` now swaps the row's badge to "Processing" and clears its action cell
directly; the poll loop re-queries `tr[data-poll="1"]` fresh on every 5s tick (rather than a
one-time `querySelectorAll` snapshot at page load) so a row marked converting after the fact is
picked up without any page reload. Bulk select — a `select-all` checkbox in the header plus one
per convertible row (`uploaded`/`failed` only, admin-only column) — feeds a "Convert Selected"
bar that fires the same `POST /documents/{id}/convert` sequentially per selected document (same
pattern as the Approval Queue's bulk approve/reject), useful right after a multi-file bulk
upload where auto-convert wasn't used or failed for some files.

**Pipeline/server health check (`GET /documents/pipeline/health`, 2026-07-25)** —
`DocumentController::pipelineHealth()`, same `auth`+`throttle:reads` gate as the monitor above.
Content-negotiated on one URL: a browser gets an HTML dashboard
(`documents/pipeline-health.blade.php`, auto-refreshes via `<meta http-equiv="refresh" content="15">`,
color-coded load/memory/temp thresholds), a script/monitor gets JSON (`Accept: application/json`,
or `?format=json` for plain `curl` — its default `Accept: */*` doesn't trigger Laravel's own
`wantsJson()`). `?format=html` forces the dashboard even with a JSON-ish Accept header. Meant to be
checked remotely (e.g. through a tunnel) without SSHing in. Returns `pending_jobs`/`failed_jobs`
(from the `jobs`/`failed_jobs` tables), per-status document counts,
`last_job_activity` (most recent `DocumentStatusHistory.created_at` — every job status transition
writes one, so a genuinely stalled worker shows up as this going stale) with `status: 'stalled'`
when jobs are queued but nothing's moved in 15+ minutes, and a `server` block (`load_avg_*` via
`sys_getloadavg()`, memory from `/proc/meminfo`, `cpu_temp_c` from
`/sys/class/thermal/thermal_zone*/temp`, `cpu_count` via `nproc`) — all read directly from
`/proc`/`/sys`, no monitoring software installed. Chosen over installing Webmin (a full root-level
admin panel — much bigger attack surface than "check load and queue status" needs, especially
exposed through a tunnel) precisely so there's no new exposed service.

**Laravel Pulse — installed 2026-07-25, removed 2026-07-26.** Installed for app performance
visibility (slow queries, exceptions, cache hit rate, per-job/per-request timing), gated behind
the same Fortify admin session as the rest of the app. Spent most of its life throwing errors
instead of showing data: first a CSP/Alpine `unsafe-eval` incompatibility (fixable, but only by
loosening the CSP on `/pulse` + Livewire's asset routes), then a genuine Livewire v4.3.3 bug —
`unserialize()` "incomplete object" errors on `Illuminate\Support\Collection`/`stdClass` across
every Pulse card, on reload or theme switch — which needed pinning Livewire down to `^3.6.4` to
fix. That second fix worked, but at that point Pulse (plus Livewire, a dependency this app had no
other use for) was two non-trivial compatibility patches deep for a monitoring tool, on an app
with exactly one server and one queue worker to monitor. Removed entirely: `composer remove
laravel/pulse livewire/livewire --with-all-dependencies`, dropped `config/pulse.php`,
`config/livewire.php`, its migration (rolled back first), `resources/views/vendor/pulse/`, the
`pdf-pipeline-pulse.service` systemd unit, the `viewPulse` Gate, the sidebar link, and the
`unsafe-eval` CSP carve-out in `SecurityHeaders` (back to a plain, uniform CSP app-wide).

Of Pulse's cards, only two were ever actually useful for this app — Exceptions and SlowQueries.
Both are now native, dependency-free additions to the Pipeline Health page instead of a separate
dashboard: `AppServiceProvider::configureSlowQueryLogging()` registers
`DB::whenQueryingForLongerThan(500, ...)` (built into Laravel since 8.x, no package needed) to log
a `SLOW_QUERY` warning whenever a request/job's total query time crosses 500ms;
`DocumentController::recentLogSignals()` tails the last 2MB of `storage/logs/laravel.log` and
counts `.ERROR:` and `SLOW_QUERY` lines timestamped in the last hour. Shown as an "App signals"
card on `documents.pipeline.health`, same traffic-light coloring as the existing server-vitals
cards. Servers/Queues/Cache/Usage/SlowJobs/SlowRequests weren't worth keeping — server vitals and
queue counts were already covered by the existing health page, and the rest (cache hit rate,
per-user request timing, outgoing-request timing) don't apply to a single-server, admin-only,
no-outgoing-HTTP app like this one.

**`PdfConversionEngine` service extraction + standalone "New Conversion" flow (M70, 2026-08-02).**
`ConvertDocumentToMarkdown`/`RunOcrExtraction`'s actual conversion logic
(`tryStructuredExtract`, `runDoclingStructureAnalysis`, `isGoodQuality`, `countPages`, `runOcr`)
was extracted verbatim into `App\Services\PdfConversionEngine` — parameters became explicit
paths/ids instead of `$document->original_pdf_path`/`$document->id`, no behavior change. Both
jobs now just orchestrate (status transitions, `DocumentStatusHistory`) and delegate the actual
work to the service. This unblocked a second, independent upload path: **"New Conversion"**
(`App\Models\QuickConversion`, `quick_conversions` table) — drop a single file, get Markdown, with
no destination (Section/Folder/Rule Set/Policy) picked upfront, unlike the existing
`documents.bulk-upload` flow where a destination is required before a file can even be added.
`QuickConversionController` (routes under `conversions.*`, `GET /conversions/new` to start) drives
its own pair of jobs (`ConvertQuickConversionToMarkdown`, `RunQuickConversionOcrExtraction` —
same status flow as the Document jobs, calling the same `PdfConversionEngine`) and a delayed
`PruneQuickConversion::dispatch($id)->delay($expiresAt)` job (default 48h,
`QUICK_CONVERSION_TTL_HOURS` env) that deletes the row + files if nothing happened to them by
then — no scheduler/cron involved, just one delayed job on the existing single serial queue
worker. From the review page (`quick_conversions/show.blade.php`, same PDF/Markdown compare UI
and Edit/Preview toggle as `documents/show`'s Compare & Verify modal) the user picks one of:
**Save to…** (`POST /conversions/{id}/place`, `QuickConversionController::place()` — moves the
already-converted files into the resolved vault dir and creates a real, permanent `Document` row,
same `Document::uniqueSlugFor*()`/vault-path logic as `DocumentController::store()`, then deletes
the `QuickConversion` row), **Download Markdown** (no vault placement at all — for someone who
just wants the converted file), or **Discard** (immediate delete). `ResolvesUploadDestination`
(`app/Http/Requests/Concerns/`) — the destination-field validation + `canUploadTo`/`canManagePolicy`
authorization previously inlined in `StoreDocumentRequest` — was extracted into a trait so
`PlaceQuickConversionRequest` reuses the exact same rules rather than duplicating them.
`documents.bulk-upload` is untouched and still the right flow for "I already know where this
goes, and I have a batch of files." See `NEW_CONVERSION_PLAN.md` for the full design writeup.

### Frontend interactivity: Alpine, not Livewire (2026-07-26)

Recurring complaint: several pages needed a manual refresh to show anything new — most visibly,
the OCR elapsed-timer on `documents/show.blade.php` restarted from `0:00` every time the page was
reloaded mid-conversion, even though the job had actually been running for minutes, and
`documents.pipeline.health` used `<meta http-equiv="refresh" content="15">` (a full page reload
every 15s, which reads as the whole page "rebooting" rather than updating). This prompted
evaluating whether to adopt the TALL stack (Tailwind, **Alpine**, **Livewire**, Laravel).

**Livewire — evaluated and rejected.** This app already tried Livewire once, as Pulse's dependency
(see above), and hit two real compatibility bugs in the few days it was installed — a CSP/Alpine
`unsafe-eval` conflict and a v4.3.3 `unserialize()` bug requiring a downgrade pin. Livewire also
solves a different problem than what was actually broken here: every interaction becomes a
server round-trip with component-state serialization/hydration, which is real architectural
weight for what turned out to be plain bugs (a client-side timer seeded from the wrong value, a
`<meta refresh>` instead of a targeted fetch) — not a case where reactivity itself was missing.

**Alpine.js — adopted.** Self-hosted (`public/vendor/alpinejs/alpine.min.js`, currently pinned to
3.14.9 — check this note before upgrading in place), `<script defer>` include in
`resources/views/components/head.blade.php`, same reasoning as Tabler Icons (avoid a third-party
network dependency on a flaky/restrictive connection). No build step, no server round-trips — it
only wires already-existing `fetch()` calls to reactive Blade-native markup (`x-data`, `x-show`,
`x-text`, `:class`) instead of hand-rolled `document.getElementById`/`addEventListener` code.

**CSP note:** Alpine's directive expressions evaluate via `new Function()` internally, which
requires `'unsafe-eval'` in `script-src` — without it Alpine's own script loads fine but every
reactive binding is silently inert (this was hit for real: the share dropdown got stuck visible,
unaffected by clicks, because CSP was blocking the `x-show` evaluation, not because of any cache
or hover bug). Added to `SecurityHeaders`'s CSP alongside the existing `'unsafe-inline'` grant
(needed for Tailwind's Play CDN) — self-hosting the script doesn't change this requirement, since
it's about what the code inside the file is allowed to *do* at runtime, not where the file was
served from. The CSP-free alternative is Alpine's separate `@alpinejs/csp` build, which avoids
`new Function()` entirely but requires every `x-data` to be a pre-registered named
`Alpine.data()` component with more restricted expression syntax — a real rewrite, not a
drop-in swap. Deliberately not adopted; revisit only if this app's CSP posture needs to tighten
further.

Used so far:
- **`documents.pipeline.health`** — rewritten from `<meta http-equiv="refresh">` to an Alpine
  component (`pipelineHealthState()`) that polls the page's own `?format=json` endpoint every 15s
  and patches the DOM in place; the page still server-renders full initial values (works even
  before Alpine loads), Alpine just keeps them current afterward.
- **`documents/show.blade.php` share dropdown** — `x-data="{ shareOpen, copied }"` with
  `@click.outside`/`@keydown.escape.window`, replacing ~30 lines of manual toggle/outside-click/
  Escape-key/clipboard-icon-swap JS.
- The OCR elapsed-timer reset itself was a plain one-line JS bug, not an Alpine job: it needed the
  real conversion-start timestamp (from the latest `DocumentStatusHistory` row) rendered into a
  `data-started-at` attribute and read by the existing `startConversionPolling()` function, instead
  of Alpine — fixed directly, see "OCR elapsed timer" note near the conversion pipeline section.

Global nav chrome (dark mode, mobile sidebar drawer, sidebar collapse, nav tooltips in
`layout.blade.php`) was **left as plain JS** — it already works correctly, touches every page in
the app, and converting it wouldn't fix anything that was actually reported broken; rewriting
working, low-risk code for its own sake isn't worth the regression surface. Reach for Alpine on
new interactive components or when touching code with this same class of bug (state that should
be seeded from the server but isn't, or a full-page reload standing in for a targeted update) —
don't retrofit it onto working code.

**Toolchain** (installed once; see `DEPLOY.md` for full reproducible setup):
```bash
composer require innobrain/markitdown erusev/parsedown
php artisan markitdown:install        # provisions its own venv
brew install tesseract tesseract-lang poppler   # hin+eng traineddata, pdftoppm/pdfinfo
```

### Route map

Routes have **no global prefix** — resources sit at the root. All models use `getRouteKeyName()` returning `'slug'` (`User` returns `'username'` instead — see "Slug-based routing" below) — IDs never appear in URLs.

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
| GET | `/documents/{document}/og-image.jpg` | `documents.og-image` | Public |
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
| GET | `/documents/{level}/{dept}/policy/{rule_set}/{doc}` | `documents.policy.show` | Public* |
| PATCH | `/documents/{level}/{dept}/policy/{rule_set}/{doc}` | `documents.policy.update` | Admin or department.head |
| DELETE | `/documents/{level}/{dept}/policy/{rule_set}/{doc}` | `documents.policy.destroy` | Admin or department.head |
| GET | `/documents/{level}/{dept}/policy/{rule_set}/{doc}/pdf` | `documents.policy.pdf` | Public* |
| GET | `/documents/{level}/{dept}/policy/{rule_set}/{doc}/review` | `documents.policy.edit` | Admin or department.head |
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
| GET | `/documents/pipeline/health` | `documents.pipeline.health` | Auth |
| POST | `/documents/{id}/convert` | `documents.convert` | Admin (controller check) |
| POST | `/documents/{id}/convert-ocr` | `documents.convert-ocr` | Admin (controller check) |
| POST | `/documents/{id}/revert-ocr` | `documents.revert-ocr` | Admin (controller check) |
| GET | `/documents/{id}/convert-status` | `documents.convert-status` | Auth (unscoped — see note) |
| GET | `/documents/{id}/structure` | `documents.structure` | Admin/policy-manager/upload-scoped (`canConvertDocument()`, M82) |
| PATCH | `/documents/{id}/markdown` | `documents.markdown.update` | Admin (Form Request check) |
| DELETE | `/documents/{id}/markdown` | `documents.markdown.discard` | Admin (controller check) |

**New Conversion (standalone, M70)** — owner-only, checked inline in `QuickConversionController`
(`$quickConversion->user_id === auth()->id() || auth()->user()->isAdmin()`), no policy class.

| Method | URI | Route name | Auth |
|---|---|---|---|
| GET | `/conversions/new` | `conversions.create` | Auth (upload scope) |
| POST | `/conversions` | `conversions.store` | Auth (upload scope) |
| GET | `/conversions/{quickConversion}` | `conversions.show` | Auth + owner |
| GET | `/conversions/{quickConversion}/status` | `conversions.status` | Auth + owner |
| GET | `/conversions/{quickConversion}/download` | `conversions.download` | Auth + owner |
| POST | `/conversions/{quickConversion}/ocr` | `conversions.ocr` | Auth + owner |
| PATCH | `/conversions/{quickConversion}` | `conversions.update` | Auth + owner |
| POST | `/conversions/{quickConversion}/place` | `conversions.place` | Auth + owner + destination authorization |
| DELETE | `/conversions/{quickConversion}` | `conversions.destroy` | Auth + owner |

*Public routes 403 for guests on `Document::isPubliclyVisible() === false` — a document's own `visibility` plus, if it has a `folder_id`, its containing folder's `visibility` too (see "Document visibility" below; folder doc routes weren't actually checking the folder's visibility here until 2026-08-17, despite this doc having claimed they did).

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
| GET | `/departments/{level}/{dept}/rules` | `departments.rules.index` | Public |
| POST | `/departments/{level}/{dept}/rules` | `departments.rules.store` | Auth |
| GET | `/departments/{level}/{dept}/rules/{rule_set}` | `departments.rules.show` | Public |
| PATCH | `/departments/{level}/{dept}/rules/{rule_set}` | `departments.rules.update` | Auth |
| DELETE | `/departments/{level}/{dept}/rules/{rule_set}` | `departments.rules.destroy` | Auth |
| GET | `/departments/{level}/{dept}/policy` | `departments.policy.index` | Public |
| GET | `/departments/{level}/{dept}/policy/create` | `departments.policy.create` | Auth |
| POST | `/departments/{level}/{dept}/policy` | `departments.policy.store` | Admin or department.head |
| GET | `/departments/{level}/{dept}/policy/{rule_set}` | `departments.policy.show` | Public |
| GET | `/departments/{level}/{dept}/policy/{rule_set}/edit` | `departments.policy.edit` | Admin or department.head |
| PATCH | `/departments/{level}/{dept}/policy/{rule_set}` | `departments.policy.update` | Admin or department.head |
| DELETE | `/departments/{level}/{dept}/policy/{rule_set}` | `departments.policy.destroy` | Admin or department.head |
| GET | `/departments/{level}/{dept}/policy/{policy}/periods/create` | `departments.policy.periods.create` | Auth |
| POST | `/departments/{level}/{dept}/policy/{policy}/periods` | `departments.policy.periods.store` | Admin or department.head |
| GET | `/departments/{level}/{dept}/policy/{policy}/periods/{period}` | `departments.policy.periods.show` | Public |
| GET | `/departments/{level}/{dept}/policy/{policy}/periods/{period}/edit` | `departments.policy.periods.edit` | Admin or department.head |
| PATCH | `/departments/{level}/{dept}/policy/{policy}/periods/{period}` | `departments.policy.periods.update` | Admin or department.head |
| DELETE | `/departments/{level}/{dept}/policy/{policy}/periods/{period}` | `departments.policy.periods.destroy` | Admin or department.head |

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
| GET | `/admin/designations` | `admin.designations.index` | Admin |
| GET | `/admin/designations/create` | `admin.designations.create` | Admin |
| POST | `/admin/designations` | `admin.designations.store` | Admin |
| GET | `/admin/designations/{designation}/edit` | `admin.designations.edit` | Admin |
| PATCH | `/admin/designations/{designation}` | `admin.designations.update` | Admin |
| DELETE | `/admin/designations/{designation}` | `admin.designations.destroy` | Admin |
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

`User` overrides `getRouteKeyName()` to return `'username'` instead (M88, 2026-08-22) — reuses the already-unique, already-existing `username` column rather than adding a dedicated slug column. `/admin/users/{user}` and `/admin/users/{user}/edit` resolve on username, e.g. `/admin/users/ravi_prakash_gautam`. Same rule applies: pass the `$user` model to route helpers, never `->id`.

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

**Non-PDF → PDF normalization (M80, 2026-08-19, applies to all upload paths):** `StoreDocumentRequest::ACCEPTED_MIMETYPES` accepts Word/Excel/PowerPoint/ODT/ODS/ODP/RTF/TXT/CSV and images, but `original_pdf_path` and the whole conversion pipeline (`pdftoppm`, pdfminer, Docling) require actual PDF bytes. Before this fix, `store()` just renamed whatever was uploaded to `.pdf` — a docx upload became a Word file mislabeled `.pdf`, which failed loudly (and permanently — no working retry path) the moment the pipeline tried to read it. Fixed at the source: if the uploaded file's real MIME (via `getMimeType()`, fileinfo-based, same signal `StoreDocumentRequest` validates against) isn't `application/pdf`, it's first saved with a neutral `.upload` extension, run through `soffice --headless --convert-to pdf` (LibreOffice, handles every accepted type including images via its Draw component), and only the resulting real PDF is kept as `{slug}_{timestamp}.pdf`; the original upload is deleted. Each conversion gets its own `-env:UserInstallation` profile dir under `storage/app/soffice-profile-*` (deleted after) — needed because the web user has no writable `$HOME`, and to keep concurrent uploads from sharing/locking one LibreOffice profile. Conversion failure → the temp upload is deleted and the request returns a 500 with a clear message, same as any other store failure.

**Section-based upload (per file):**
1. Slug: `Document::uniqueSlugForSection($title, $section->id)`
2. Vault dir: `document_vault/{dept.level}/{dept.slug}/{section.wing?}/{section.slug}`
3. File stored: `{vaultDir}/{slug}_{YmdHis}.pdf` on `public` disk (converted from the original via `soffice` first if it wasn't already a real PDF — see the non-PDF normalization note above)
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

Folders carry their own, separate `visibility` column with the same two values — gating the
folder's own browse page. Departments, sections, and divisions have no `visibility` column of
their own at all (only `requires_approval`, an unrelated upload-workflow toggle).

**Folder visibility is the ceiling on every document inside it, not a separate, independent
check (fixed 2026-08-17).** A document's own `visibility` used to be the *only* thing every
guest-facing check looked at — a document marked Public that lived inside an Authenticated-only
folder was still directly viewable, downloadable, searchable, and sitemap-indexed by a guest who
had (or found) its URL, even though the folder page itself correctly blocked guest browsing. Fixed
by adding `Document::isPubliclyVisible(): bool` (checks the document's own `visibility` **and**,
if it has a `folder_id`, that the folder's `visibility` is also `public`) and a matching
`Document::scopePubliclyVisible()` query scope — every guest-facing check now goes through one of
these instead of reading the `visibility` column directly:
- `DocumentController@show/pdf/showRuleSetDoc/pdfRuleSetDoc/showDivisionDoc/pdfDivisionDoc/`
  `showSectionFolderDoc/pdfSectionFolderDoc/showDivisionFolderDoc/pdfDivisionFolderDoc/ogImage/index`
- `DownloadController` (zip downloads) — `folderEntries()` additionally short-circuits to an empty
  zip for a guest if the folder itself is `authenticated`, since a zip download has no per-folder
  page guard of its own to inherit; `divisionEntries()`/`department()` (whose direct-document
  queries can include folder-attached documents) use the new scope
- `FrontendController@dashboard` (stat counts, department counts, recent-documents feed)
- `SearchController@index` (document results — folder search results were already correctly
  scoped on the folder's own visibility, unaffected)
- `SitemapController@index`
- `SectionController@index/show`, `RuleSetController@show`, `DepartmentController@index/show`,
  and `FolderController`'s own document-listing queries were **not** changed — each of these
  already either has no folder-attached documents to worry about (rule sets don't have folders)
  or already gates the containing folder itself before ever reaching the document query (so guests
  only reach it when the folder is already known-public). See `tests/Feature/DocumentFolderVisibilityTest.php`.

**Upload modals** — section, rule-set, and folder upload modals include a visibility radio
selector (defaults to Public). `folders/show.blade.php`'s modal is the one exception: since a
folder is a known, fixed context there (unlike section/rule-set uploads, which never target a
folder), the radio defaults to **Authenticated** instead when `$folder->visibility ===
'authenticated'`, and picking Public anyway raises a SweetAlert2 warning ("won't make them visible
to guests — the folder's own restriction still applies") before allowing the upload to proceed.
This is UX sugar only, not the enforcement — the `isPubliclyVisible()` fix above is what actually
prevents the leak regardless of what gets saved, so overriding the warning is safe by design, not
just discouraged. `StoreDocumentRequest` validates and passes the value through to
`Document::create()` unchanged; there's no server-side coercion forcing it to match the folder.

**`documents/show`** — green "Public" or amber "Authenticated Only" badge shown in the document
header, reflecting the document's own `visibility` value as-is (not the effective/inherited one) —
worth remembering when reading that badge on a document inside an authenticated folder.

**Key distinction:** `status` tracks the conversion pipeline (`uploaded → processing → review →
verified`); `visibility` controls read access. A document can be `public` while still `uploaded`
(guests can download the original PDF immediately), or `authenticated` while `verified`
(internal-only even after full processing) — and, per the fix above, a document's *effective*
public-ness is never looser than its folder's, even if its own flag says otherwise.

### Document views

- **`documents/show`** — context-aware: receives context flags `$isRuleSetDoc`, `$isDivisionDoc`, `$isSectionFolderDoc`, `$isDivisionFolderDoc`. Each flag switches breadcrumbs, page subtitle, vault path display, and all route helpers (PDF, edit, destroy). The "Section / Division / Rule Set / Folder" metadata label adapts accordingly. Visibility badge shown in header. When `$isSectionFolderDoc` or `$isDivisionFolderDoc`, the folder name + link are shown in the metadata sidebar.
- **`documents/index`** — tabbed by department; renders section, rule-set, and folder documents; row links follow routing priority: `$doc->folder ? ($doc->division ? documents.divisions.folders.show : documents.folders.show) : ($doc->division ? documents.divisions.show : ($doc->section ? documents.show : documents.rules.show))`. Display context name: `$doc->folder?->name ?? $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name`.

### SEO / social-share metadata (2026-07-25)

`<x-head>` (`resources/views/components/head.blade.php`) accepts `title`, `description`, `image`, `url`, `type` props and emits `<meta name="description">`, `<link rel="canonical">`, and full Open Graph + `twitter:card` (`summary_large_image`) tags. `<x-layout>` forwards these (`description` defaults to `pageSubtitle` if not given). Default `image` is `public/og-default.jpg` (a static 1200×630 branded banner, generated once via a GD script — regenerate by re-running the same script if branding changes, no build step involved).

Per-page dynamic values are set where each page's `<x-layout>` tag is declared:
- `documents/show` builds a real description (doc type · rule set/section · department · amendment · effective year) and, for `visibility=public` documents only, points `image` at `route('documents.og-image', $document)` — `DocumentController::ogImage()` rasterizes page 1 of the PDF via `pdftoppm` on first request (`-scale-to-x 1200`), caches it as a `.og.jpg` sibling file on the `public` disk (same convention as `.md`/`.structure.json`), and serves it directly after. `visibility=authenticated` documents fall back to the generic banner — the route has to be reachable unauthenticated for a social crawler to fetch it at all, so a real thumbnail there would leak content past the auth gate.
- `rule_sets/show` sets a description (department · rule set · document count); image stays the default banner (no natural single "page 1" for a rule set).
- Every other page (`department/show`, `sections/*`, `divisions/*`, `folders/*`, `search/index`, etc.) at minimum gets a real dynamic `title`/`page-subtitle` — no page shows the generic homepage description anymore.

Document show also has a "Share" button on the same row as the status pills, right-aligned (`#share-menu-btn`/`#share-menu-panel`) — the same spot Edit/Delete occupy for a signed-in manager, otherwise empty for a public visitor. Clicking it opens a small dropdown (WhatsApp/X/copy-link — plain `wa.me`/`twitter.com/intent` links plus a `navigator.clipboard` copy button, no library), closes on outside click or Escape.

`public/robots.txt` disallows the auth-only top-level paths (`/admin`, `/profile`, `/approvals`, `/login`, `/logout` — everything else is public read, per the route map above) and points to `/sitemap.xml`. That route (`SitemapController::index`, cached 1 hour via `Cache::remember('sitemap.urls', ...)`) lists every department, every `kind=rules` RuleSet, and every `visibility=public` document in `review`/`verified` status — same visibility rule the show routes themselves already enforce, so the sitemap never lists a URL that would actually 403 for an anonymous crawler.

**Error pages (2026-07-25):** `resources/views/errors/{401,402,403,404,419,429,500,503}.blade.php` all render via a shared `<x-error-page icon="ti-..." heading="..." message="..." />` component (`resources/views/components/error-page.blade.php`) instead of Laravel's default templates — consistent branding, dark mode, real `<title>`/description via `<x-head>`, and `noindex` so they never get indexed or generate a misleading rich preview when a broken/restricted link gets shared. Route model-binding failures (e.g. a bad document slug) and `abort(403)`/`abort(404)` calls throughout the controllers all resolve to these automatically — no per-call-site change needed, Laravel picks the view up by HTTP status code. When adding a new HTTP error response, prefer reusing `<x-error-page>` over a one-off view.

**Favicon/PWA icons (2026-07-25):** `public/favicon.ico`/`favicon-{16,32}.png`/`apple-touch-icon.png`/`icon-{192,512}.png`/`site.webmanifest` — a small branded document-page glyph on the app's indigo, generated once via a GD script (regenerate the same way if branding changes; no build step). Referenced via `<link>` tags in `head.blade.php`. `favicon.ico` is a PNG-in-ICO container (32×32 PNG wrapped in a minimal ICO header) — valid in every current browser, no legacy multi-resolution BMP frames needed.

**Blade gotcha this surfaced, applies everywhere in this codebase:** `<x-component prop="{{ $var }}">` (unprefixed attribute containing `{{ }}`) compiles to `'prop' => e($var)` — the value arrives at the component **pre-escaped**. If that prop is later echoed again with `{{ }}` anywhere downstream (as `<x-head>`'s new meta tags now do with `title`/`pageSubtitle`), the escaping doubles: `&` → `&amp;` → `&amp;amp;`, rendering literally as `&amp;` in the browser. Every `<x-layout title="{{ ... }}">` / `page-subtitle="{{ ... }}"` call site in the app was converted to bound syntax (`:title="..."`, a raw PHP expression, no pre-baked escaping) so the single `{{ $title }}` in `head.blade.php`/`header.blade.php` is the only escape that ever happens. **When adding a new page, always use `:title="..."` / `:page-subtitle="..."` (colon-prefixed) on `<x-layout>`, never the unprefixed `title="{{ ... }}"` form** — the latter silently reintroduces this bug the moment the value contains `&`, `<`, `>`, or `"`.

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

- **Auto kind-suffix on name (2026-07-25)**: `RuleSet::withKindSuffix($name, $kind)` appends " Rules" (kind=rules) or " Policy" (kind=policy) to a user-typed name, unless that word already appears (case-insensitive `\b` match) — so "Bar" → "Bar Rules" but "Bar Rules" stays as-is. Applied in `RuleSetController@store` (both kinds) and `@update` (only when `name` actually changed, so re-saving an edited record doesn't double-suffix). Policy *documents* (`PolicyDocumentController`, e.g. "2024-25") are a different concept — not suffixed.
- **`rule_sets/create`** — name + description form with JS validation; slug is auto-generated server-side from name.
- **`rule_sets/edit`** — same, pre-populated; slug is read-only (set at creation, never changed).
- **`rule_sets/show`** — header + two state-aware upload buttons + two independent modals + hierarchy document list.

**Two upload modals (separate, not combined):**

- **`#modal-rule`** (Upload Document) — indigo accent; type dropdown shows all types except `rule_amendment`, pre-selects `rule`/`policy`; no parent field. The button itself is always enabled — only the root `rule`/`policy` **option inside the dropdown** is disabled once one exists for this rule set (`$rootDocuments->where('document_type', 'rule')->isNotEmpty()`), so supplementary docs (`other`, `notice`, `court_order`, etc.) can still be uploaded afterward through the same modal.
- **`#modal-amendment`** (Upload Amendment) — amber accent; type is a fixed hidden input (`rule_amendment`) shown as read-only badge; requires parent document selection from `$parentOptions` dropdown (root docs only, auto-selects if exactly one exists). Button is disabled until a `rule` doc exists.

Both modals share a `makeQueue(ids)` JS factory function that handles multi-file queue, drag-and-drop, per-row title editing, sequential upload loop, and post-upload redirect/error handling. Each modal has its own set of element IDs passed via the `ids` object.

**Edit lock on rule docs with amendments:** `documents/show.blade.php` — if `$document->document_type === 'rule'` and `$document->amendments->isNotEmpty()`, the Edit button is replaced with a greyed-out disabled `<span>`.

**Cascade delete:** `RuleSetController@destroy` — before soft-deleting the rule set, iterates all documents via `$ruleSet->documents()->each(...)`, writes a `DocumentStatusHistory` row per doc, then soft-deletes each doc — all inside the same `DB::transaction()`. Users do not need to delete documents manually before deleting a rule set.

### Policy Taxonomy (kind=policy RuleSets)

**Implemented 2026-07-15, restructured into containers + policy documents 2026-07-23, terminology
fixed 2026-07-26 (see POLICY_PERIODS.md §8 — "period" now names only the timeframe, never the
entity).** Reuses the `RuleSet` model/controller/views with a `kind` discriminator (`rules` |
`policy`) rather than a parallel model. Full detail (schema, controllers, routes, views,
migration/backfill strategy) lives in [POLICY_PERIODS.md](POLICY_PERIODS.md) — this section is a
summary.

**Two-level structure:** a Policy **container** (state + policy_type, e.g. "UP Excise Policy") is
created once via `RuleSetController` (`kind=policy`, `container_id=null`). Each year/cycle's actual
policy document (e.g. "Excise Policy 2025-26") is created underneath it via
`PolicyDocumentController` (`container_id` set to the container's id) — a policy document is still
just a plain `RuleSet` row, so it holds its own root document + amendments exactly like a Rule Set
does, reusing the same document-listing logic via the `ListsRuleSetDocuments` trait (shared by both
controllers). This replaced the original one-row-per-year design, where creating a new year meant
re-filling the whole "Add Policy" form (state, policy type, name) every time.

**Supersession** happens at the policy-document level (`PolicyDocumentController::store()`):
creating a new policy document under the same container automatically flips the previously-current
one to `superseded` and sets `previous_policy_id` on the new one. Containers themselves are never
superseded — they're permanent once created, and cannot be deleted while they still have policy
documents (`container_id`'s `restrictOnDelete` FK enforces this at the DB layer).

**Controlled vocabularies** — `policy_type` and `state` are dropdown-only on the container (never
free text by default), preventing "Excise Policy" / "excise policy" fragmenting search/filtering:
`RuleSet::POLICY_TYPES`, `RuleSet::STATES` (28 states + 8 UTs, `RuleSet::DEFAULT_STATE = 'Uttar
Pradesh'`), both with an `other` free-text escape hatch, sanitized and title-cased server-side —
see `POLICY_PERIODS.md` for the full validation details.

**Bilingual documents:** `documents.language` (`english` default | `hindi` | `both`) + nullable
`sibling_document_id` self-FK (rule-set/policy uploads only — see `rule_sets/show.blade.php`;
language = "both" there creates two independent `Document` rows, one per language, linked via
`sibling_document_id`, each with its own status/conversion/OCR lifecycle, rather than one row with
two file slots). Elsewhere `language` is a plain column with no sibling-splitting behavior.

**Language field coverage (2026-08-13).** Originally offered only on policy/rule-set uploads —
`StoreDocumentRequest`/`UpdateDocumentRequest` always accepted `language`, but the Section,
Division, Folder, and Bulk Upload forms never rendered the input, so every document uploaded
through those paths silently defaulted to `english` (`StoreDocumentRequest`'s `strtolower(trim($this->language ?? 'english'))`),
and there was no way to correct it after the fact — `UpdateDocumentRequest` had no `language` rule
at all. Fixed: a `language` `<select>` (`Document::LANGUAGES`) added to every upload entry point
(`sections/show.blade.php`, `divisions/show.blade.php`, `folders/show.blade.php`,
`documents/bulk-upload.blade.php`, `quick_conversions/show.blade.php`'s "Save to…" modal) and a
new auto-submitting `language-form` on `documents/show.blade.php` (same pattern as the existing
`visibility-form`) so it can also be corrected post-upload. `UpdateDocumentRequest` gained the
`language` rule + normalization, shared by all five `documents.*.update` route variants since they
all route through the same Form Request class.

**Clickable pills → exact search filters:** the `document_type` and (for policy documents) `state`
badges on `documents/show.blade.php` are links to `search.index` with `?document_type=`/`?state=`
query params — `SearchController::index()` applies them as exact `where()` filters, independent of
and combinable with the free-text `q` search, consistent with the `?sort=&year=` query-param
convention already used by `RuleSetController`/`PolicyDocumentController::show()`.

**Permissions** unchanged from before the restructure — `User::canManagePolicy(RuleSet $policySet)`
/ `canManagePolicyForDepartment(Department $department)` remain department-scoped (not
state-scoped): a department's `department.head` manages every state/policy_type/policy document
under their department. Both containers and policy documents are `RuleSet` rows with the same
`department_id`, so no authorization changes were needed.

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

Every mutating action (upload, delete/archive, restore, force-delete) is scoped to the user's organisational assignment. **Viewing is now also scoped for authenticated users (as of M79, 2026-08-19)** — see "View-scoping" immediately below; this section's own scope-tier table still applies unchanged to uploads/deletes.

**User assignment → scope:**

| User has | Can upload to | Can archive (delete) from |
|---|---|---|
| `division_id` set | That division only | That division only |
| `section_id` set, no `division_id` | All of that section (direct docs + all its divisions) | Same |
| `department_id` set, no `section_id` | All sections + divisions in that department | Same |
| `department.head` privilege + `department_id` | Entire assigned department | Same |
| `organization.head` privilege | Anywhere across all departments | Same |
| `role=system_admin` | Anywhere | Anywhere |
| `role=admin` (org-scoped officer, M79) | Whatever their `department_id`/`section_id`/`division_id` + `.head` privilege resolves to (same tiers as any other role — `role=admin` itself grants no extra scope, only the document-action privilege bundle, see `ORG_ADMIN_PRIVILEGES`) | Same |
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

### View-scoping (M79, 2026-08-19)

Before this, viewing was explicitly unscoped for every authenticated user regardless of role — a
documented architecture decision, reversed after the site owner reported a section-scoped Deputy
Excise Commissioner browsing into a sibling section under the same department. Same org-unit tiers
as upload scope (`global` → `department` → `section` → `division`), applied additively on top of
the existing guest `visibility`/`isPubliclyVisible()` gate — guest access is completely untouched.

**Helper methods:**
```php
User::canView(object $context): bool          // Section/Division/Folder — page-level 403 gate
Document::scopeViewableBy(Builder $query, ?User $user): Builder  // list-query filter
```

Both share tier-matching logic with `canUploadTo()` via a private `matchesOrgScope()` helper on
`User`, differing only in what an **unscoped** user (`uploadScope() === 'none'`) resolves to:
`canUploadTo()` default-denies (no assignment ⇒ no mutation rights, unchanged from before);
`canView()`/`scopeViewableBy()` default-**allow** — an unscoped user still sees everything, exactly
as before M79. This only adds a ceiling for users who already have a department/section/division
assigned; it never narrows someone who has none.

**Deliberately not scoped: Rule Sets and Policies.** Acts/Rules/GOs-by-rule and named department
policies are department-wide reference material with no section-level owner (confirmed with the
site owner before building this) — `scopeViewableBy()` passes through any document carrying a
`rule_set_id` at every tier, and `RuleSetController`/`PolicyDocumentController` were not touched at
all. A section-scoped officer still sees every Rule Set/Policy in their department, same as a
department-scoped one.

**Where it's applied:**
- `SectionController::show()`, `DivisionController::show()`, `FolderController`'s shared
  `renderShow()` — `abort(403)` for an authenticated out-of-scope user, mirroring the pre-existing
  folder-visibility-ceiling 403 pattern (M-04). `SectionController::index()` and the Search
  controller's Sections/Divisions/Folders result blocks additionally filter the *listing* itself
  (`->filter(fn ($s) => $user->canView($s))`), so a scoped user never sees a dead link to a section
  they can't open.
- `DocumentController` — new private `authorizeDocumentView(Document $document, object $context)`
  helper wraps the existing guest `isPubliclyVisible()` check and adds the `canView()` ceiling for
  authenticated users; used by all 8 non-rule-set show/PDF route variants (section, division,
  section-folder, division-folder — **not** the 2 rule-set-doc variants, per the decision above).
  `index()` and `pipeline()` chain `->viewableBy(auth()->user())` onto their existing queries.
- `SearchController::index()` — documents via `viewableBy()`; Sections/Divisions/Folders filtered
  post-fetch the same way; RuleSets left unscoped.
- `FrontendController::dashboard()` — every per-status stat count and the recent-documents feed.
- `DownloadController` — `folder()`/`divisionFolder()`/`division()`/`section()` gained a new
  `authorizeZipView()` 403 gate (mirrors the page-level gate above); `department()` gained an
  explicit tier check via `uploadScope()` directly (`canView()` doesn't resolve a bare `Department`
  — only global/unscoped/matching-department may pull a whole-department export); `ruleSet()`/
  `policyPeriod()`/`policyState()`/`rules()` stay unscoped.
- Sidebar's own Pipeline nav badge count (`components/sidebar.blade.php`) — found unscoped in the
  same pass (would have shown a department/system-wide number next to a link that, once clicked,
  showed a correctly-narrowed list) and fixed to match.

See `SECURITY.md` Pass 7 (M-05) and `summary.md`'s M79 entry for the full incident writeup, and
`tests/Feature/DocumentViewScopeTest.php` for the regression coverage.

**Follow-up gap found after M79 shipped (M81, 2026-08-19):** `canManageDocument()` in the controller
was updated with the org-scoped-admin branch, but `documents/show.blade.php`'s own `$canManageDoc`
— computed independently in the view, not via the controller method, since it needs route-local
context variables — was not, leaving a 6th instance of the exact "admin, or policy department.head"
duplicate the M79 sweep was supposed to have fully closed. Consequence: a scoped `admin` (e.g. the
EC's PA, section-scoped) opening a document in their own section saw no Convert/Edit/Delete/Compare
& Verify actions at all — only Share — despite the server-side action routes already accepting them.
Fixed by mirroring `canManageDocument()`'s third branch in the Blade `@php` block (kept manually in
sync, not extracted to a shared helper — the view needs `$document->division ?? $document->section
?? $ruleSet`, which only exists after the route-context resolution above it). Also found and fixed
the same pattern gating the document-delete button in `rule_sets/_doc_row.blade.php` (server already
allows via `canDeleteFrom()`).

### User management & profile

**Security model** — two distinct access tiers enforced at the route layer, not just in Form Requests:

| Tier | Routes | Middleware | What's accessible |
|---|---|---|---|
| Admin CRUD | `admin.*` | `auth` + `is_admin` | Full user list, create, show, edit any user, delete, role/privilege assignment |
| Self-edit profile | `profile.*` | `auth` | Own name/username/email/mobile/post/password only — no role or privilege fields |

`admin.*` routes are gated by `IsAdmin` middleware (`app/Http/Middleware/IsAdmin.php`, alias `is_admin`, registered in `bootstrap/app.php`). This was the critical fix: previously only `auth` middleware was applied, allowing any authenticated user to list all accounts, access the create form, and delete other users.

**Form Requests:**
- `StoreUserRequest` — `authorize()` requires `isAdmin()`; validates all user fields including role and `designation_id` (`nullable, integer, exists:designations,id`, M74). `username` is `nullable`; `prepareForValidation()` calls `User::uniqueUsername($name, $post)` to auto-generate one from full name + post (`Str::slug`-based, deduped like `RuleSet::uniqueSlugForDepartment()`) when left blank on the create form. Admin can still type/edit their own.
- `UpdateUserRequest` — `authorize()` requires `isAdmin()`; validates all fields including role/privileges/dept/section/`designation_id`. Used only by `admin.users.update`.
- `UpdateProfileRequest` — `authorize()` requires any authenticated user (`$this->user() !== null`); validates name/username/email/mobile/post/password only. Scopes `unique` checks to `auth()->user()->id`. No role, privilege, department, section, or designation fields — those cannot be self-assigned.
- `StoreDesignationRequest`/`UpdateDesignationRequest` (M74) — `authorize()` requires `isAdmin()`; `slug` auto-generated from `name` (`Str::slug($name, '_')`), unique per `department_id` (same `Rule::unique()->where()` pattern as `StoreDepartmentRequest`); `default_privileges.*` validated against `User::PRIVILEGES`, same whitelist as user privileges.

**Views:**
- `admin/users/index.blade.php` — paginated user table (admin-only); post column shows `designation->name ?? post`.
- `admin/users/create.blade.php` — account creation form with role/privilege/dept/section fields plus a Designation `<select>` (grouped generic vs. department-locked, filtered by the chosen Department via the same client-side cascade pattern as Section/Division) and a small "Other post" free-text fallback (admin-only). `applyDesignationPreset()` JS pre-fills Department + privilege checkboxes on selection — one-time, non-locking (M74).
- `admin/users/edit.blade.php` — same Designation select + preset JS as create (admin-only route).
- `admin/_privilege_checkboxes.blade.php` (M74, new) — the Granular Privileges checkbox panel, extracted out of `create.blade.php`/`edit.blade.php` so `admin/designations/create.blade.php`/`edit.blade.php` render the identical markup for `default_privileges` instead of duplicating it. **`groupBy()` key-preservation bug (M86, 2026-08-22):** the panel groups the flat privilege list by category with `collect($privilegeLabels)->groupBy(fn($v) => $v['group'], true)` — the trailing `true` (`$preserveKeys`) is load-bearing; without it, `Collection::groupBy()` silently re-indexes each group with plain `0,1,2...` integers instead of keeping the original privilege-slug keys (`documents.upload`, `section.head`, ...), so every checkbox's `value` attribute rendered a meaningless number instead of the real privilege key — every submission with any privilege checked then silently failed the `in:documents.upload,...` validation rule on both the Designation and User forms. Pre-existing bug (not introduced by M74), surfaced only after M83's flasher fix made the resulting failed-silently form resubmission actually visible to notice. The small raw-key label that used to render under each checkbox's human label (`{{ $key }}`, meant for internal reference) was removed entirely in the same pass — an implementation detail, not something an end user needs to see. **`$readonly` mode (M88, 2026-08-22):** the partial accepts an optional `$readonly` param — when true, renders only the checked privileges as a plain text list (grouped identically) instead of an editable checkbox grid. Added so `admin/users/show.blade.php` (the new read-only user profile page) can reuse the same canonical `$privilegeLabels` list instead of duplicating it.
- `admin/designations/index.blade.php`, `create.blade.php`, `edit.blade.php` (M74, new) — CRUD screen for Designations, grouped by department in the index list.
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
| System Admin | `shubhanraj2002@gmail.com` | `Admin@1234` | `role=system_admin` — full technical bypass + site console, IT/dev only |
| Admin (demo) | `admin.demo@excise.up.gov.in` | `Admin@1234` | `role=admin`, `['*']` privileges — org-scoped full document authority, no site console |
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

**Pipeline / Bulk Upload nav links** — `Pipeline` (linking to `documents.pipeline`) sits under the main document nav with a live count badge, `Document::whereIn('status', [...])->viewableBy(auth()->user())->count()` (2026-08-19, M79 — previously unscoped across all departments; now agrees with what the Pipeline page itself shows once clicked, see "View-scoping" below). `Bulk Upload & Convert` (linking to `documents.bulk-upload`) sits under "Tools", visible only when `auth()->user()->uploadScope() !== 'none'`; the header's "New Conversion" CTA button links to the same route under the same gate. Both replace what were previously placeholder/"Coming soon" entries. `Pipeline Health` (linking to `documents.pipeline.health`, 2026-07-25) sits under "Manage", admin-only (`@if(auth()->user()->isAdmin())` — since M79, `isAdmin()` means `system_admin` only) alongside Users/Activity Log — matches the operational, not document-browsing, nature of the page; the route itself also carries `middleware('is_admin')` as of M79 (previously only the sidebar link was hidden, the raw URL was reachable by any authenticated user). (A `Pulse` link sat below it briefly, 2026-07-25 to 2026-07-26; removed along with Pulse itself.)

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

Current accepted types: PDF, Word (doc/docx), Excel (xls/xlsx), PowerPoint (ppt/pptx), ODT/ODS/ODP, RTF, TXT, CSV, JPEG, PNG, WebP, GIF, TIFF, BMP, HEIC/HEIF. **SVG is explicitly excluded** — it is XML with executable script content and has no valid use case in a government document vault. Max size: 300 MB (raised 2026-07-24 for large scanned court-matter documents).

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

30. **Folder/Division/Section visibility gates the container page AND is a hard ceiling on every document inside it (M84, 2026-08-22 — supersedes the pre-M84 "does not cascade" claim this item used to make, which had gone stale against the actual code and `DocumentFolderVisibilityTest`/`DocumentVerifyTest` coverage for a while before being caught)** — if `folder.visibility`/`division.visibility`/`section.visibility === 'authenticated'`, that container's show page (and, for Folder, its document PDF routes) abort 403 for guests; `SectionController`/`DivisionController` additionally drop an out-of-scope *authenticated* user to the same public-only view instead of hard-aborting (see "View-scoping" above). Separately, `Document::isPubliclyVisible()`/`scopePubliclyVisible()` treat a document's own `visibility` as necessary but not sufficient — a document only counts as publicly visible if its own flag is `public` **and** every non-null container it sits in (`folder`, `division`, `section`) is also `public`; one `authenticated` container anywhere in the chain overrides a `public` document inside it. A public document is NOT independently reachable by direct URL once any container above it is `authenticated` — `authorizeDocumentView()`/`authorizeZipView()` route-level checks call `isPubliclyVisible()`, not the raw `visibility` column, so the ceiling is enforced everywhere, not just at the container page.

31. **Policy reuses `RuleSet` with a `kind` discriminator — not a parallel model** — `RuleSet.kind` (`rules` | `policy`) drives the same controller (`RuleSetController`), the same five `DocumentController` rule-set-document methods, and the same Blade views, branching only where the two genuinely differ: permission (`canManagePolicy()` vs the generic `canUploadTo()`/admin-only rules), and policy-only columns (`state`, `policy_type`, `effective_start_date`/`effective_end_date`, `policy_status`, `previous_policy_id`). Route names mirror this exactly — `departments.policy.*`/`documents.policy.*` sit next to `departments.rules.*`/`documents.rules.*`, both resolved via a `kind` route default applied **per-route** (`Route::get(...)->name(...)->defaults('kind', ...)`), never on the `Route::prefix()->name()->group()` chain itself — `RouteRegistrar::group()` does not return a chainable `Route` instance, so `->defaults()` on it throws `BadMethodCallException`. Do not introduce a separate `Policy` model/controller/view set; extend the `kind`-aware branches in the existing ones instead.

32. **A policy document is valid until superseded, not until its stated end date** — `policy_status` (`current` | `superseded`) is the only field the app trusts to answer "is this the policy to cite." `effective_start_date`/`effective_end_date` are descriptive only. Creating a new policy document under the same container as an existing `current` one automatically flips the old row to `superseded` and links it via `previous_policy_id` — this happens inside `PolicyDocumentController::store()` (a separate endpoint from container creation, `RuleSetController::store()`, since 2026-07-23's container/policy-document split; see POLICY_PERIODS.md §1). Superseded policy documents are never deleted or hidden — they stay fully browsable and citable at their original URL forever (old case references must keep resolving), and amendments may still be uploaded to them.

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
├── footer.blade.php   — footer bar (no props)
└── document-row.blade.php — a document listing row (M71); props: doc, url, destroyUrl (nullable
    → no delete button), isAmendment. Used by sections/show, divisions/_doc_row, folders/_doc_row,
    search/index. `rule_sets/_doc_row.blade.php` is deliberately its own richer copy (live status
    polling + language badge) — use this component for any *new* document listing, don't inline
    another one-off row; that duplication is exactly what caused M71.
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
| Tabler Icons (webfont) | Self-hosted (`public/vendor/tabler-icons/`), **not** CDN — see note below |
| Chart.js | jsDelivr — `chart.js@4.4.7` |
| SweetAlert2 | jsDelivr — `sweetalert2@11` |
| marked.js | jsDelivr — `marked@13` — page-scoped (`@push('scripts')` in `documents/show.blade.php` only, not global); client-side Markdown→HTML for the Compare & Verify editor's live Preview tab |

All additional JS/CSS packages must be loaded from jsDelivr. Add them to `head.blade.php` (global) or push to `@stack('styles')` / `@stack('scripts')` from individual pages.

**Tabler Icons is the one deliberate exception (2026-07-25)** — it used to be jsDelivr-primary with a JS timeout-based fallback to the self-hosted copy, but that fallback only checked whether the *stylesheet* had loaded (`link.sheet`), not whether the actual `.woff2` font file behind it had — on a flaky/restrictive network (this app is used inside a government office) the small CSS file can load fine while the much larger font file stalls or gets blocked, and the fallback never fires. Icons showing as empty glyph boxes with no recovery was a real, repeated user complaint. Fixed by serving the self-hosted copy (`public/vendor/tabler-icons/`) directly, no CDN involved at all — both files were already vendored in the repo.

**Icon font is subset, not the full Tabler set (2026-07-26, refreshed 2026-07-28, 2026-08-05)** — `public/vendor/tabler-icons/tabler-icons.min.css`/`fonts/tabler-icons-subset.woff2` contain only the **120 `ti-*` classes this app actually uses** (subset via `fonttools`/`pyftsubset` from the full ~5,900-icon set), not the full library. This dropped the font from ~892KB (woff2) + 247KB (css) down to ~13KB + ~5KB — the app was only using ~2% of the bundled icons. Same class names (`<i class="ti ti-xxx">`), zero Blade template changes from the subsetting itself. Rebuilt 2026-07-28 to add `ti-file-zip`, `ti-lock-check`, `ti-mail-exclamation`. Rebuilt again 2026-08-05 to add `ti-id-badge-2` (M74 Designations sidebar link) plus two more pre-existing gaps caught by the same audit — `ti-history` (`quick_conversions/create.blade.php`'s "My Conversions" link) and `ti-wand` (same file) — both had been silently rendering nothing since M72, only surfaced now because adding the Designations icon prompted a full re-diff of used-vs-subset classes instead of just grepping for the one new class. Rebuilds should union the previous subset's classes with the newly-needed ones — never regenerate from a fresh grep alone, since a class can be referenced dynamically (string-built in Blade) and not show up in a static search. Font pulled fresh each rebuild from `cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.45.0/dist/fonts/tabler-icons.ttf` (build-time only, matches the pinned version already in the CSS header comment — never loaded from the page itself, see the CDN-reliability note above) to source codepoints and glyphs, then `pyftsubset --unicodes=... --drop-tables+=GSUB,GPOS,GDEF --layout-features='' --no-hinting --flavor=woff2/woff`. **After deploying, the CSS file specifically needs a Cloudflare cache purge** (`/vendor/tabler-icons/tabler-icons.min.css`) — it's cached at the edge with `max-age=14400` (4h) and a stale `cf-cache-status: HIT` will keep serving the old class list until purged or it naturally expires; the woff2/woff font files are fine (byte-identical URL, but Cloudflare treats a changed `Last-Modified`/`ETag` as a fresh object on next fetch — verified this behaves correctly without a font purge).

**Before using a new `ti-*` class that isn't already in this app:** check the full icon set at [tabler.io/icons](https://tabler.io/icons) (or the CDN copy, `https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css`, for reference only — never load it from the page, per the CDN-reliability note above) to find/confirm the exact class name, then **add its glyph to the self-hosted subset** — don't just add the class and assume it'll render; if it's not already in the subset, the glyph doesn't exist in the served font and the icon will silently render nothing (this happened once already: `ti-upload-off` was used in `documents/bulk-upload.blade.php` despite not being a real Tabler icon name — fixed to `ti-upload`, found while building this subset). To regenerate the subset after adding icons: re-run `pyftsubset` against a fresh full-set `.ttf` (Tabler Icons npm/GitHub release) with `--unicodes` set to every currently-used class's codepoint (grep `resources/views` for `\bti-[a-z0-9-]+` to get the full list) plus the new one(s), `--drop-tables+=GSUB,GPOS,GDEF --layout-features='' --no-hinting` (the full font's GSUB table trips a `fontTools` assertion otherwise — icon fonts have no real ligatures/layout features to lose), then convert to woff2/woff and rebuild the trimmed CSS the same way (base `@font-face`/`.ti` rules + one `:before` rule per used class). Don't switch the whole set back to loading everything just because one new icon is needed.

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
- This desktop collapse is a separate mechanic from the mobile drawer below — both can be active at once (e.g. collapsed-icon sidebar reopened as a full drawer on a narrow window).

### Mobile layout (2026-07-25)

- Below the `md` Tailwind breakpoint, `#sidebar` becomes a fixed off-canvas drawer: `fixed inset-y-0 left-0 z-50 -translate-x-full`, toggled to `translate-x-0` by `window.toggleMobileSidebar()` (`layout.blade.php`). At `md:` and up, `md:translate-x-0 md:relative` puts it back in-flow — desktop behavior is unchanged.
- `#sidebar-backdrop` (in `layout.blade.php`, `md:hidden`) dims the page and closes the drawer on tap.
- The header (`header.blade.php`) shows a hamburger button only below `md` that calls the same `toggleMobileSidebar()`. Clicking any link/submit button inside `#sidebar` while `window.innerWidth < 768` auto-closes the drawer (listener wired in `layout.blade.php`'s `DOMContentLoaded`).
- Header also hides the global search box below `sm` (the sidebar's own "Search" link covers it) and collapses "New Conversion" to an icon-only button below `sm`.
- Row-level hover-reveal actions (`opacity-0 group-hover:opacity-100` on view/edit icons in document-row partials, search results, etc.) are invisible on touchscreens since there's no hover — changed to `opacity-100 sm:opacity-0 sm:group-hover:opacity-100` everywhere this pattern appears, so they're always visible below `sm` and hover-revealed above it. If you add a new hover-reveal action, use the same three-class pattern, not bare `opacity-0 group-hover:opacity-100`.
- This is media-query/responsive-class driven, not user-agent detection — there is no separate mobile template.
- `<x-breadcrumb>` (`components/breadcrumb.blade.php`) is `flex-wrap`, not single-line — a long crumb chain wraps instead of stretching the page and adding a horizontal scrollbar. Only the *last* crumb (current page name) gets `max-w-[70vw] sm:max-w-xs truncate`, since it's usually the longest (a document title) and the one most likely to dominate a wrapped line on its own.
- `<x-header>`'s `<h1>` (page title) uses `line-clamp-2 sm:truncate` — wraps to 2 lines below `sm` instead of clipping a long document title to one line with an ellipsis; still single-line-truncates at `sm:` and up.
- PDF preview (`documents/show.blade.php`, `#pdf-iframe` / `#pdf-iframe-fallback`): most mobile browsers have no inline-PDF plugin for an `<iframe>` to use (desktop Chrome/Firefox/Edge use PDFium; Android Chrome/Firefox don't — the frame renders blank or silently downloads instead). Detected via feature-detection, not UA-sniffing: `navigator.pdfViewerEnabled` where it exists (Chromium — true on desktop, false on Android), else assume only Android is unsupported (iOS Safari previews fine). When unsupported, JS hides `#pdf-iframe` and shows `#pdf-iframe-fallback` (a plain "Open PDF" button, opens the PDF route in a new tab) instead. Only the main viewer got this treatment — the admin-only Compare & Verify modal's side-by-side iframes and the trash/approvals preview iframes are desktop workflows, left as plain iframes.

### Flash notifications (php-flasher/flasher-laravel)

**Package:** `php-flasher/flasher-laravel` v2.x — installed, configured, and rendering via a direct `{!! app('flasher')->render('html') !!}` call in `layout.blade.php` (before `@stack('scripts')`).

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
- **Never switch `layout.blade.php` back to the `@flasher_render` Blade directive** — it's a genuine bug in the vendor package: the directive computes and *clears* the notification from session storage as a side effect, but never echoes the resulting HTML, so nothing ever actually renders (M87, 2026-08-22 — found by diffing the compiled Blade cache; had been silently broken since the package was first added, invisible even after M83 published its missing assets). Always call `app('flasher')->render('html')` directly and echo it, as `layout.blade.php` already does.
- Auto-dismiss timeout is the package's 10s default — `config/flasher.php` was tried at 5s (M87) and explicitly reverted; don't re-add it without being asked.

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
- **Every controller method that renders a management form or mutates/reveals scoped data must itself be authorized — `middleware('auth')` alone is never enough.** `FormRequest::authorize()` only runs for methods that take a `FormRequest` parameter (`store()`/`update()`). Methods that don't — `create()`, `edit()`, `destroy()`, any bespoke GET/DELETE action — get **zero** authorization from that mechanism, no matter how carefully the paired Form Request was written. **This is not hypothetical: `SECURITY.md` H-04/H-05 (2026-07-15) found exactly this gap in `RuleSetController`, `DepartmentController`, `SectionController`, `DivisionController`, `FolderController`, and `DocumentController` simultaneously — every one of them correctly gated `store()`/`update()` via a Form Request, then implicitly assumed the same protection covered `create()`/`edit()`/`destroy()` too. It didn't. Any authenticated user, any role, could delete any department/section/division/folder/rule set/policy outright.** When adding or reviewing a controller:
  - If a route/method mutates data or renders a form scoped to a specific department/section/division/folder/document, ask explicitly: "what stops an unrelated logged-in user from hitting this?" `middleware('auth')` answers "must be logged in," never "must be allowed to touch *this* record."
  - `store()`/`update()` — authorize inside the paired `FormRequest::authorize()`, as usual.
  - `create()`/`edit()`/`destroy()` (and any other method without a `FormRequest`) — add a private `authorizeManage()`/`authorizeEdit()` helper on the controller that mirrors the sibling Form Request's `authorize()` logic exactly, and call it as the **first line** of the method, before any query, view render, or mutation. See `RuleSetController::authorizeManage()`, `DepartmentController::authorizeManage()`, `FolderController::authorizeManage()` for the established pattern — copy it, don't reinvent it.
  - Route-group middleware (`is_admin`, etc.) is a valid substitute **only** when it covers the entire group with no per-record scoping needed (e.g. `admin.*` routes — every method is inherently admin-only, so `UserManagementController` needs no additional check). The moment authorization depends on *which* department/section/record is being touched, middleware alone cannot express that — it has to be a per-method check.
  - `app/Policies/*.php` do **not exist in this codebase** — they were tried once (stub classes generated by `make:policy`, never registered in a provider, always returned `false`, never called anywhere) and deleted 2026-07-15 for being dead, misleading scaffolding. Do not resurrect the Laravel Policy/Gate pattern without registering it properly (`Gate::policy()` or auto-discovery) and actually calling `$this->authorize()` — a half-wired policy is worse than no policy, because it looks like protection.
- Mutations (POST/PATCH/DELETE) are always behind `middleware('auth')` — no exceptions.
- Admin-only routes are gated by **both** `middleware('is_admin')` on the route group AND `$user->isAdmin()` in each Form Request's `authorize()`. Defense in depth — never rely on Form Request alone for route-level access control.
- `IsAdmin` middleware (`app/Http/Middleware/IsAdmin.php`) is registered as the `is_admin` alias in `bootstrap/app.php`. It aborts 403 for any non-admin. Applied to the entire `admin.*` route group.
- Use `$request->user()?->isAdmin()` (nullable-safe) in `authorize()` — never assume the user is logged in inside a Form Request.
- Self-deletion must be blocked explicitly in controllers (see `UserManagementController@destroy` using `auth()->id()`).
- Fortify's public registration is **disabled** — accounts are admin-created only. Package stays installed only for `PasswordValidationRules` and the `login`/`two-factor` rate-limiter names; `FortifyServiceProvider::boot()` calls `Fortify::ignoreRoutes()` because login itself is fully custom (see below) — Fortify's own `AttemptToAuthenticate` pipeline calls `Auth::login()` straight off a valid password, which is exactly the step the OTP flow needs to sit in front of.
- **Passwordless onboarding (2026-07-26, admin password override removed 2026-08-05).** `UserManagementController::store()`/`update()` never accept a password — `StoreUserRequest`/`UpdateUserRequest` have no password field at all. The user is created with an unusable placeholder (`Hash::make(Str::random(40))`) and `email_verified_at = null`, then `URL::temporarySignedRoute('onboarding.show', now()->addHours(72), ['user' => $id])` is mailed via `App\Mail\AccountOnboarding`. `email_verified_at` doubles as the link's single-use gate — `App\Http\Controllers\Auth\OnboardingController::show()`/`store()` redirect to `/login` once it's non-null, so a second visit to an already-used link can't reopen the set-password form. No token table — Laravel's signed-URL HMAC is the whole mechanism. The admin edit form originally kept a "leave blank to keep current password" manual override as a mail-outage escape hatch, but that let an admin set/see-set an officer's password directly, at odds with the "officer always sets their own password" principle — removed; "Resend activation link" (still shown on the edit page while `email_verified_at` is null) is now the only recovery path. Self-service `profile.*` (own password only, via `UpdateProfileRequest`) is unaffected — this only ever concerned the admin-facing edit form.
- **Email-OTP login (2026-07-26).** `App\Http\Controllers\Auth\LoginController` — `POST /login` validates credentials via `Auth::guard('web')->validate()` **without** calling `Auth::login()`, then stashes `session(['login.id', 'login.remember', 'otp.code', 'otp.expires_at', 'otp.last_sent_at'])` and emails a 6-digit code via `App\Mail\LoginOtp`, redirecting to `/login/otp`. `POST /login/otp/verify` is the only place `Auth::login()` is called, gated by `hash_equals()` + expiry check. Because no session is ever authenticated before OTP succeeds, there is no "authenticated but unverified" window — plain `middleware('auth')` on every protected route already fully covers the gap, no extra middleware needed. `POST /login/otp/resend` enforces a 45s cooldown (`otp.last_sent_at`) on top of the `two-factor` rate limiter, specifically to protect email send volume. The `two-factor` limiter (`AppServiceProvider`, keyed by `session('login.id')|ip`) was already defined for exactly this "pending 2FA user" case — reused as-is, not reinvented. Interacts with the 7-day sliding session/remember-me above: Laravel's remember-me cookie re-authenticates via `Auth::viaRemember()` without ever touching `/login` again, so a remembered user skips both the password screen and the OTP screen until the remember cookie or session actually lapses — this is *why* OTP-on-every-login doesn't mean OTP-on-every-page-load.
- **Forgot / reset password (2026-08-13).** `App\Http\Controllers\Auth\ForgotPasswordController` uses Laravel's core `Password` broker (`Illuminate\Support\Facades\Password`) — the same `password_reset_tokens` mechanism Fortify's own reset feature wraps — rather than Fortify's routes (which stay disabled via `Fortify::ignoreRoutes()`, see above) or a bespoke signed-URL scheme like onboarding's. `Password::sendResetLink()` always returns the same flash message regardless of whether the email matches an account (`passwords.sent` vs. silently no-op'ing for an unknown email look identical to the user, closing the obvious email-enumeration hole). `User::sendPasswordResetNotification($token)` is overridden to send our own branded `App\Mail\ResetPassword` Mailable instead of Fortify/Notifiable's default Markdown notification, keeping the same `emails.layout` wrapper as `LoginOtp`/`AccountOnboarding`. Token is single-use (deleted from `password_reset_tokens` on success) and expires in 60 minutes (`config('auth.passwords.users.expire')`, framework default, unchanged). `ForgotPasswordController::reset()` also rotates `remember_token` on success, invalidating any existing "remember me" cookies for that account. **Never auto-logs in** — `Password::reset()`'s callback only saves the new password; the controller redirects to `/login`, so a reset always re-enters the normal email + password + OTP sequence from a clean slate, same as any other sign-in. Routes: `GET/POST /forgot-password` (`password.request`/`password.email`), `GET/POST /reset-password{/token}` (`password.reset`/`password.update`) — all `guest`-only, all behind the new `password-reset` rate limiter (same dual-key email+IP / IP-only shape as `login`, see Rate limiting below).
- Mail: `app/Mail/AccountOnboarding.php`, `app/Mail/LoginOtp.php`, `app/Mail/ResetPassword.php`, all `->view()`-based (not Markdown mail), all extending `resources/views/emails/layout.blade.php` (shared inline-CSS wrapper — email clients don't support Tailwind). Transport-agnostic by design: `.env`'s `MAIL_MAILER` picks `resend` (needs `RESEND_API_KEY`, `composer.json` has `resend/resend-php` — NOT `symfony/resend-mailer`, which looks right but isn't what Laravel's own `MailManager::createResendTransport()` actually instantiates) or `smtp` (NIC/Gmail/etc, `MAIL_HOST`/`PORT`/`USERNAME`/`PASSWORD`) — neither Mailable references a transport directly, both just call through `Mail::to()->send()`. Currently active in production with a real Resend key.
- **Profile self-edit** (`GET /profile/edit`, `PATCH /profile`) — any authenticated user may edit their own name, username, email, mobile, post, and password. Role, privileges, department, and section are read-only (admin-assigned). Validated by `UpdateProfileRequest` which scopes uniqueness checks to `auth()->user()->id` and has no role/privilege fields. The `admin.users.edit` / `admin.users.update` routes are strictly admin-only and must not be used for self-editing by non-admins.
- Sidebar avatar and name are clickable links: admins → `admin.users.edit` for their own record; non-admins → `profile.edit`.

### Session lifetime
- `SESSION_LIFETIME=10080` (7 days), sliding — resets on every authenticated request, expires only after 7 days of inactivity. `SESSION_EXPIRE_ON_CLOSE=false` — sessions survive browser restarts. This deliberately reverses `SECURITY.md` A-04/A-05 (2026-07-15, 120-min + expire-on-close + no remember-me, written for a shared-workstation threat model); this deployment turned out to be personal-PC-per-user, not shared kiosks, so the shared-workstation risk those findings guarded against doesn't apply — see `SECURITY.md` for the reversal note. Do not re-tighten this without confirming the deployment model has actually changed back.
- Login form has a "Remember me" checkbox (`name="remember"`), wired to Fortify's built-in `$request->boolean('remember')` handling (no controller changes needed — Fortify's `AttemptToAuthenticate` action reads it natively). Sets Laravel's standard long-lived `remember_token` cookie on top of the 7-day session.

### Rate limiting
- All auth mutation route groups carry `throttle:mutations` middleware (60/min/user).
- `POST /documents` additionally carries `throttle:uploads` (20/min/user); disk exhaustion is guarded by the 300 MB file size cap and the mutations limiter. Once the initial legacy-document bulk load is complete, reduce to 5–10/min.
- All named limiters live in `AppServiceProvider::configureRateLimiters()` — never add inline `throttle:N,M` to routes.
- The `login` and `two-factor` limiters are named in `config/fortify.php` and defined in `AppServiceProvider` — both must remain in sync.
- `password-reset` (2026-08-13) — same dual-key shape as `login` (5/min per email+IP, 10/min per IP); guards both `POST /forgot-password` and `POST /reset-password` against email-enumeration/mail-bomb and token-guessing respectively.

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
- **Activity logging** — `LogMutation` middleware (registered globally) records every authenticated POST/PATCH/DELETE with user ID, IP, user agent, route name, and HTTP status into `activity_logs`, except `LogMutation::SKIP_ROUTES`. The `Login`/`Logout` event listeners record every session start/end. Guests are never logged. `ActivityLog::record()` is non-fatal — logging failures are caught and written to Laravel's application log, never propagated to the user. The `activity_logs` table is append-only; no application route deletes or updates these rows. Raw route-name actions are shown as human-readable labels in the admin UI via `ActivityLogController::ACTION_LABELS` — see the `activity_logs` schema section for the full explanation.
- Sensitive config (DB credentials, mail passwords) belongs in `.env` only — never hardcoded.
- `.env.example` must have blank values for all secrets.

## Conventions

- Bridge any new Python dependency through a Composer/Laravel package where one exists (as with `markitdown`) rather than raw `Process::run()` calls, unless no package exists.
- Long-running or potentially slow operations (extraction, OCR) must be dispatched as queued jobs — never run synchronously in a request/controller, to avoid browser timeouts.
- When generating migrations, prefer updating the original migration file directly for schema-in-flux tables rather than creating alter migrations — migration files are the single source of truth for table shape.

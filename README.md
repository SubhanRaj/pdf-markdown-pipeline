# PDF to Markdown Pipeline (`pdf-markdown-pipeline`)

A robust, local-first document ingestion and conversion portal that transforms dense, unstructured PDFs into clean, structured, AI-ready Markdown.

## 📖 Project Background & Scope

This pipeline was architected to handle the document digitization needs of two State Government bodies:

- **Department of Excise**, Government of Uttar Pradesh
- **Department of Sugarcane & Sugar Industries**, Government of Uttar Pradesh

Government workflows require parsing thousands of pages of dense bureaucratic material — Government Orders (GOs), service codes, departmental policies, Acts, Rules, and amendments — in both English and administrative Hindi (Rajbhasha). Due to strict data privacy and security mandates, this system runs **100% on-premise**, ensuring sensitive administrative data never touches third-party cloud APIs.

While built for government requirements, the architecture is fully open-source and adaptable for any organization that needs an auditable, human-in-the-loop document conversion pipeline.

## ✨ Core Features

- **Multi-Engine Processing** — conversion itself is button-triggered, never automatic on upload; OCR within a conversion can now trigger itself:
  - Every conversion first runs native-text extraction (`markitdown`/pdfminer, invoked through a Laravel queue job) — fast, and correct whenever a real text layer exists, including a geometric table-detection pass so tabular data renders as real Markdown tables instead of one flattened paragraph. A legacy non-Unicode font check (Kruti Dev, Chanakya, DevLys, etc.) flags documents whose text layer decodes to readable-looking garbage, which char-count alone wouldn't catch.
  - A Docling structure-detection pass (layout/table model, own venv) then runs, detecting headings and table cells with bounding boxes; wherever the text extraction's own heuristic missed a table or heading on a page, Docling's own recognized text is spliced into the rendered Markdown to fill the gap — not just shown as a side panel. See `STRUCTURE_RESEARCH.md`.
  - If the text layer looks unreadable, OCR now runs **automatically** (no reviewer click needed) with a choice of four local engines (Tesseract `hin`+`eng` default, EasyOCR, PaddleOCR, Surya) — a reviewer can still re-run manually with a different engine to compare results on a hard document. A one-click "Revert to Text Extraction" restores the pre-OCR result if a given engine's OCR output turns out worse. See `OCR_RESEARCH.md` for on-prem OCR engine comparisons and tradeoffs (Tesseract remains the default; Surya is CPU-impractically slow for full pages on hardware without a GPU).
- **Human-in-the-Loop Validation UI** — A "Compare & Verify" split-pane modal on the document page where clerks and administrators visually check the original PDF against the extracted Markdown, edit the raw text if needed, toggle a rendered Preview (GitHub/VS Code-style formatting via `marked.js`, not raw asterisks) to sanity-check the result, then verify or discard the draft. The already-verified document view renders the same way, server-side via Parsedown.
- **Bulk Upload & Conversion Pipeline Monitor** — a dedicated bulk-upload page (any scoped department/section/division/folder/rule-set, sequential multi-file upload, optional auto-convert) and a pipeline monitor page listing every document still mid-conversion with live status.
- **Strict Siloed Architecture** — A hierarchical directory structure (Level → Body → Section/RuleSet) maps directly to database records, preventing context leakage between administrative units.
- **Dual Document Taxonomy** — Documents belong to either a **Section** (for GOs, notices, policy circulars) or a **Rule Set** (for Acts, Rules, and their amendments), each with dedicated vault paths and URL structures.
- **Policy Taxonomy** — Department-level-only policy containers (`RuleSet` with `kind=policy`) for the state/government's actual named policies — UP Excise Policy, UP Cane Policy, UP Sugar Policy, UP Import/Export Policy — distinct from the subject-specific Rules (Bar, Beer, Bottling, Distillery, Vending) that already live under the Rule Set taxonomy. A new policy period automatically **supersedes** the previous one for the same department + state + policy type (marked historical, never deleted — old URLs keep resolving for pending case citations), while mid-season corrections use the existing amendment flow. `state` and `policy_type` are controlled dropdowns (with a sanitized "Other" free-text fallback) so search and filtering never fragment on casing. Upload defaults to Uttar Pradesh; a separate action adds another state's policy. Managed by admins or the owning department's `department.head` only — everyone else is view-only.
- **Structured Metadata** — Each document carries a `metadata` JSON column (extraction method, OCR engine used, GO number, subject, dates, structure-detection counts, etc.), enabling accurate context retrieval for downstream LLM/RAG pipelines without parsing the Markdown body itself.
- **Maker-Checker Approval Workflow** — Bulk-onboarding operators can have all their uploads held in `pending_approval` until a designated approver reviews them. Approval scope follows the existing organisational hierarchy (section / department / global). Approvers can approve, reject (with mandatory reason), or reclassify (move document to the correct section/division/rule set without re-uploading). Rejected documents can be resubmitted by the uploader. The entire flow is audit-logged.
- **Full Audit Trail** — Every document state transition (`Uploaded → Processing → Review → Verified`, including `pending_approval` and `rejected`) is logged with the acting user and timestamp.
- **Full Rajbhasha / Unicode Support** — All document titles, section names, rule set names, and division names accept Devanagari text natively — including combining marks (matras, halant). Mixed Hindi-English titles like `FL Bottling Rules 2011 (शुद्धिपत्र)` are stored, displayed, and slugified correctly. Validation uses Unicode category classes (`\p{L}\p{M}\p{N}\p{P}\p{Z}`) in both PHP (PCRE) and browser JavaScript. URL slugs preserve Devanagari characters intact (e.g. `…/fl-bottling-rules-2011-16th-amendment-शुद्धिपत्र`) instead of transliterating them.

## 🛠️ Technology Stack

| Layer | Technology |
|---|---|
| Core Framework | Laravel 13, PHP 8.4 |
| Database | MariaDB 12 |
| Web Server | Apache (mod_php or php-fpm) — no Nginx |
| Frontend / UI | Blade Templates, Tailwind CSS v4 (Play CDN + `typography` plugin), Parsedown, `marked.js` (CDN, client-side Markdown preview), `Grid.js` (CDN, sortable/searchable tables in the structure panel), `Cleave.js` (CDN, masked date inputs on the Policy create/edit form) — no Node, no npm, no build step |
| Text Extraction | Python `markitdown`/pdfminer (Microsoft, MIT), via the [`innobrain/markitdown`](https://github.com/innobraingmbh/markitdown) Laravel package — runs first in every conversion (the fast pass) |
| Structure Detection | [Docling](https://github.com/docling-project/docling) (IBM, Apache 2.0) — layout/table-structure model, own Python venv (`storage/app/private/ocr-engines/docling/`), runs after text extraction; headings/tables it detects are spliced into the rendered Markdown wherever the text-extraction heuristic missed them |
| OCR Engines | Tesseract (Google/HP, `hin`+`eng`, default), EasyOCR (JaidedAI), PaddleOCR (Baidu), Surya (VikParuchuri, open source) — auto-triggered when the text layer looks unreadable, or manually re-run with a different engine from the review screen; each in its own Python venv (`storage/app/private/ocr-engines/`, pyenv 3.12.8) |
| Queue | Laravel database queue driver (local single-box deployment, no Redis dependency) |

**Dev tooling:** [`subhanraj/laravel-db-provisioner`](https://github.com/SubhanRaj/laravel-db-provisioner)
(`require-dev`) provides `php artisan db:provision` — generates a random per-project database
name/user/password on first setup instead of reusing (or hardcoding) a shared MariaDB admin
account. See [DEPLOY.md](./DEPLOY.md#3-project-setup) for the full fresh-machine setup sequence.

## ⚙️ PHP Configuration Requirements

PHP ships with restrictive defaults that block uploads larger than 2 MB. Four directives need raising before the pipeline can accept real documents. There are three places to set them — use whichever matches your deployment:

**If you're running `php artisan serve` (the built-in dev server), none of Options A/B below
apply** — `.htaccess` and `.user.ini` are both Apache/php-fpm-specific and are silently ignored
by the CLI server. It only reads the **CLI** php.ini (confirm with `php --ini`, typically
`/etc/php/8.x/cli/php.ini` on Debian/Ubuntu — note this is a **different file** from the
`apache2/php.ini` path in Option C below). Edit that file directly, then restart `php artisan
serve` (Ctrl+C, re-run) — no service to restart, since the CLI server isn't a system service.
This was hit in practice: two ~7-8MB PDF uploads silently failed with the CLI ini still at its
package defaults (`upload_max_filesize = 2M`, `post_max_size = 8M`) while smaller files under
those thresholds succeeded, which is what made it look intermittent rather than a hard limit.

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
storage/app/public/document_vault/
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
    │           ├── {rule-set-slug}/       ← Acts, Rules, and amendments (kind=rules)
    │           ├── {policy-slug}/         ← state excise/cane/sugar/import/export policies (kind=policy)
    │           └── ...
    │
    └── sugarcane_sugar/
        └── (structure to be added once scoped)
```

Policy rule sets (`RuleSet.kind = 'policy'`) share the same `rules/{slug}/` layout as Acts/Rules —
they're both just `RuleSet` records with a different `kind`, not a separate vault branch.

Once a document is uploaded, converting it to Markdown adds sibling files next to the PDF in the
same directory: `{slug}_{YmdHis}.md` (rendered Markdown), `{slug}_{YmdHis}.pre-ocr.md` (backup of
the pre-OCR result, if OCR ran), and `{slug}_{YmdHis}.structure.json` (Docling's compact
headings/tables map) — see "Text Extraction & Markdown Conversion Pipeline" in `claude.md`.

**Section-based document path:**
```
document_vault/{level}/{dept_slug}/{wing?}/{section_slug}/{slug}_{YmdHis}.pdf
```

**Rule-set-based document path** (Acts/Rules and Policies alike):
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
| `name` | string | Full name of the Act/Rule, or the policy period's name |
| `slug` | string | Auto-generated from name |
| `description` | text nullable | Optional summary |
| `requires_approval` | boolean | default false — any upload to this rule set is held for approval |
| `kind` | enum | `rules` (default) \| `policy` — see Policy Taxonomy below |
| `state`, `policy_type` | string nullable | Policy-only; dropdown-controlled, sanitized "Other" fallback |
| `effective_start_date`, `effective_end_date` | date nullable | Policy-only; descriptive, not authoritative |
| `policy_status` | enum | Policy-only; `current` (default) \| `superseded` |
| `previous_policy_id` | FK → rule_sets nullable | Policy-only; self-referencing, set on the policy that supersedes another |
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

For a visual picture of how these routes fit together end to end — document status lifecycle,
upload taxonomy resolution, maker-checker approval, authorization decisions, and the overall
component map — see [APP_FLOW.md](./APP_FLOW.md). The conversion pipeline itself (Markdown/OCR/
structure) has its own dedicated diagram in `OCR_RESEARCH.md`.

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
| `/documents/{level}/{dept}/policy/{rule_set}/{doc}` | GET | `documents.policy.show` | Public* |
| `/documents/{level}/{dept}/policy/{rule_set}/{doc}` | PATCH | `documents.policy.update` | Admin or department.head |
| `/documents/{level}/{dept}/policy/{rule_set}/{doc}` | DELETE | `documents.policy.destroy` | Admin or department.head |
| `/documents/{level}/{dept}/policy/{rule_set}/{doc}/pdf` | GET | `documents.policy.pdf` | Public* |
| `/documents/{level}/{dept}/policy/{rule_set}/{doc}/review` | GET | `documents.policy.edit` | Admin or department.head |
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

### Conversion Pipeline (Markdown / OCR / Structure)

| Route | Method | Name | Auth |
|---|---|---|---|
| `/documents/bulk-upload` | GET | `documents.bulk-upload` | Auth — scoped to the user's own upload permissions within the form itself |
| `/documents/pipeline` | GET | `documents.pipeline` | Auth — shows every document across all departments, not scope-filtered |
| `/documents/{id}/convert` | POST | `documents.convert` | Admin, or `department.head`/policy-manager for policy documents (`canManageDocument()`) |
| `/documents/{id}/convert-ocr` | POST | `documents.convert-ocr` | Same as above |
| `/documents/{id}/revert-ocr` | POST | `documents.revert-ocr` | Same as above |
| `/documents/{id}/structure` | GET | `documents.structure` | Same as above |
| `/documents/{id}/markdown` | PATCH | `documents.markdown.update` | Same as above |
| `/documents/{id}/markdown` | DELETE | `documents.markdown.discard` | Same as above |
| `/documents/{id}/convert-status` | GET | `documents.convert-status` | Auth only — **no** `canManageDocument()` scope check; any logged-in user can poll any document's conversion status by ID (documented, low-severity, open gap — `SECURITY.md` L-04) |

These use numeric `{id}`, not slugs — conversion is an internal pipeline action on a document
that already resolved through one of the slug-based routes above, not a public-facing URL.

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
| `/departments/{level}/{dept}/policy/create` | GET | `departments.policy.create` | Auth |
| `/departments/{level}/{dept}/policy` | POST | `departments.policy.store` | Admin or department.head |
| `/departments/{level}/{dept}/policy/{rule_set}` | GET | `departments.policy.show` | Public |
| `/departments/{level}/{dept}/policy/{rule_set}/edit` | GET | `departments.policy.edit` | Admin or department.head |
| `/departments/{level}/{dept}/policy/{rule_set}` | PATCH | `departments.policy.update` | Admin or department.head |
| `/departments/{level}/{dept}/policy/{rule_set}` | DELETE | `departments.policy.destroy` | Admin or department.head |

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
| `/admin/activity-logs` | GET | `admin.activity.index` | Admin |
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
| `GET/POST /user/confirm-password` | `password.confirm`, `password.confirm.store` | Fortify — re-confirms password before a sensitive action |
| `GET /user/confirmed-password-status` | `password.confirmation` | Fortify |

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

**Completed (2026-07-13 — Text Extraction & Markdown Conversion Pipeline):**
- `ConvertDocumentToMarkdown` queue job — `markitdown`/`pdfminer.six` text-layer extraction, button-triggered per document; quality-checked (near-empty text, or `(cid:N)` glyph-ID fallback tokens from unmapped legacy fonts) and flagged for review either way, never silently discarded
- `RunOcrExtraction` queue job — Tesseract (`hin`+`eng`, hOCR mode) OCR, **explicit human trigger only**, never an automatic fallback (see rationale above)
- Compare & Verify split-pane modal on `documents/show` — edit/save/verify, one-time Discard Draft (resets to pre-conversion state, re-enables Convert), Run OCR trigger for low-quality text-layer results, Edit/Preview tabs (`marked.js`) for rendered vs. raw Markdown while reviewing
- Bulk Upload page (`/documents/bulk-upload`) and Conversion Pipeline monitor (`/documents/pipeline`)
- Tailwind `typography` plugin enabled — the verified-document Markdown view's `prose` classes now actually render (were previously inert)

**Completed (2026-07-14 — Multi-Engine OCR + Table Detection):**
- `config/ocr.php` engine registry; Compare & Verify modal now has an engine dropdown (Tesseract/EasyOCR/PaddleOCR/Surya) next to "Run OCR Extraction" instead of a single hardcoded engine
- EasyOCR, PaddleOCR, and Surya provisioned in their own Python venvs (`storage/app/private/ocr-engines/`, pyenv 3.12.8 — Python 3.14 is too new for their PyTorch/Paddle wheels) and wired into `RunOcrExtraction`/`pdf_structure_extractor.py`; PaddleOCR pinned to the mobile detection/recognition models with `enable_mkldnn=False` (oneDNN CPU crash on this Paddle build); Surya needs a `llama.cpp` binary + shared libs extracted from Ubuntu's `llama.cpp-tools`/`libllama0`/`libggml0` packages (not a pip dependency) — see `OCR_RESEARCH.md` for the full writeup, including why Surya is CPU-impractically slow for full pages without a GPU
- Geometric table detection (`detect_tables()`/`TableBlock` in `pdf_structure_extractor.py`) — tabular data (pricing tables, schedules) now renders as real Markdown tables across all extraction modes, instead of being flattened into one paragraph
- "Revert to Text Extraction" button + `POST /documents/{id}/revert-ocr` — restores the pre-OCR Markdown (backed up once by `RunOcrExtraction`, never overwritten by later OCR re-runs) if a given engine's OCR result turns out worse than the original text-layer pass

**Next up:** production integration decision on which OCR engine to default to per document type, once enough real documents have been compared across all four; Surya GPU acceleration (Vulkan backend, Intel iGPU) if full-page OCR speed on that engine becomes a priority; vault path file resolution refinements on verification.

**Completed (2026-07-15 — Policy Taxonomy):**
- `RuleSet.kind` (`rules` | `policy`) discriminator — Policy reuses the `RuleSet` model/controller/views rather than a parallel model; department-level-only, available to every department (existing or future), no allowlist
- Controlled vocabularies — `RuleSet::POLICY_TYPES` (Excise/Cane/Sugar/Import/Export/Other) and `RuleSet::STATES` (28 states + 8 UTs), both dropdown-only with a sanitized "Other" free-text fallback so search/filtering never fragments on casing
- Year-over-year supersession — a new policy for the same department + state + policy type automatically flips the previous `current` one to `superseded` (linked via `previous_policy_id`) inside the same transaction as creation; superseded policies are never deleted, stay fully browsable/citable at their original URL, and can still receive amendments
- `effective_start_date`/`effective_end_date` are descriptive only — `policy_status`, not dates, is what the app trusts for "is this the policy to cite"; entered via Cleave.js-masked `DD-MM-YYYY` fields (CDN, page-scoped) rather than a native date picker
- Upload flow defaults to Uttar Pradesh (hidden `state` field); a separate "Add Other State's Policy" action reveals the state dropdown
- `User::canManagePolicy()`/`canManagePolicyForDepartment()` — admin or the owning department's `department.head` only; everyone else is view-only for policy documents (convert/OCR/verify/discard/edit/delete all gated the same way, both client- and server-side)
- Department show page gets a "Policies" panel (current only) plus a collapsed "Historical Policies" disclosure; Bulk Upload's Rule Set picker now includes policy containers (tagged `[Policy]`/`(Superseded)`) in the same dropdown, since both submit via `rule_set_id` identically

**Fixed post-merge (2026-07-15 — Policy Taxonomy follow-ups):**
- **Blade `ParseError` on `/departments/{level}/{dept}/policy/create`** — `rule_sets/create.blade.php` had a `route(\"departments.{$kind}.store\", ...)` call with invalid backslash-escaped quotes inside a `{{ }}` PHP context (only valid inside an already-quoted string, not as a raw PHP token); switched to plain double quotes, matching the pattern already used in `rule_sets/show.blade.php` and `_doc_row.blade.php`
- **Dark-mode toggle contrast** — the "Add UP Policy" / "Add Other State's Policy" buttons toggled base utility classes (`bg-indigo-600`) via JS while leaving a static `dark:bg-slate-800` class in place from the inactive markup; in dark mode the two-class `dark:` selector outranked the one-class active-state selector on specificity, so "Other State" never actually highlighted when selected and "UP" fell back to a plain white background once deselected. JS now swaps the full active/inactive class set per button instead of toggling individual utilities
- **Policy form widened** — `max-w-2xl` → `max-w-4xl` for the Policy variant only (plain Rule Set create form unchanged), since this page has no sidebar-type content competing for width
- **Policy Type dropdown scoped to the uploading department** — previously listed all of Excise/Cane/Sugar/Import/Export Policy regardless of department, so an Excise upload could accidentally be filed as a Cane Policy; now locked to the single type the department's slug already resolves to server-side (`RuleSetController::create`'s `$defaultPolicyType`), plus "Other" for anything genuinely outside that department's own named policy (e.g. Import/Export Policy, entered as free text)
- **"Other" policy-type free text now title-cased server-side** (`Str::title()` in both `StoreRuleSetRequest`/`UpdateRuleSetRequest`) — `"import POLIcy"` and `"Import policy"` both persist as `"Import Policy"`, so this controlled-vocabulary escape hatch doesn't reintroduce the casing fragmentation the dropdown itself was built to avoid
- **Security audit (`SECURITY.md` Pass 5, H-04, fixed)** — `RuleSetController::create()`/`edit()`/`destroy()` had no authorization check beyond the route's blanket `auth` middleware, so any authenticated user (not just admin/`department.head`) could view any department's policy forms and — critically — delete any rule set or policy outright. Fixed with a controller-level `authorizeManage()` helper mirroring the same check already enforced on `store()`/`update()` via the Form Requests
- **Same flaw found codebase-wide (`SECURITY.md` H-05, fixed)** — the identical no-authorization gap existed in `DepartmentController`, `SectionController`, `DivisionController`, and `FolderController`'s `create()`/`edit()`/`destroy()` methods, plus `DocumentController`'s five `edit*Doc()` review forms — any authenticated user could delete any department, section, division, or folder outright. Fixed with the same per-controller authorization-helper pattern, one mirroring each controller's own paired Form Request logic

**Completed (2026-07-15 — Docling Structure Detection, Phase 1):**
- `ConvertDocumentToMarkdown` gains a Pass 0: Docling (own venv) detects headings and table cells with bounding boxes for every document, automatically, before the text-layer pass — always uses the default engine (Tesseract) for Docling's own scanned-page OCR internally (discarded after structure detection, not surfaced as a UI choice — an engine dropdown was tried before conversion and removed, since it broke the pattern of only offering an engine choice once there's a result to react to)
- Compact structure map stored as a `{slug}.structure.json` sibling file on the `public` disk (never in the database), viewable via `GET /documents/{id}/structure`; discarded alongside the Markdown draft by `discardMarkdown()`
- Informational only this round — not yet merged into the rendered Markdown; see `STRUCTURE_RESEARCH.md` for the evaluation and the deferred merge phase
- Legacy non-Unicode Devanagari font detection (Kruti Dev, Chanakya, DevLys, etc.) added to `pdf_structure_extractor.py` — flags `needs_ocr_review` even when a corrupted text layer would otherwise pass the existing char-count/cid-token quality check

**Completed (2026-07-16 — Docling table splice, review UX, queue-status fix, bulk state-policy seeding):**
- Partial Phase 2: Docling's own recognized table text is now spliced into the rendered Markdown wherever the existing geometric heuristic misses a table on a page — no LLM, reuses text Docling's Pass 0 already produced. Known limitation: on some OCR-derived documents a garbled duplicate of the same table can still appear alongside it; full de-duplication needs OCR/Docling bbox coordinate reconciliation, not done this round. See `STRUCTURE_RESEARCH.md`.
- Structure panel moved from a page-level banner into the Compare & Verify modal itself (collapsible, headings as a list, tables via Grid.js); modal is now full-screen.
- Fixed a real bug: `convert()`/`convertOcr()` never persisted `processing`/`ocr_pending` status before dispatch, so a document queued behind a slow job (e.g. a 14-minute PaddleOCR run) looked like conversion had silently stopped once the polling JS saw a stale status. Both endpoints now persist status + history before dispatch; `conversionStatus()` reports `queued_behind_other_job` so the UI shows "waiting in queue" instead.
- New `php artisan policies:seed` command bulk-imports a folder of state excise/export policy PDFs into `RuleSet`(kind=policy)/`Document` rows, so bulk test documents don't need uploading one at a time through the form.
- `OCR_RESEARCH.md` corrected: EasyOCR's entry was still describing it as "not integrated" despite being one of the four live selectable engines since 2026-07-14.

**Completed (2026-07-17 — heading splice, pipeline reorder, auto-OCR-trigger):**
- Heading splice, symmetric to the table splice above: Docling's detected headings are inserted wherever the geometric heuristic found none of its own on a page.
- `ConvertDocumentToMarkdown` now runs the fast text-layer pass before Docling's structure pass (previously the reverse), so the quality/legacy-font check is known before Docling's per-page time is spent, not after.
- If that check flags the text layer unreadable, `RunOcrExtraction` is now dispatched automatically — no reviewer click needed for the common "this is a scan" case. Manual OCR re-runs (e.g. a different engine) still work as before.

## 🚀 Future Roadmap

Advanced enterprise features and security enhancements planned for SDC/NIC compliance and high-value bureaucratic workflows are documented in [ROADMAP.md](ROADMAP.md).

**Highlights:**
- Mandatory TOTP 2FA + concurrent session blocking (`Auth::logoutOtherDevices`)
- ClamAV anti-virus pipeline integration (queued scan before text extraction)
- Dynamic PDF watermarking for `authenticated`-visibility downloads (`setasign/fpdi`)
- Maker-Checker (E-File approval) workflow with `pending_approval` status stage
- Full-text Devanagari/English search via Meilisearch + Laravel Scout
- Non-destructive document versioning with SHA-256 hash audit trail

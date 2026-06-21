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

## 🛠️ Technology Stack

| Layer | Technology |
|---|---|
| Core Framework | Laravel 13, PHP 8.4 |
| Database | MariaDB 12 |
| Frontend / UI | Blade Templates, Tailwind CSS v4, Parsedown |
| Text Extraction | Python `markitdown`, via the [`innobrain/markitdown`](https://github.com/innobraingmbh/markitdown) Laravel package |
| OCR Engine | Tesseract OCR (`hin` + `eng` language packs) |
| Queue | Laravel database queue driver (local single-box deployment, no Redis dependency) |

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
| `document_type` | string | `go` \| `policy` \| `notice` \| `court_order` \| `service_code` \| `rule_amendment` \| `other` |
| `original_filename` | string | |
| `original_pdf_path` | string | Full relative path on `public` disk |
| `markdown_path` | string nullable | Set after extraction job completes |
| `vault_path` | string nullable | Vault directory; set at upload |
| `status` | string | `uploaded → processing → ocr_pending → review → verified \| failed` |
| `metadata` | json nullable | GO number, subject, dates, etc. |
| timestamps + softDeletes | | |

Unique constraint: `(section_id, slug)` for section documents. Slug generation for rule-set documents uses `uniqueSlugForRuleSet()`.

### `document_status_histories`
| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `document_id` | FK → documents | `cascadeOnDelete` |
| `actor_id` | FK → users nullable | `nullOnDelete` |
| `from_status` | string nullable | |
| `to_status` | string | |
| `note` | text nullable | |
| `created_at` | timestamp | Append-only — no `updated_at` |

### `users`
Standard Laravel/Fortify users table with `is_admin` boolean. Public registration disabled — admin-created only.

## 🗺️ Route Map

All models use slug-based routing (`getRouteKeyName() = 'slug'`). IDs never appear in URLs.

`{level}` = `dept` (department_level) | `sectt` (secretariat_level) — disambiguates departments sharing a slug across levels.

| Resource | Public | Auth-Protected Mutations |
|---|---|---|
| Documents index | `GET /documents` | — |
| Section document | `GET /documents/{level}/{dept}/{section}/{doc}` | POST store, PATCH, DELETE |
| Section document PDF | `GET /documents/{level}/{dept}/{section}/{doc}/pdf` | — |
| Rule-set document | `GET /documents/{level}/{dept}/rules/{rule_set}/{doc}` | PATCH, DELETE |
| Rule-set document PDF | `GET /documents/{level}/{dept}/rules/{rule_set}/{doc}/pdf` | — |
| Departments | `GET /departments`, `GET /departments/{level}/{dept}` | POST, PATCH, DELETE |
| Sections | `GET /departments/{level}/{dept}/sections/{section}` | POST, PATCH, DELETE |
| Rule sets | `GET /departments/{level}/{dept}/rules/{rule_set}` | POST, PATCH, DELETE |
| Admin users | — | Full CRUD under `/admin/users` |

## 🚧 Status

Active development. The core upload, browse, and rule-set flows are working end-to-end.

**Complete:**
- Database schema: `departments`, `sections`, `rule_sets`, `documents` (with `rule_set_id`, `title`, `document_type`), `document_status_histories`, `users`
- Full CRUD for Documents, Departments, Sections, Rule Sets, and admin User Management — all with DB transactions, try/catch, and `$request->validated()` throughout
- Dual document taxonomy: section-based (GOs, notices, circulars) and rule-set-based (Acts, Rules, amendments) with separate vault paths and URL structures
- File upload: accepts PDF, Word, Excel, PowerPoint, ODT, all image formats, RTF, TXT, CSV — validated against actual magic bytes; stored directly in the vault directory as `{slug}_{YmdHis}.pdf`
- Rate limiting: login brute-force (5/min per email+IP), general mutation cap (60/min/user), upload cap (10/min/user) — all named limiters
- Sidebar fully dynamic: driven by DB records; no hardcoded department links
- Level-aware department routing: `{level}` URL segment disambiguates departments sharing slugs across levels
- Browse Vault sidebar and dashboard department cards are fully dynamic

**Next up:** Queue job for extraction via `markitdown`, OCR fallback for scanned PDFs, split-pane review UI (PDF embed + editable Markdown), vault path file resolution on verification.

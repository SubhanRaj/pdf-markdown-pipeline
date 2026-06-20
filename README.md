# PDF to Markdown Pipeline (`pdf-markdown-pipeline`)

A robust, local-first document ingestion and conversion portal that transforms dense, unstructured PDFs into clean, structured, AI-ready Markdown.

## 📖 Project Background & Scope

This pipeline was architected to handle the document digitization needs of two State Government bodies:

- **Department of Excise**, Government of Uttar Pradesh
- **Department of Sugarcane & Sugar Industries**, Government of Uttar Pradesh

Government workflows require parsing thousands of pages of dense bureaucratic material — Government Orders (GOs), service codes, departmental policies, and legacy court orders — in both English and administrative Hindi (Rajbhasha). Due to strict data privacy and security mandates, this system runs **100% on-premise**, ensuring sensitive administrative data never touches third-party cloud APIs.

While built for government requirements, the architecture is fully open-source and adaptable for any organization that needs an auditable, human-in-the-loop document conversion pipeline.

## ✨ Core Features

- **Dual-Engine Processing**
  - Native-text PDFs are processed via the `markitdown` Python package (invoked through Laravel queue jobs).
  - Scanned legacy documents fall back to OCR (Tesseract, with the `hin` language pack for bilingual Devanagari/English text).
- **Human-in-the-Loop Validation UI** — A split-pane interface where clerks and administrators visually verify the original PDF against the compiled, styled Markdown (rendered via Parsedown) before committing the data to the vault.
- **Strict Siloed Architecture** — A hierarchical directory structure (Level → Body → Section) maps directly to database records, preventing context leakage between administrative units (e.g. Excise data never resolves into a Sugarcane query, and vice versa).
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

Scope for this phase is **Secretariat and Head Quarter level only** — policies, GOs, and rules are uniform across field offices (DEO/DEC/JEC), so no district/jurisdiction-level breakdown is needed in the vault. Field office tiers can be added later if a use case requires it.

```text
storage/app/document_vault/
├── secretariat_level/
│   └── excise/                                  # repeat sibling for sugarcane/, sugar_federation/ when added
│       ├── joint_secretary_wing/
│       │   └── sections/                        # section names to be added later
│       └── deputy_secretary_wing/
│           └── sections/                        # section names to be added later
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
        └── (structure to be added once scoped)
```

Additional departments, wings, or sections can be added to the vault without restructuring existing branches.

## 🗄️ Database Schema

Core tables are migrated and in use. Structural columns are minimal; evolving fields (GO number, subject, dates, etc.) live in a `metadata` JSON column until they stabilise.

- **`departments`** — name, slug, level (`secretariat_level` / `department_level`). Unique on `(slug, level)`. Soft-deleted.
- **`sections`** — belongs to a department; carries an optional `wing` (e.g. `joint_secretary_wing`, `headquarter`). Unique on `(department_id, wing, slug)`. Soft-deleted.
- **`documents`** — one row per ingested file. FK to department + section + uploader. Status machine: `uploaded → processing → ocr_pending → review → verified | failed`. Stores `original_pdf_path`, `markdown_path`, `vault_path` (set post-verification), and a JSON `metadata` column.
- **`document_status_histories`** — append-only audit log of every status transition, with actor and optional note.
- **`users`** — standard Fortify users table with `is_admin` boolean. Public registration disabled.

## 🚧 Status

Active development. Core scaffolding is complete:

- All database migrations applied and models wired with relationships and `$fillable`.
- Full CRUD routes and controller stubs for Documents, Departments, Sections, and admin User Management.
- Blade component architecture in place (`x-layout`, sidebar, header, dark mode toggle).
- Admin dashboard with document statistics chart.
- Form Request validation classes with sanitisation and JS-side real-time validation.

**Next up:** queue job for PDF extraction via `markitdown`, OCR fallback, split-pane review UI, and vault path resolution on verification.
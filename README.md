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

## 🗄️ Database Schema (Overview)

> Schema is under active design and will evolve. Structural columns are kept minimal; department/section-specific fields live in a flexible metadata column until they stabilize enough to be promoted to real columns.

Planned tables:

- **`documents`** — One row per ingested file. Tracks vault path, originating department/section/level, processing status (`uploaded → processing → review → verified`), original PDF path, converted Markdown path, uploading user, and a JSON `metadata` column for evolving fields (GO number, subject, dates, etc.).
- **`document_status_history`** — Append-only audit log of every state transition, with actor and timestamp.
- **`users`** — Department personnel with role/section assignments controlling vault access.
- **`departments`** / **`sections`** — Reference tables defining the hierarchy shown in the vault structure above, used to enforce that a document's silo path matches its assigned department (preventing Excise data from resolving under a Sugarcane query, and vice versa).

## 🚧 Status

Early scaffolding stage — repository initialized, architecture and schema design in progress.
# Application Flow — Diagrams

**Date:** 2026-07-17
**Purpose:** Visual map of how a request moves through this app — upload, taxonomy resolution,
approval, conversion, review, verify/archive — plus authorization and the component map. Kept in
its own file since `README.md` is already long; linked from there. For the Markdown/OCR/structure
conversion pipeline specifically (Pass 1, Pass 0, splice, auto-OCR-trigger in full detail), see
the diagram in `OCR_RESEARCH.md` — not duplicated here, only referenced.

**Legend** (colors are consistent across every diagram below, though not every diagram uses
every color):

| Color | Meaning |
|---|---|
| 🟦 Indigo | Entry point / start of a flow |
| 🟨 Amber | Pending, in-progress, or a decision node |
| 🟩 Green | Good outcome, done, or a terminal success state |
| 🟥 Red | Rejected, failed, or flagged for attention |
| 🔷 Sky blue | Scoped/authorized step, controller, or review checkpoint |
| 🟪 Purple/pink | Background job, processing step, or storage layer |

## 1. Document status lifecycle

Split into two diagrams — upload/approval, then conversion/verification — since one combined
diagram had too many crossing arrows to read cleanly.

**Upload and approval:**

```mermaid
stateDiagram-v2
    classDef pending fill:#fef3c7,stroke:#d97706,color:#78350f
    classDef good fill:#d1fae5,stroke:#059669,color:#064e3b
    classDef bad fill:#fee2e2,stroke:#dc2626,color:#7f1d1d

    [*] --> pending_approval
    [*] --> uploaded
    pending_approval --> uploaded
    pending_approval --> rejected
    rejected --> pending_approval
    uploaded --> [*]

    class pending_approval pending
    class uploaded good
    class rejected bad
```

`[*] --> pending_approval` fires when the uploader or the upload context (section/division/rule
set) requires approval; otherwise `[*] --> uploaded` fires directly. A checker moves
`pending_approval` to `uploaded` (approve) or `rejected` (reject, reason required); only the
original uploader can move `rejected` back to `pending_approval` (resubmit). See
`ApprovalController`.

**Conversion and verification** (picks up from `uploaded`):

```mermaid
stateDiagram-v2
    classDef start fill:#e0e7ff,stroke:#4338ca,color:#312e81
    classDef working fill:#fef3c7,stroke:#d97706,color:#78350f
    classDef good fill:#d1fae5,stroke:#059669,color:#064e3b
    classDef bad fill:#fee2e2,stroke:#dc2626,color:#7f1d1d

    uploaded --> processing
    uploaded --> verified
    processing --> review
    processing --> ocr_pending
    processing --> failed
    review --> ocr_pending
    ocr_pending --> review
    ocr_pending --> failed
    failed --> processing
    review --> verified
    verified --> [*]

    class uploaded start
    class processing,ocr_pending working
    class review working
    class verified good
    class failed bad
```

- `uploaded --> processing`: reviewer clicks Convert to Markdown.
- `processing --> review`: text layer was readable (`ConvertDocumentToMarkdown`).
- `processing --> ocr_pending`: text layer was unreadable — OCR auto-dispatches, no click needed
  (M34, see `STRUCTURE_RESEARCH.md`).
- `review --> ocr_pending`: reviewer manually re-runs OCR with a different engine.
- `ocr_pending --> review`: `RunOcrExtraction` completes.
- either job throwing goes to `failed`; the Pipeline monitor's Retry button sends it back to
  `processing`.
- `review --> verified` and `uploaded --> verified` (skip conversion entirely, verify the
  text-layer-only document as-is) both go through `DocumentController::updateMarkdown()`.

Any status can be soft-deleted to `archived` and later restored back to its prior status —
omitted from the diagrams above to avoid an arrow from every node. `visibility`
(`public`/`authenticated`) is a separate, independent flag, not part of this state machine.

## 2. Upload — taxonomy resolution

Every upload resolves to exactly one of five contexts, which decides the vault path and the
document's foreign keys (`DocumentController::store()`):

```mermaid
flowchart TD
    classDef entry fill:#e0e7ff,stroke:#4338ca,color:#312e81
    classDef decision fill:#fef3c7,stroke:#d97706,color:#78350f
    classDef branch fill:#e0f2fe,stroke:#0284c7,color:#0c4a6e
    classDef good fill:#d1fae5,stroke:#059669,color:#064e3b
    classDef pending fill:#fee2e2,stroke:#dc2626,color:#7f1d1d

    U[POST to documents] --> CTX{Which ID was submitted}
    CTX -->|rule_set_id| RS[Rule-set document]
    CTX -->|folder_id, no division| FS[Section-folder document]
    CTX -->|folder_id and division| FD[Division-folder document]
    CTX -->|division_id| DV[Division document]
    CTX -->|section_id only| SD[Direct section document]

    RS --> APR{Approval required}
    FS --> APR
    FD --> APR
    DV --> APR
    SD --> APR

    APR -->|yes| PA[status pending_approval, hidden from browse]
    APR -->|no| UP[status uploaded, visible per visibility flag]

    class U entry
    class CTX,APR decision
    class RS,FS,FD,DV,SD branch
    class UP good
    class PA pending
```

Approval-required means either the uploader's `uploads_require_approval` flag or the context's
own `requires_approval` flag. Vault paths: rule-set docs (Acts/Rules and Policies alike) go under
`rules/RULE_SET_SLUG/`; the other four branches nest under the owning section's own directory —
see the vault tree in `README.md`. Each branch also picks a distinct
`Document::uniqueSlugForRuleSet()`/`ForFolder()`/`ForDivision()`/`ForSection()` method, so slug
collisions are scoped to the right parent, not globally.

## 3. Maker-checker approval flow

```mermaid
flowchart LR
    classDef entry fill:#e0e7ff,stroke:#4338ca,color:#312e81
    classDef pending fill:#fef3c7,stroke:#d97706,color:#78350f
    classDef decision fill:#e0f2fe,stroke:#0284c7,color:#0c4a6e
    classDef good fill:#d1fae5,stroke:#059669,color:#064e3b
    classDef bad fill:#fee2e2,stroke:#dc2626,color:#7f1d1d

    M1[Bulk operator uploads] --> M2[status pending_approval, hidden from browse]
    M2 --> C1[Checker opens approvals queue]
    C1 --> C2{Decision}
    C2 -->|approve| C3[status uploaded]
    C2 -->|reject with reason| C4[status rejected]
    C2 -->|reclassify| C5[moved to correct section or rule set, stays pending_approval]
    C4 --> R1[Uploader resubmits]
    R1 --> M2

    class M1 entry
    class M2,C1,C5 pending
    class C2 decision
    class C3 good
    class C4,R1 bad
```

Approval scope follows the org hierarchy (section/department/global) — a checker only sees
submissions within their own scope, enforced in `ApprovalController::index()`'s query, not just
the UI.

## 4. Authorization — who can do what

```mermaid
flowchart TD
    classDef entry fill:#e0e7ff,stroke:#4338ca,color:#312e81
    classDef decision fill:#fef3c7,stroke:#d97706,color:#78350f
    classDef good fill:#d1fae5,stroke:#059669,color:#064e3b
    classDef scoped fill:#e0f2fe,stroke:#0284c7,color:#0c4a6e
    classDef warn fill:#fee2e2,stroke:#dc2626,color:#7f1d1d

    R[Any route] --> A{Guest or authenticated}
    A -->|guest| G[Public routes only, 403 on authenticated-only documents]
    A -->|authenticated| B{Admin}
    B -->|yes| ALL[Full access, bypasses every scope check]
    B -->|no| C{Which action}
    C -->|upload or delete| D[User scope check against department, section, division]
    C -->|create edit destroy on dept, section, division, folder| E[Per-controller authorization helper]
    C -->|convert, OCR, structure, markdown edit| F[Admin, or department head for policy documents only]
    C -->|approve reject reclassify| Gp[documents.approve privilege, scoped to approver own org boundary]
    C -->|convert-status poll| H[Auth only, no scope check, documented low-severity gap]

    class R entry
    class A,B,C decision
    class ALL good
    class G,D,E,F,Gp scoped
    class H warn
```

`E` closes a real gap found in `SECURITY.md` H-04/H-05 (these routes had no authorization check
beyond the blanket `auth` middleware before that fix). `H` is the still-open `SECURITY.md` L-04
gap: any logged-in user can poll any document's conversion status by ID.

## 5. Component map

```mermaid
flowchart TD
    classDef view fill:#e0e7ff,stroke:#4338ca,color:#312e81
    classDef ctrl fill:#e0f2fe,stroke:#0284c7,color:#0c4a6e
    classDef store fill:#fef3c7,stroke:#d97706,color:#78350f
    classDef job fill:#fce7f3,stroke:#db2777,color:#831843
    classDef py fill:#d1fae5,stroke:#059669,color:#064e3b
    classDef disk fill:#ede9fe,stroke:#7c3aed,color:#4c1d95

    Blade[Blade views] --> Ctrl[Controllers]
    Ctrl --> DB[(MariaDB)]
    Ctrl --> Jobs[Queue jobs, database driver, single worker]
    Jobs --> CDM[ConvertDocumentToMarkdown]
    Jobs --> ROE[RunOcrExtraction]
    CDM --> Py[Python venvs]
    ROE --> Py
    Py --> Disk[Public disk, document vault]
    CDM -.->|auto dispatch| ROE
    Ctrl --> ActivityLog[LogMutation middleware, activity_logs table]

    class Blade view
    class Ctrl,ActivityLog ctrl
    class DB store
    class Jobs,CDM,ROE job
    class Py py
    class Disk disk
```

- **Blade** — `documents/show`, `documents/pipeline`, `documents/bulk-upload`, `approvals/index`,
  `admin/*`, etc.
- **Controllers** — `DocumentController`, `RuleSetController`, `ApprovalController`,
  `DepartmentController`/`SectionController`/`DivisionController`/`FolderController`.
- **MariaDB** — `departments`, `sections`, `divisions`, `folders`, `rule_sets`, `documents`,
  `document_status_histories`, `users`.
- **Python venvs** — `markitdown`/pdfminer (text layer), Docling (structure), Tesseract/EasyOCR/
  PaddleOCR/Surya (OCR), each in its own venv under `storage/app/private/ocr-engines/`.
- **Public disk** — `document_vault/.../SLUG.pdf` plus sibling `.md`, `.pre-ocr.md`, and
  `.structure.json` files once converted.
- The dotted edge is M34's auto-dispatch: `ConvertDocumentToMarkdown` queues `RunOcrExtraction`
  itself when the text layer is unreadable, instead of waiting for a reviewer to trigger it.

See `OCR_RESEARCH.md` for the conversion pipeline's own detailed flowchart (pass ordering, quality
checks, splice logic, auto-OCR-trigger) — this file stops at the component-boundary level to
avoid duplicating that diagram.

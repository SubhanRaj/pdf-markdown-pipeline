# Application Flow — Diagrams

**Date:** 2026-07-17
**Purpose:** Visual map of how a request moves through this app — upload → taxonomy resolution →
approval → conversion → review → verify/archive — plus authorization and the document data model.
Kept in its own file since `README.md` is already long; linked from there. For the
Markdown/OCR/structure conversion pipeline specifically (Pass 1/Pass 0/splice/auto-OCR-trigger in
full detail), see the diagram in `OCR_RESEARCH.md` — not duplicated here, only referenced.

## 1. Document lifecycle (status state machine)

```mermaid
stateDiagram-v2
    [*] --> pending_approval: upload, context/user requires approval
    [*] --> uploaded: upload, no approval required
    pending_approval --> uploaded: ApprovalController::approve()
    pending_approval --> rejected: ApprovalController::reject()
    rejected --> pending_approval: ApprovalController::resubmit() (uploader only)
    uploaded --> processing: DocumentController::convert()
    processing --> review: ConvertDocumentToMarkdown — good text layer
    processing --> ocr_pending: ConvertDocumentToMarkdown — text layer unreadable, auto-dispatches RunOcrExtraction (M34)
    processing --> failed: ConvertDocumentToMarkdown throws
    review --> ocr_pending: DocumentController::convertOcr() — reviewer manually re-runs OCR
    ocr_pending --> review: RunOcrExtraction completes
    ocr_pending --> failed: RunOcrExtraction throws
    failed --> processing: retry (Pipeline monitor "Retry" button)
    review --> verified: DocumentController::updateMarkdown(verify=true)
    uploaded --> verified: skip conversion, verify text-layer-only doc directly
    verified --> [*]
    uploaded --> archived: soft-delete (any status can be archived)
    review --> archived: soft-delete
    verified --> archived: soft-delete
    archived --> uploaded: DocumentController::restore() (status recalculated from what's on disk)
```

`visibility` (`public`/`authenticated`) is a separate, independent flag — not part of this state
machine — see `Document::$fillable` and `SECURITY.md`.

## 2. Upload — taxonomy resolution

Every upload resolves to exactly one of five contexts, which decides the vault path and the
document's foreign keys. `DocumentController::store()`:

```mermaid
flowchart TD
    U[POST /documents] --> CTX{Which ID was submitted?}
    CTX -->|rule_set_id| RS["Rule-set doc\nvault: rules/{rule_set_slug}/\n(Acts/Rules AND Policies — same branch)"]
    CTX -->|folder_id, no division| FS["Section-folder doc\nvault: {section_slug}/folders/{folder_slug}/"]
    CTX -->|folder_id + division| FD["Division-folder doc\nvault: {section_slug}/divisions/{division_slug}/folders/{folder_slug}/"]
    CTX -->|division_id| DV["Division doc\nvault: {section_slug}/divisions/{division_slug}/"]
    CTX -->|section_id only| SD["Direct section doc\nvault: {section_slug}/"]

    RS --> APR{"user->shouldRequireApproval(context)?\n(uploads_require_approval flag OR context.requires_approval)"}
    FS --> APR
    FD --> APR
    DV --> APR
    SD --> APR

    APR -->|yes| PA["status = pending_approval\nhidden from all browse views\n(Document::publishable() scope)"]
    APR -->|no| UP["status = uploaded\nimmediately visible per visibility flag"]
```

Each branch also picks a distinct `Document::uniqueSlugFor*()` method (`ForRuleSet`/`ForFolder`/
`ForDivision`/`ForSection`) so slug collisions are scoped to the right parent, not globally.

## 3. Maker-checker approval flow

```mermaid
flowchart LR
    subgraph Maker
        M1[Bulk operator uploads] --> M2["status: pending_approval\n(hidden from public/browse)"]
    end
    subgraph Checker
        C1["GET /approvals\n(Pending / Rejected / My Submissions tabs)"] --> C2{Decision}
        C2 -->|approve| C3["status: uploaded\nDocumentStatusHistory logged"]
        C2 -->|reject + reason| C4["status: rejected\nreason stored"]
        C2 -->|reclassify| C5["move to correct section/division/rule_set\nwithout re-upload, stays pending_approval"]
    end
    M2 --> C1
    C4 --> R1["Uploader: POST .../resubmit"] --> M2
```

Approval scope follows the org hierarchy (section/department/global) — a checker only sees
submissions within their own scope, enforced in `ApprovalController::index()`'s query, not just
the UI.

## 4. Authorization — who can do what

```mermaid
flowchart TD
    R[Any route] --> A{Guest or authenticated?}
    A -->|guest| G["Public routes only\n(index/show/pdf where visibility=public)\n403 on visibility=authenticated docs"]
    A -->|authenticated| B{Admin?}
    B -->|yes| ALL[Full access — bypasses every privilege/scope check]
    B -->|no| C{Which action?}
    C -->|upload/delete| D["User::canUploadTo() / canDeleteFrom()\nchecked against department_id/section_id/division_id scope"]
    C -->|create/edit/destroy dept-sections-divisions-folders| E["Per-controller authorizeManage() helper\n(SECURITY.md H-04/H-05 fix — was previously unchecked)"]
    C -->|convert/OCR/structure/markdown edit on a document| F["canManageDocument():\nadmin, OR (ruleSet.kind===policy AND user.canManagePolicy())\n— non-policy documents: admin only"]
    C -->|approve/reject/reclassify| Gp["documents.approve privilege or admin,\nscoped to approver's own org boundary"]
    C -->|convert-status (poll)| H["Auth only — no scope check\n(SECURITY.md L-04, open low-severity gap)"]
```

## 5. Component map

```mermaid
flowchart TD
    Blade["Blade views\n(documents/show, pipeline, bulk-upload,\napprovals/index, admin/*)"] --> Ctrl
    Ctrl["Controllers\nDocumentController · RuleSetController · ApprovalController\nDepartmentController/SectionController/DivisionController/FolderController"] --> DB[(MariaDB\ndepartments/sections/divisions/\nfolders/rule_sets/documents/\ndocument_status_histories/users)]
    Ctrl --> Jobs["Queue jobs (ShouldQueue, DB driver, single worker)"]
    Jobs --> CDM["ConvertDocumentToMarkdown\n(Pass 1 text-layer, Pass 0 Docling, splice)"]
    Jobs --> ROE["RunOcrExtraction\n(rasterize + chosen OCR engine + splice)"]
    CDM --> Py["Python venvs\nmarkitdown/pdfminer · Docling · Tesseract/EasyOCR/PaddleOCR/Surya"]
    ROE --> Py
    Py --> Disk["public disk\ndocument_vault/.../{slug}.pdf + .md + .pre-ocr.md + .structure.json"]
    CDM -.auto-dispatch when text unreadable (M34).-> ROE
    Ctrl --> ActivityLog["LogMutation middleware -> activity_logs table\n(every authenticated mutation, non-fatal)"]
```

See `OCR_RESEARCH.md` for the conversion pipeline's own detailed flowchart (Pass ordering,
quality checks, splice logic, auto-OCR-trigger) — this file stops at the component-boundary
level to avoid duplicating that diagram.

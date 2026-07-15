# Roadmap — pdf-markdown-pipeline

**Target audience:** UP State Data Centre (SDC) / NIC auditors and senior departmental stakeholders.  
**Purpose:** Document the forward trajectory of enterprise-grade features and security enhancements planned for the pdf-markdown-pipeline. Items marked ✅ are implemented; all others represent committed design decisions that have been architecturally scoped and are ready to enter the development queue.

---

## Phase 1 — Advanced Security & SDC/NIC Compliance

### 1.1 Mandatory Two-Factor Authentication (2FA) & Concurrent Session Blocking

**Rationale:** CERT-In guidelines (2022) and NIC's application security checklist mandate strong multi-factor authentication for government web portals that handle administrative documents or personally identifiable information. A single-factor password — even with the existing brute-force rate limiting and strong-password policy — is insufficient for an application that controls access to sensitive departmental GOs, circulars, and legal orders.

**Planned approach:**

- **TOTP via Laravel Fortify's built-in 2FA scaffold** — No additional Composer dependency. Fortify ships with `TwoFactorAuthenticationController`, `ConfirmableTwoFactorAuthentication`, and a recovery-codes mechanism. Enabling `Features::twoFactorAuthentication(confirmPassword: true)` in `config/fortify.php` activates the full TOTP flow with a QR-code enrollment page. The `two_factor_secret` and `two_factor_recovery_codes` columns (already present in the `users` table migration from `HasTwoFactorAuthentication`) will be activated.
- **Admin-enforced 2FA** — A new `Require2FA` middleware will be added to all authenticated routes. Any user without 2FA enrolled will be redirected to `/user/two-factor-authentication` (the enrollment page) on every request until setup is complete. Admins are not exempt — this prevents a privileged account from bypassing the policy.
- **Concurrent session blocking via `Auth::logoutOtherDevices()`** — On each successful login, call `Auth::logoutOtherDevices($password)` to invalidate all existing sessions for that user. This ensures a government officer's account cannot be simultaneously active on a home device and a shared office workstation — a requirement for a pristine, non-repudiable audit trail. The `activity_logs` table (already in place) will record each session termination event with the IP and user agent of both the new login and the invalidated sessions.
- **Session inventory page** — A new `/profile/sessions` view will list all active sessions (IP, user agent, last activity) and allow the user to explicitly invalidate any of them, consistent with the pattern established by Laravel Jetstream.

---

### 1.2 Anti-Virus (AV) Pipeline Integration

**Rationale:** Every uploaded file — regardless of declared MIME type — must be scanned for malware before being committed to the vault or queued for text extraction. A weaponized PDF exploiting a reader vulnerability, or an Office document with embedded macros, could compromise the operator's workstation or the extraction pipeline. This is a mandatory control under NIC's application hosting policy for government portals.

**Planned approach:**

- **ClamAV daemon on the application server** — ClamAV (`clamd`) is an open-source, signature-based antivirus engine that is available in all major Linux distribution repositories (RHEL: `clamav-daemon`, Debian: `clamav-daemon`). No cloud or third-party AV API is needed, consistent with the project's 100% on-premise mandate.
- **`ScanDocumentForViruses` queued job** — After a document is uploaded and the DB record is created (`status = 'uploaded'`), the existing processing queue will dispatch this job *before* the `ExtractDocumentText` job. The job will call `clamd` via the `Socket` or `TCP` interface (using a thin PHP wrapper — the `xenolope/quahog` package, for instance) and pipe the file bytes for inspection.
- **Status transitions:**
  - Clean scan → status advances to `processing` → `ExtractDocumentText` job is dispatched normally.
  - Infected / suspicious → status set to `failed`; a `DocumentStatusHistory` row is written with `to_status = 'quarantined'`, `note` = ClamAV's signature name; the file is moved to a private quarantine directory (`storage/app/private/quarantine/`); an admin flash notification and an activity log entry are created. The file is never exposed to the extraction pipeline.
- **ClamAV signature updates** — A daily cron (via `php artisan schedule:run`) will invoke `freshclam` to keep the virus database current without manual intervention.
- **Fail-safe behaviour** — If the ClamAV daemon is unreachable (daemon restart, etc.), the job will fail and be retried (Laravel queue retry mechanism). After exhausting retries, the document status is set to `failed` with a `note` indicating an AV scan could not be completed. The document is never silently passed through without a scan result.

---

### 1.3 Dynamic PDF Watermarking (Anti-Leak Protocol)

**Rationale:** Documents marked `visibility = authenticated` are internal-use-only — departmental proceedings, draft policies, internal circulars. While the application already restricts access to logged-in users, once a PDF is downloaded it is indistinguishable from a public document and can be forwarded without attribution. Dynamic watermarking creates a forensic chain of custody: every download is stamped with the downloading user's identity, making leaks traceable and acting as a strong deterrent.

**Planned approach:**

- **`setasign/fpdi` + `tecnickcom/tcpdf` Composer packages** — FPDI allows importing an existing PDF page-by-page and rendering it onto a new PDF canvas. TCPDF provides the rendering engine. This combination runs entirely on-premise with no external API calls.
- **`WatermarkedPdfResponse` action class** — For any `GET …/pdf` route where the document's `visibility === 'authenticated'`, the controller will delegate to this action instead of streaming the file directly via `Storage::disk()->response()`. The action will:
  1. Open the source PDF from the private or public disk via FPDI.
  2. For each page, render a diagonal semi-transparent watermark stamp containing: `Downloaded by: {user.name} ({user.username}) | {IP address} | {timestamp}`.
  3. Stream the watermarked PDF directly to the browser (never written to disk) with `Content-Disposition: inline` and `Cache-Control: no-store, no-cache` to prevent browser caching of the stamped copy.
- **Public documents are not watermarked** — `visibility = public` documents continue to stream directly from disk via `Storage::disk()->response()`, preserving performance and shareability.
- **Activity log integration** — Every watermarked PDF stream will write an `activity_logs` entry with `action = 'documents.pdf.watermarked'`, IP, user agent, and document ID, creating an immutable server-side record of every authenticated download event.
- **Stamp position and opacity** — The watermark will be rendered diagonally across the centre of each page at 30% opacity using a red sans-serif font, striking enough to be visible in screenshots or phone photographs of the screen.

---

## Phase 2 — High-Value Bureaucratic Workflows

### 2.1 Maker-Checker (E-File Approval) Workflow ✅ Implemented (2026-06-26)

**Status:** Fully implemented. The design below reflects what was actually built.

**Rationale:** Government document workflows follow the "Maker-Checker" principle — a document uploaded by a junior operator must be reviewed by a designated officer before it is publicly visible. This satisfies the UP Excise Department's internal SOP and provides a clear audit trail for each approval decision.

**What was built:**

- **Two independent triggers for `pending_approval`:**
  1. `users.uploads_require_approval = true` — all uploads by this user are held (bulk operator mode)
  2. `sections.requires_approval = true` / `divisions.requires_approval = true` / `rule_sets.requires_approval = true` — any upload to this context is held regardless of who uploads it
- **Status pipeline update:** `pending_approval` and `rejected` inserted before the normal extraction pipeline. Approved docs proceed as `uploaded`. Status flow: `uploaded (direct) | pending_approval → (approve) → uploaded → processing → review → verified | (reject) → rejected → (resubmit) → pending_approval`
- **`documents.approve` privilege** — operators designated as approvers. Approval scope equals the user's upload scope (section.head → their section; department.head → their department; organization.head / admin → anywhere).
- **Approval queue at `GET /approvals`** — Three tabs: Pending Approval, Rejected, My Submissions. Slide-over drawer with PDF preview, metadata strip, and action buttons. All authenticated users see the queue; badge counts differ by role.
- **Actions:**
  - `POST /approvals/{id}/approve` → status `pending_approval → uploaded`, optional note
  - `POST /approvals/{id}/reject` → status `pending_approval → rejected`, mandatory reason
  - `POST /approvals/{id}/reclassify` → moves document to correct context (new section/division/rule_set), physically moves files on public disk, optional approve-in-same-step checkbox
  - `POST /approvals/{id}/resubmit` → status `rejected → pending_approval` (uploader's own doc only)
- **Bulk approve / bulk reject** — via checkbox select on Pending tab, Swal2 confirmation with shared reason.
- **`->publishable()` scope** — `whereNotIn('status', ['pending_approval', 'rejected'])` applied to all regular document list queries (section/division/rule_set show pages, search, dashboard). Pending and rejected docs are invisible outside the approvals queue.
- **Reclassification** uses `Storage::disk('public')->move()` (same-disk atomic rename). Cross-department moves allowed for org.head/admin; department.head limited to own department.
- **Approval routes use numeric `{id}`** — reclassification changes the document's context mid-flow, making slug-based routing stale.
- **`requires_approval` toggle** on section/division/rule_set edit forms — gated by appropriate privilege (section.head or above).
- **`uploads_require_approval` toggle** on user create/edit forms — admin-only.

---

### 2.2 Full-Text Search Engine Integration (Meilisearch + Laravel Scout)

**Rationale:** The current SQL `LIKE %q%` search operates on document titles, section names, and rule set names only — it cannot search inside the extracted Markdown content of thousands of documents. For a government document vault that contains dense legal text (Acts, Rules, GOs with specific clause references), users need to search for terms like "धारा 34(क)" or "license cancellation fee" and retrieve the precise document and page. LIKE queries on a `TEXT` column also become prohibitively slow as the corpus grows.

**Planned approach:**

- **Meilisearch** — An open-source, self-hosted, Rust-based search engine with first-class support for Hindi (Devanagari Unicode tokenization), near-instant indexing, and typo tolerance. Runs as a single binary with no JVM dependency, suitable for on-premise SDC deployment. Available as a Linux amd64 binary or via a Docker container (`getmeili/meilisearch`).
- **Laravel Scout** — The `laravel/scout` package provides a driver-agnostic search interface. The `meilisearch/meilisearch-laravel-scout` driver handles queue-based indexing. The `Document` model will implement `Searchable` and define a `toSearchableArray()` method that indexes: `title`, `document_type`, `markdown_path` content (full text), `section.name`, `rule_set.name`, `metadata.go_number`, and `metadata.effective_year`.
- **Index configuration** — Meilisearch will be configured with `filterableAttributes` (`department_id`, `visibility`, `status`, `document_type`) so Scout queries can scope results to the authenticated user's visibility and the standard `visibility = public` gate for guests.
- **Devanagari tokenization** — Meilisearch's default Unicode segmenter correctly handles Devanagari script without additional configuration. Combining marks (`\p{M}`) are handled as part of the base character during tokenization.
- **Fallback strategy** — The `SearchController` will be refactored to use Scout's `Document::search($q)->where(...)` API. The existing SQL LIKE fallback will be retained for environments where Meilisearch is not running (guarded by a `SCOUT_DRIVER=database` environment variable), so the application degrades gracefully rather than erroring.
- **Markdown content indexing** — The `ExtractDocumentText` queue job (the existing markitdown/OCR job, not yet built) will trigger a Scout re-index after writing the `markdown_path`. This ensures full-text search of document content is available as soon as extraction completes.

---

### 2.3 Document Versioning (Non-Destructive File Corrections)

**Rationale:** A scanned document is sometimes uploaded with a rotation error, OCR artefacts, or as a placeholder draft before the final signed copy is available. The current workflow requires archiving the original document and uploading a new one — which breaks any external links or bookmarks to the original URL, and loses the continuity between the original upload and the corrected file in the audit trail. A "Replace File" action preserves the vault URL while maintaining a complete history of which file was active at each point in time.

**Planned approach:**

- **`document_versions` table** — A new append-only table storing the history of file replacements for a given document:

  | Column | Type | Notes |
  |---|---|---|
  | `id` | bigint PK | |
  | `document_id` | FK → documents | `cascadeOnDelete` |
  | `replaced_by_user_id` | FK → users nullable | `nullOnDelete` |
  | `old_pdf_path` | string | Vault path of the file being replaced |
  | `old_pdf_sha256` | string(64) | SHA-256 hash of the old file — immutable evidence |
  | `old_markdown_path` | string nullable | |
  | `replacement_reason` | text | Required (5–500 chars) — auditor's note |
  | `created_at` | timestamp | Append-only |

- **"Replace File" action** — A new button on the `documents/show` page (visible to users with `documents.edit` privilege), guarded by SweetAlert2 confirmation. The action opens a modal with: a file upload field (same MIME validation as the original upload), a mandatory replacement reason textarea, and a preview of the hash that will be recorded.
- **Controller flow** (`POST /documents/{level}/{dept}/{section}/{doc}/replace`) — Inside a `DB::transaction()`:
  1. Compute and record the SHA-256 hash of the existing file before moving it.
  2. Move the existing PDF to `storage/app/private/document_versions/{document_id}/{old_slug}_{timestamp}.pdf` (private disk — not web-accessible).
  3. Store the new file at the document's existing `original_pdf_path` on the public disk (vault URL unchanged).
  4. Nullify `markdown_path` and reset `status = 'uploaded'` to re-queue the extraction pipeline.
  5. Insert a `document_versions` row with the old path, hash, and reason.
  6. Insert a `DocumentStatusHistory` row (`to_status = 'replaced'`, `note` = reason, `actor_id`).
- **Version history sidebar** — The `documents/show` page will display the version history (date, actor, reason) in the sidebar alongside the existing status history, so auditors have a complete picture of the document's lifecycle.
- **URL stability guarantee** — Because the new file is stored at the identical `original_pdf_path`, all existing `/pdf` route links, bookmarks, and external references continue to work without redirection.

---

### 2.4 Text Extraction Pipeline — Markdown Conversion + Hindi/English OCR

**Status:** ✅ Implemented (2026-07-13, M30 in `summary.md`). The plan below was written before
implementation started and described an auto-OCR-fallback design; the design changed during
build after concrete testing (see "What actually shipped, and why it differs" below). Kept
here, struck through where superseded, so the reasoning trail isn't lost.

**Rationale (still holds):** Real-world intake for this vault is dominated by scanned or
photographed paper — GOs run through a departmental scanner, mobile-camera captures of physical
files, old "print to PDF from a scan" workflows. These almost never carry a selectable text
layer. Native-text PDFs (drafted digitally) do exist but are the minority case in practice. The
pipeline tries the cheap text-layer extraction first since it costs nothing to attempt and is
strictly better when it works — but see below for why OCR ended up as a strictly human-gated
second step rather than an automatic fallback.

**What actually shipped, and why it differs from the original plan:**

The original plan below called for OCR to auto-run whenever the text-layer pass looked
low-quality (`FAIL → status → ocr_pending → rasterize → tesseract → write`, all inside one job,
no human in the loop). This was built, then reverted, after two concrete problems surfaced in
testing — not a preference change, a correctness/ops finding:

1. With a single serial `queue:work` process, one slow OCR job (minutes, for a multi-page scan)
   blocked every other queued document behind it. This is what caused a real "stuck on
   converting" complaint — the queue wasn't broken, it was just serially bottlenecked on OCR
   nobody had asked for yet.
2. Running OCR on an already-good, native-text PDF **actively corrupts correct text**. Verified
   by testing Tesseract against `Haryana Excise Policy 2025-27.pdf` page 1 (already handled
   cleanly by the text-layer pass): "150 meters" was silently changed to "50 meters" in **four
   separate places**, plus `21 out of 22` → `2l out of 22` and dropped leading digits in section
   numbers. Automatic OCR fallback would have silently degraded documents that didn't need it.

**Revised, shipped design — "man in the middle":** every upload's text-layer pass always runs
first (button-triggered, not automatic on upload — same as originally planned) and its result is
**always** shown to a reviewer, flagged if low-quality rather than auto-escalated to OCR. A human
decides whether to accept it or explicitly trigger OCR from inside the Compare & Verify modal.
OCR never runs unless a person asks for it, for a specific document, every time.

```
Button click → POST …/convert → ConvertDocumentToMarkdown job → status: processing
    1. Run pdfminer.six (via resources/python/pdf_structure_extractor.py --mode pdf) —
       NOT markitdown's own extract_text(), which is plain-text only
    2. Quality check: (cid:\d+) glyph-ID tokens (>5 ⇒ bad — unmapped legacy font) OR
       near-empty char count relative to page count (⇒ bad — scanned/image PDF)
    3. Write .md either way, status → review, metadata.needs_ocr_review = true/false
       (a bad result is flagged to the reviewer, never silently discarded)
    Uncaught exception → status → failed

[If flagged, or the reviewer just wants to try] → Compare & Verify modal →
"Run OCR-Based Extraction" → POST …/convert-ocr → RunOcrExtraction job → status: ocr_pending
    1. pdftoppm -r 300 rasterize to PNG, private-disk temp dir
    2. tesseract per page, lang = hin+eng, hocr mode (per-line x_size for heading detection)
    3. Join, write .md, status → review, metadata.extraction_method = 'ocr'
    4. Page images deleted in a `finally` block regardless of outcome — never retained
    Uncaught exception → status → failed
```

**Retry** — when `status = 'failed'`, the button relabels to **Retry**. Both `convert()` and
`convertOcr()` are gated `isAdmin()` directly in the controller (same pattern as other
admin-only document mutations elsewhere in this codebase).

**Toolchain (installed, this machine — versions in `DEPLOY.md`):**
```bash
composer require innobrain/markitdown erusev/parsedown
php artisan markitdown:install        # provisions its own venv, no manual Python bridge
brew install tesseract tesseract-lang # adds hin.traineddata + eng.traineddata
brew install poppler                  # pdftoppm — PDF page rasterization ahead of OCR
```

**Compare & Verify modal (`documents/show`) — this shipped, contrary to the original plan's
"no split-pane review editor in this pass":** split-pane PDF-vs-Markdown review, editable, with
Save & Verify, one-time Discard Draft (resets to pre-conversion state), and the Run OCR trigger
living inside the modal rather than as a second page banner. Full detail in `CLAUDE.md`'s
"Text Extraction & Markdown Conversion Pipeline" section — not duplicated here.

**Also shipped, not in the original plan at all:** a Bulk Upload page (`/documents/bulk-upload`)
and a Conversion Pipeline monitor page (`/documents/pipeline`) — both added because reviewing
one document at a time didn't scale to onboarding a legacy document backlog.

**Follow-up research, no pipeline change:** two on-prem OCR alternatives to Tesseract
(PaddleOCR, EasyOCR) were evaluated against the exact document class causing accuracy
complaints. PaddleOCR rejected (resource exhaustion + a Paddle-inference version crash).
EasyOCR showed a real accuracy improvement on the specific failure modes (correct Devanagari
numerals, no conjunct artifacts, no hallucinated English substitutions) at a workable but heavy
memory cost — not integrated, pending a multi-page test and explicit sign-off given the new
PyTorch dependency weight. Full write-up: `OCR_RESEARCH.md`.

No schema migration was needed — `metadata.extraction_method`/`needs_ocr_review` live in the
existing JSON column; `status` reuses values already named in `CLAUDE.md`'s enum.

---

### 2.5 Structure Detection (Docling) ✅ Phase 1 implemented (2026-07-15)

**Status:** Structure-only phase implemented. The geometric merge phase below is the next increment, not yet built.

**Rationale:** Section 2.4's pipeline extracts characters correctly but loses page *structure* — tables collapse into run-on paragraphs, headings disappear into body text — even when the underlying character recognition (Tesseract/EasyOCR/PaddleOCR) is accurate. Evaluated hands-on against real other-state excise policy PDFs (both a text-layer document and a 54-page scan): [Docling](https://github.com/docling-project/docling) (IBM, Apache 2.0) already implements the layout-detection → table-structure → structured-Markdown pattern this problem calls for, using a purpose-built vision model rather than a general LLM — confirmed fast (~3s/page) and no Ollama/LLM needed anywhere in this pipeline. Full write-up, including the RapidOCR-defaults-to-Chinese bug found and the `--force-ocr` impracticality finding: `STRUCTURE_RESEARCH.md`.

**What shipped (Phase 1):**
- Docling runs automatically as Pass 0 of every `ConvertDocumentToMarkdown` job — headings and table cells (with bounding boxes) detected and trimmed into a compact `{slug}.structure.json` sibling file on the `public` disk, never in the database.
- Always uses the default engine (Tesseract, of the three — EasyOCR/RapidOCR — Docling can call directly; it cannot use Paddle or Surya) for Docling's own scanned-page OCR internally, discarded after structure detection — the main text still comes from section 2.4's pipeline. An engine-choice dropdown was tried next to the Convert button and removed on review: it broke the established pattern of only surfacing an engine choice once there's a result to react to (see `config/docling.php` if the default ever needs changing).
- Informational only: shown as a small strip on `documents/show` ("Structure: N headings, M tables"), viewable as raw JSON via `GET /documents/{id}/structure`, discarded by `discardMarkdown()` alongside the Markdown draft.
- A real, pre-existing gap found independently during this evaluation: legacy non-Unicode Devanagari fonts (Kruti Dev, Chanakya, DevLys) produce readable-looking-but-wrong text that neither the `(cid:\d+)` nor char-count quality check catches — fixed by detecting the font *name* directly and forcing `needs_ocr_review`.

**Phase 2 (not yet built) — geometric merge:** align whichever OCR engine's word-level bounding boxes (Tesseract/EasyOCR/PaddleOCR/Surya) into Docling's detected region/table boxes, to actually reconstruct structured Markdown for scanned documents instead of relying solely on `pdf_structure_extractor.py`'s own row/column-clustering heuristics. Deferred deliberately until real structure output has been reviewed against enough real documents in the UI to know if the heuristic-only path is actually insufficient in practice.

---

## Cross-Cutting Concerns

### CSP Nonce Migration (Post-CDN)

The current `Content-Security-Policy` header requires `unsafe-inline` to support the Tailwind Play CDN and inline `<script>` blocks in Blade views. This is a known temporary concession documented in `SECURITY.md`. When the project migrates to a compiled Tailwind build (removing the Play CDN), all inline scripts will be refactored to use a per-request CSP nonce (generated in `SecurityHeaders` middleware and passed to Blade via a view composer). This will allow `unsafe-inline` to be removed entirely, bringing the CSP to a Level 2 compliant policy.

### `activity_logs` Retention Policy

The `activity_logs` table is append-only with no TTL. A scheduled `php artisan logs:prune` command will be introduced with a configurable retention window (default: 2 years, matching the UP government document retention schedule). Rows older than the retention window will be exported to a date-stamped CSV in `storage/app/private/log_archives/` before hard deletion. This prevents unbounded table growth without losing the legal audit trail.

### HTML Purifier for Markdown Output

The current Parsedown post-processor uses a targeted `preg_replace` to strip `javascript:`, `data:`, and `vbscript:` URI schemes (M-03 mitigation). When text extraction is in active daily use, this will be replaced with `ezyang/htmlpurifier` configured with an explicit allowlist (`URI.AllowedSchemes = ['http', 'https', 'mailto']`), providing a defence-in-depth sanitization layer that covers URI schemes not anticipated at the time of the initial fix.

---

*Roadmap authored: 2026-06-25. Last updated: 2026-07-15 (2.5 Structure Detection/Docling added, Phase 1 marked implemented; see `summary.md` and `STRUCTURE_RESEARCH.md`). All items subject to prioritisation based on NIC audit outcomes and departmental SOP review.*

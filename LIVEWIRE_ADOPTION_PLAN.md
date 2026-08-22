# Adopting Livewire — scoped pilot, not a stack swap

## Context

This app already evaluated and rejected Livewire once (2026-07-26, documented in `claude.md`'s
"Frontend interactivity: Alpine, not Livewire" section) after two real bugs surfaced while it was
installed as a Laravel Pulse dependency: a CSP `unsafe-eval`/Alpine conflict, and a Livewire
`v4.3.3` `unserialize()` bug requiring a downgrade pin. Pulse itself was removed the same week for
unrelated compatibility problems and replaced with a custom `documents.pipeline.health` dashboard.

Revisiting now because the two original blockers no longer apply the way they did:

- **CSP**: `'unsafe-eval'` is already granted in `SecurityHeaders`'s `script-src` (added for
  Alpine's own directive-expression evaluation, independent of this decision) and `connect-src
  'self'` already covers Livewire's same-origin `/livewire/update` AJAX endpoint. No CSP change
  needed at all — verified by reading `app/Http/Middleware/SecurityHeaders.php:39,51` directly.
- **Deployment model**: this app now runs the same way `~/Sites/excise-budget-tracker` does
  (self-hosted behind a tunnel, not the shared/managed hosting context the original CSP hardening
  was partly written for), and that sibling project runs Livewire v4.3 in production without any
  of the earlier issues resurfacing.
- **The Pulse-era `unserialize()` pin** was specific to `v4.3.3`; `excise-budget-tracker` runs
  `^4.3` cleanly today with no such workaround needed.

The real, current pain point (per this conversation) is the same class of thing that prompted the
original Alpine adoption: several pages hand-roll `setInterval`/`fetch()` polling loops and manual
DOM patching to keep document/conversion status current without a full page reload — most visibly
`documents/pipeline.blade.php` (`fetch` polling `.../convert-status` per row,
`resources/views/documents/pipeline.blade.php:145-171`) and the Compare & Verify conversion-status
widget on `documents/show.blade.php` (`startConversionPolling()`,
`resources/views/documents/show.blade.php:1389-1425`). Livewire's `wire:poll` + server-rendered
partial re-render replaces that hand-rolled pattern directly, and `excise-budget-tracker`'s
`TransactionForm` single-file component (`resources/views/components/transaction-form.blade.php`)
is a solid, already-working reference for this app's own conventions (single-file `new class extends
Component`, `#[Computed]` properties, `wire:model.live`, `$this->validateOnly()` in an `updated()`
hook).

**Intended outcome of this plan:** get Livewire cleanly coexisting with this app's existing Alpine
usage, prove the pattern on one genuinely poll-shaped page (Pipeline Monitor), and leave the much
larger, higher-risk work (site-wide `wire:navigate` SPA navigation touching every view's inline
`<script>` blocks) as an explicit, separate future decision — not bundled into this first pass.

## Approach

### Phase 0 — Install & coexistence (infrastructure only, no page behavior changes)

1. `composer require livewire/livewire:^4.3` — matches `excise-budget-tracker`'s pinned major/minor,
   avoiding the known `v4.3.3` `unserialize()` regression by staying on the range that project has
   already run stably in production.
2. **Remove the standalone Alpine include, don't dual-load it.** `excise-budget-tracker` was
   architected from the start with *no* separate Alpine install (confirmed: no
   `public/vendor/alpinejs/` equivalent exists there, no `alpinejs` entry in its `composer.json`/
   `package.json`) — every `x-data` usage, including one bare (non-Livewire-component) view
   (`resources/views/auth/otp.blade.php`), rides Livewire's own bundled Alpine build via
   `@livewireScripts`. This app must do the same: delete the `<script defer src=".../alpinejs/alpine.min.js">`
   tag from `resources/views/components/head.blade.php`, add `@livewireStyles` there instead
   (same position `excise-budget-tracker` uses, `head.blade.php:94`), and add `@livewireScripts`
   right before `</body>` in `resources/views/components/layout.blade.php` (mirroring
   `excise-budget-tracker/resources/views/components/layout.blade.php:240`). Loading both a
   standalone Alpine *and* Livewire's bundled copy causes "Detected multiple instances of Alpine
   running" and double-init bugs — this app must pick one, and Livewire's bundled copy is the only
   option once Livewire is installed at all.
3. Delete the now-unused `public/vendor/alpinejs/` directory once step 2 is confirmed working (keep
   it until manual verification passes, in case rollback is needed).
4. **Verify existing Alpine usage still works unchanged** under Livewire's bundled Alpine — same
   Alpine core API, no rewrite expected, but confirm both call sites live-render correctly:
   - `documents.pipeline.health`'s `pipelineHealthState()` component (polls its own `?format=json`
     endpoint)
   - The share dropdown on `documents/show.blade.php` (`x-data="{ shareOpen, copied }"`)
5. No CSP change (see Context above) — but update the explanatory comment in `SecurityHeaders.php`
   to note `unsafe-eval` is now also required by Livewire's bundled Alpine, not just the
   already-removed standalone copy, so a future reader doesn't think it's safe to drop.
6. No Vite/npm/build-step introduced — Livewire v3+ serves its JS/CSS assets via its own Laravel
   route (`/livewire/livewire.js`), not a compiled asset pipeline, so this stays consistent with
   the app's existing "no Node, no build step" principle (`claude.md`'s tech-stack table).

### Phase 1 — Pilot: Pipeline Monitor as a Livewire component

Chosen as the first real conversion because it's a monitoring/list view — the single best-fit shape
for `wire:poll` — and is decoupled from the Compare & Verify page's much larger, higher-stakes JS
surface (1400+ lines covering convert/OCR/structure/markdown-edit/discard/revert/delete/share).
Lowest blast radius for validating the whole approach.

1. New Livewire single-file component, `resources/views/components/pipeline-monitor.blade.php`
   (matches `excise-budget-tracker`'s naming/location convention for single-file components),
   reusing `DocumentController::pipeline()`'s existing query almost verbatim
   (`app/Http/Controllers/DocumentController.php:65-86`: status-tab filtering, `$counts` via
   `selectRaw('status, count(*) as c')`, paginated `Document::with([...])` eager-loads).
2. `wire:poll.5s` (or whatever interval matches the current JS polling cadence — check
   `pipeline.blade.php`'s existing interval before picking one) on the component root, replacing
   the hand-rolled `setInterval` + `fetch('.../convert-status')` loop
   (`documents/pipeline.blade.php:167-171`) entirely.
3. Convert/Retry row actions become `wire:click="convert($documentId)"` methods on the component
   (same authorization check the controller's `convert()` action already does — reuse, don't
   duplicate), replacing the manual `fetch(btn.dataset.convertUrl, ...)` call
   (`documents/pipeline.blade.php:145`).
4. Status tabs stay plain Blade links with `wire:navigate` **only if** Phase 2 below is later
   adopted — for this phase, leave them as normal full-page links pointing at query-string variants
   the component reads from its `mount()`, so the rest of the app's navigation is untouched.
5. `DocumentController::pipeline()`'s route/controller method can either delegate straight to
   rendering this component, or be replaced by a thin route closure — decide during implementation
   once the component's `mount()` signature is settled; not a decision that needs to be locked in
   ahead of time.

### Phase 2 — Only after Phase 1 is verified working in real use

Not part of this pass — listed here so the follow-on scope is visible, not so it gets built now:

- Approval Queue (`approvals/index.blade.php`) — its approve/reject/reclassify/bulk actions are
  already isolated `fetch()`-based AJAX calls with manual DOM patching, a similar shape to what
  Phase 1 replaces.
- A small, **embedded** Livewire component for just the Compare & Verify page's conversion-status/
  elapsed-timer widget (not a rewrite of the whole 1400-line page) — directly answers "no
  hardwiring markdown status" for the page the user actually named, without touching the
  surrounding OCR/edit/discard/share JS that already works.
- Site-wide `wire:navigate` (the actual "no page reload, SPA behavior" part of the request) is
  deliberately **not** in Phase 1 or 2. It requires every existing view's per-page `<script>`/
  `@push('scripts')` block — and there are many, heavy ones (`documents/show.blade.php`,
  `sections/show.blade.php`, `bulk-upload.blade.php`, `quick_conversions/show.blade.php`,
  `approvals/index.blade.php`) — to be rewired from implicit once-per-load execution onto a
  `livewire:navigated` listener instead of `DOMContentLoaded`, exactly the migration
  `excise-budget-tracker/resources/views/components/layout.blade.php:195-198` had to do and left
  detailed comments about (including a dark-mode-flash bug it hit along the way). That is a
  site-wide, every-page-touched effort with real regression surface — worth doing once Livewire's
  coexistence and the poll-shaped-page pattern are both proven out, not bundled into the first
  install.

## Verification

- Phase 0: load every page that currently uses Alpine (`documents/pipeline.health`,
  `documents/show.blade.php`'s share dropdown) after the swap and confirm no
  "multiple instances of Alpine" console warning, and that existing `x-show`/`x-data` behavior is
  pixel-identical to before.
- Phase 1: open `/documents/pipeline`, upload/convert a real document in another tab, and confirm
  the row's status updates in place within one poll interval with no full page reload — the same
  outcome the current JS delivers, minus the hand-rolled polling code. Confirm Convert/Retry still
  respects the existing admin/`department.head` authorization check.
- Manual smoke-test pass (this project has no automated browser test suite): a real logged-in pass
  through Pipeline Monitor with an in-flight conversion, in both light and dark mode.

## Status (2026-08-13, `livewire-pilot` branch)

Phases 0 and 1 shipped as planned. Phase 2's listed scope (Approval Queue actions) shipped too,
plus two items not originally in Phase 2: Designation management and User management, both added
after the pilot proved out cleanly. Site-wide `wire:navigate` and the Compare & Verify embedded
status widget remain unbuilt, still deliberately out of scope.

Delivered, each with Pest feature-test coverage (`tests/Feature/*Test.php`) and verified live
against `docsrepo.exciseup.in` (this app's docroot is served directly from this checkout, so the
pilot branch has been running live throughout):
- **Pipeline Monitor** (`pipeline-monitor` component) — `wire:poll`-driven status list, bulk-select.
- **Approval Queue actions** (`approval-actions` component) — approve/reject/reclassify/resubmit/
  bulk, wired into the existing drawer/modal UI without touching it.
- **Designation management** (`designation-manager` component) — full CRUD, replacing separate
  create/edit pages with a modal.
- **User management** (`user-list` + `user-form` components) — full CRUD (index/create/edit).
- Login/OTP/password-reset were evaluated and explicitly declined — converting them would drop
  `throttle:login`/`throttle:two-factor`/`throttle:password-reset` middleware, since Livewire's
  update endpoint doesn't inherit page-route middleware. Left as plain Fortify-backed forms.

See `claude.md`'s "Frontend interactivity: Alpine and Livewire" section for the full writeup,
including the `SupportRedirects`/`RedirectResponse` `TypeError` bug this pilot surfaced (affects
any reused controller action typed `: RedirectResponse` when called from a Livewire component) and
the FormRequest-reuse pattern (`$class::createFrom(...)->validateResolved()`) used to keep
authorization/validation logic in one place rather than duplicating it into each component.

**User-observed gap (2026-08-13):** after the above shipped, regular page-to-page navigation
(sidebar links, breadcrumbs, list → edit page, etc.) still does a full browser reload — navbar
included — confirmed even with a hard refresh / incognito, so it isn't a cache artifact. This is
expected, not a regression: nothing above added `wire:navigate` to any `<a href>`. Only *actions
inside* a page (approve/reject, save, delete, poll) moved to AJAX. Site-wide SPA-style navigation
is Phase 3 below, not yet started.

### Phase 3 — Site-wide `wire:navigate` (not started)

The actual "no full page reload, SPA feel" behavior. Deliberately deferred out of every phase so
far because it's a different shape of risk than everything above: Phases 1–2 each touched one
isolated component's worth of surface, but this phase touches **every page's navigation chrome and
every page's inline `<script>`/`@push('scripts')` block**, all at once, because `wire:navigate`
swaps `<body>` content via AJAX and only re-runs `<head>` once — any script relying on
`DOMContentLoaded` firing per-navigation goes dead the first time a user clicks a `wire:navigate`
link to get there.

Not yet scoped in detail — the next session should start by reading `resources/views/components/
layout.blade.php` and `head.blade.php` in full, and every page with a nontrivial `@push('scripts')`
block (at minimum `documents/show.blade.php`, `sections/show.blade.php`, `bulk-upload.blade.php`,
`quick_conversions/show.blade.php`, `approvals/index.blade.php`, `admin/users/*`,
`admin/designations/*`), before deciding on a rollout order. `excise-budget-tracker`'s
`resources/views/components/layout.blade.php:195-198` already solved this same migration for a
sibling app on this same stack and left comments about a dark-mode-flash bug it hit — read that
file first as the reference implementation, not a blank-page design exercise.

## Sync status (2026-08-22) — `main` has moved 23 commits since this branch's last merge

`main` is the live branch (`docsrepo.exciseup.in` serves it directly from this checkout) and has
kept moving while this pilot sat idle. This branch's last merge from `main` was at `d47b591`
(`git merge-base main livewire-pilot` — still `d47b5916021c6af258e305d37f83698c709bb63f`). Since
then `main` has picked up 23 commits, none of them merged here yet. **This section is a map for
whoever resumes this branch — read it before merging, don't merge blind.** Nothing below has been
applied to `livewire-pilot`; this is documentation only, per instruction.

### 1. Auth/role model changed shape — read this first, it touches everything else

`c694388` (`fix(auth): split role=admin into system_admin/admin, add view-scoping`) restructured
`User`'s role model after this branch diverged: `role='admin'` used to be the one true-god-mode
bypass; now `role='system_admin'` is (`User::isAdmin()`), `role='admin'` is a new *scoped* org-admin
tier (`User::isOrgAdmin()`) that auto-grants a fixed privilege bundle
(`User::ORG_ADMIN_PRIVILEGES`) within its own department/section/division instead of bypassing
scope entirely. A new migration, `2026_08_19_140000_add_system_admin_to_users_role_enum.php`, adds
the enum value — **must run before anything below makes sense**. `app/Models/User.php` gained ~90
lines net (`isOrgAdmin()`, `ORG_ADMIN_PRIVILEGES`, `canView()`, `scopeViewableBy()` support) — diff
it in full (`git diff d47b591..main -- app/Models/User.php`) before touching `user-form.blade.php`
or `user-list.blade.php`, since both components read `role`/privilege state directly and were built
against the old two-tier (`admin`/`operator`/`viewer`) model. Check in particular whether
`user-form.blade.php`'s role `<select>` options and any `isAdmin()` calls in `user-list.blade.php`
need the same 4th option / `system_admin` vs `admin` distinction the Blade forms on `main` now have.

View-scoping itself (`Document::scopeViewableBy()`, `User::canView()`, applied across
`SectionController`, `DivisionController`, `FolderController`, `SearchController`,
`FrontendController`, `DownloadController`) is a large, mostly controller/model-level change with
no direct Livewire-component overlap — should merge cleanly, but re-run
`tests/Feature/DocumentViewScopeTest.php` (new on `main`, 150 lines) after merging to confirm.

### 2. Public/private visibility extended to Sections and Divisions

`b45d4a1` added a `visibility` column to `sections` and `divisions` (previously only `folders` had
one) — migration `2026_08_22_070843_add_visibility_to_sections_and_divisions_table.php`, plus
`Document::isPubliclyVisible()`/`scopePubliclyVisible()` now treat a document's containing
division/section as an additional ceiling, mirroring the existing folder ceiling. `750a388`
followed up enforcing the same ceiling as a hard ceiling (not just advisory). Straightforward model/
migration/controller change, no Livewire overlap — but `sections/create.blade.php`,
`sections/edit.blade.php`, `divisions/create.blade.php`, `divisions/edit.blade.php` all gained a
Visibility radio group; none of these were converted to Livewire in this pilot, so they merge as
plain Blade changes. **Except `sections/edit.blade.php` — see #4 below, do not merge it as-is.**

### 3. Designation/User admin forms — two real bugs fixed on `main`, likely still present here

Both bugs are structural (Blade partial + FormRequest), not tied to the plain-Blade-vs-Livewire
question, so they most likely reproduce in this branch's Livewire versions too:

- **`department_id`/`sort_order` empty-string-into-typed-column bug** (`ca5bd3e`) — Laravel's
  `nullable` rule lets `''` through without coercing it to `null`/`0`, so a blank "Generic
  department" dropdown threw a raw SQL error on save. Fixed via `prepareForValidation()` in
  `StoreDesignationRequest`/`UpdateDesignationRequest`/`StoreUserRequest`/`UpdateUserRequest` — pure
  FormRequest changes, apply directly regardless of which controller/component calls them.
  Confirmed `livewire-pilot`'s copies of these FormRequests predate the fix
  (`git diff d47b591..main -- app/Http/Requests/Admin/`).
- **`groupBy()` silently discarding privilege-slug keys** (`059d248`) — `_privilege_checkboxes.blade.php`
  groups `$privilegeLabels` with `collect(...)->groupBy(fn($v) => $v['group'])`, and Laravel's
  `Collection::groupBy()` **without** `$preserveKeys = true` re-indexes each sub-group to plain
  `0,1,2...` instead of keeping the privilege-slug keys (`documents.upload`, etc.). **Confirmed
  still present in `livewire-pilot`'s copy of this file** (`git show
  livewire-pilot:resources/views/admin/_privilege_checkboxes.blade.php` — missing the `true` second
  argument). This affects `wire:model`-bound checkboxes exactly the same way it affected plain
  `name="...[]"` ones — the checkbox `value="{{ $key }}"` comes from the same corrupted `$key`
  either way, so both `user-form.blade.php` and `designation-manager.blade.php` (both `@include`
  this same partial) are very likely still hitting the "every privilege checkbox submission
  silently rejected by validation" bug M86 found on `main`. **Recommend verifying this live on the
  pilot branch before assuming Designation/User privilege editing there actually works.** Fix is a
  one-argument change (`groupBy(fn($v) => $v['group'], true)`) — same partial, same fix, trivial to
  port; `main`'s copy also dropped the raw privilege-key label span under each checkbox and added a
  `$readonly` mode (for M88's new read-only user profile page, see #7) — decide whether the
  `$readonly` mode is worth porting when the profile page work happens here, not required for the
  groupBy fix itself.
- The Sort Order field was removed from Designation forms entirely on `main` (`d9dda75` —
  alphabetical-by-default was judged sufficient, no user-facing input needed). Cosmetic; port or
  skip independently of the two bugs above.

### 4. SERIOUS — nested `<form>` bug, confirmed still live on this branch (M89 on `main`)

`8387811` fixed a bug where `sections/edit.blade.php` and `department/edit.blade.php` nested a
"Delete" `<form>` inside the main "Save Changes" `<form>` — invalid HTML that browsers respond to by
merging both forms' hidden `_method` inputs into one (`DELETE` wins, since it's added last) and
closing the outer form early. Real-world impact on `main`: editing a Section's visibility and
clicking "Save Changes" **deleted the section instead**, with no confirm dialog (the confirm was on
the discarded inner form's `onsubmit`). Both accidentally-deleted sections were recovered (soft-delete
only, `SoftDeletes`), no data lost — see `summary.md`'s M89 entry on `main` for the full incident
writeup and root-cause explanation.

**Confirmed via `git show livewire-pilot:resources/views/sections/edit.blade.php` that this branch
has the exact same nested-form structure — the bug is live and unpatched here too**, dormant only
because this branch isn't the one being served. `department/edit.blade.php` here is presumably the
same (not yet individually re-checked on this branch, but it wasn't part of this pilot's scope
either, so no reason to expect it differs from `sections/edit.blade.php`'s pattern).
`admin/users/edit.blade.php`'s equivalent nesting bug (Resend-activation form nested in Save) does
**not** apply here — this branch's `user-form.blade.php` already uses a single `wire:submit` form
with `wire:click="resendActivation"` as a plain button, which structurally avoids the nesting
entirely. Worth noting as a small, real advantage of the Livewire conversion already done.

**When this branch is next picked up, port `main`'s fix to `sections/edit.blade.php` and
`department/edit.blade.php` before anything else** — this is a correctness/data-safety bug, not a
style preference, and shouldn't wait for a full merge. `main`'s fix pattern: un-nest the two forms
(make Delete's form a sibling instead of a child), give the outer form's submit button a
`form="<id>"` attribute so it still submits the right form despite being physically outside its
tags now, and switch the delete confirm from `onsubmit="return confirm(...)"` (silently discarded
by the parsing bug) to a SweetAlert2 confirm on the button's `onclick`. See `claude.md`'s "Never
nest a `<form>` inside another `<form>`" convention note (added alongside this fix on `main`) for
the full explanation and the exact fix shape to copy.

### 5. Pipeline Monitor gained a feature on `main` that needs porting to the Livewire component, not the old page

`72358d2` (`feat(pipeline): add bulk verify/accept for Review-status documents`) added ~130 lines
to `documents/pipeline.blade.php` — but that's the *old* page this pilot's Phase 1 already replaced
with `pipeline-monitor.blade.php` (the `wire:poll`-driven component). Porting this feature means
re-implementing bulk verify/accept as component methods (`wire:click`/bulk-select, matching the
pattern the component already uses for Convert/Retry), **not** copying the Blade diff verbatim —
the old page and the new component don't share markup. Read `72358d2`'s diff for the *behavior*
(which statuses are eligible, what the bulk-accept authorization check is), then reimplement it
against `pipeline-monitor.blade.php`'s existing bulk-select machinery (already built for
Convert/Retry per this file's own "Status" section above).

### 6. Auth/document-management bug fixes — should merge cleanly, verify against the new role model

Three targeted fixes on `main`, all in `DocumentController`/`app/Models/Document.php`, no direct
Livewire overlap but worth re-testing after the role-model merge (#1) since two of them are
authorization-adjacent:
- `91dc2cf` — Convert to Markdown was gated on `documents.verify`, should have been
  `documents.upload`.
- `c12f186` — `documents/show.blade.php`'s `$canManageDoc` was a stale duplicate of
  `canManageDocument()` computed separately in the view, occasionally disagreeing with the
  controller's own check. Consolidated to one source of truth.
- `6c76843` — non-PDF uploads were being renamed to `.pdf` without actual conversion; now actually
  converted.

### 7. Smaller items, lower priority

- **M88** (`ea55d0e`) — `User::getRouteKeyName()` now returns `'username'` instead of the default
  `id`, so `/admin/users/{user}` resolves on the username slug app-wide (matches the
  `Section`/`Division`/`RuleSet`/`Folder`/`Department` pattern already in place). This branch kept
  the dead `admin/users/{user}` show route + a real `show.blade.php` was added on `main` (a
  read-only profile page with an Edit button — this branch's `UserManagementController::show()`
  route exists but has no `show.blade.php` at all here, same dead-route state `main` was in before
  M88). Trivial to port (one method on `User`, one new Blade file) and worth doing since the route
  already exists unused.
- **M87** (`15cd615`) — `layout.blade.php`'s `@flasher_render` never actually echoes its output (a
  real bug in `php-flasher/flasher-laravel` itself) — confirmed still present in this branch's copy
  of `layout.blade.php` (`git diff main:...layout.blade.php livewire-pilot:...layout.blade.php`
  shows only this block differs, plus this branch's own `@livewireScripts` addition — no conflict
  between the two, should merge as a clean one-line swap:
  `{!! app('flasher')->render('html') !!}` instead of `@flasher_render`).
- `d095eff` — cosmetic, raw privilege-key label removed from the checkboxes partial; folds into #3's
  groupBy fix if that's ported.
- `3c88524`, `f33c78c`, `f355c06`, `3fce495` — `DEPLOY.md`-only documentation commits about the
  live-serving setup (artisan-serve gotchas, Blade view-cache touch() behavior). No code, but worth
  a skim since this branch will eventually also need to be the one served live.

### Suggested order when this branch is resumed

1. Port #4 (nested-form fix) immediately — it's a live, unpatched data-safety bug, independent of
   everything else here.
2. Merge/rebase onto current `main` proper (or cherry-pick #1's role-model commit first in
   isolation) — #1 changes `User` in ways #3 and #7 both build on, so it should land before either.
3. Run the full test suite (`tests/Feature/DocumentViewScopeTest.php`,
   `DocumentFolderVisibilityTest.php`, `DocumentVerifyTest.php` are all new on `main` since this
   branch diverged) and manually re-verify Designation/User privilege checkboxes actually save
   (#3's groupBy bug) before trusting this branch's admin forms again.
4. Reimplement #5 (bulk verify/accept) against `pipeline-monitor.blade.php`.
5. Everything else (#2, #6, #7) is low-risk and can land in any order.

## Sync status update (2026-08-22, later same day) — two more fixes landed on `main`

More bugs surfaced and got fixed on `main` after the first sync note above (still docs-only here,
nothing applied to this branch):

### 8. CRITICAL — onboarding link 404'd after `main` picked up username-based routing

Directly caused by #7's `User::getRouteKeyName() → 'username'` change: the onboarding link is a
*signed* URL generated with the numeric id (`UserManagementController::sendOnboardingLink()` →
`URL::temporarySignedRoute(..., ['user' => $user->id])`), never re-typed or browsed. Once `{user}`
started implicitly binding on `username`, `/onboarding/{id}` tried to resolve a user whose
`username` literally equalled that numeric string and 404'd — broke every onboarding email sent
between the routing change landing and this fix. Fixed on `main` by scoping just the two onboarding
routes to `{user:id}` in `routes/web.php`, overriding the model's default route key for that one
signed-link pair only.

**This branch doesn't have #7 (`getRouteKeyName`) yet, so it isn't hit by this specific break** —
but it's a landmine that reactivates the moment #7 is ported here without also porting this fix in
the same pass. Confirmed via diff that this branch's `routes/web.php` still has the plain
`Route::get('/onboarding/{user}', ...)` form. **When porting #7, port this `{user:id}` scoping in
the same commit, not as an afterthought** — the two are a matched pair, and shipping one without the
other reproduces exactly this incident.

### 9. Out-of-scope users couldn't discover public sections/folders at all (list-page bug, separate from #1's view-scoping)

A second, independent bug in the same area as #1's view-scoping work: `SectionController::index()`
(the browsable list of sections under a department — the page a user clicks through to *discover* a
section before ever reaching its `show()` page) had its own scope filter that **hard-excluded** any
section the viewing user wasn't scoped to, instead of degrading to the public-only view every other
controller in the app uses for out-of-scope authenticated users. Net effect: a section-scoped user
could never even see the *link* to another section's public content, even though that section's own
`show()` page rendered the public content correctly if reached by direct URL. Same bug pattern found
and fixed in `SearchController` for sections/divisions in search results (folders there were already
correct), plus a related guest-visibility leak in search (authenticated-only sections/divisions were
showing up by name for anonymous users) closed in the same pass.

This is a pure controller/query-layer fix — `SectionController::index()`, `SearchController` — with
**no Livewire overlap**, should merge/port cleanly once #1 (the role-model restructure it builds on
top of) lands here. No new migration.

### 10. Three admin tables converted from horizontal-scroll to responsive cards on mobile — check before porting Users/Designations

`main` converted the Users, Designations, and Activity Log admin tables to a `hidden md:block` table
+ `md:hidden` stacked-card layout, removing horizontal scroll on mobile. **This only ports cleanly
for Activity Log** (`admin/activity-logs/index.blade.php` is unmodified by this pilot — confirmed via
diff, applies as a straight cherry-pick). **Users and Designations do NOT port the same way** — on
`main` those are still plain Blade `create.blade.php`/`edit.blade.php`/`index.blade.php` pages, but
on this branch they were already replaced by Livewire components (`user-list.blade.php`,
`designation-manager.blade.php` — see this file's own "Status" section above). Confirmed both
components still have the exact same `overflow-x-auto` table their pre-Livewire counterparts had
(`git show livewire-pilot:resources/views/components/user-list.blade.php` /
`designation-manager.blade.php`) — the mobile-scroll problem exists here too, just needs the
responsive-card treatment applied *inside* those two Livewire components instead of copying `main`'s
Blade diff verbatim (which touches files that no longer exist in this branch's admin UI). Read
`main`'s `resources/views/admin/users/index.blade.php`/`admin/designations/index.blade.php` diffs
for the card-layout *shape* (a `md:hidden` stacked `<div>` list mirroring each `<tr>`'s fields, one
shared set of hidden delete/deactivate `<form>`s keyed by row id, table unchanged above `md`), then
reimplement that shape inside the two Livewire components' own markup.

### Updated suggested order

Insert between the previous steps 1 and 2: **port #8 in the same commit as #7** (they're a matched
pair, see above). Item #9 can land any time after #1. Item #10's Activity Log half can land
immediately (zero risk, isolated); its Users/Designations half should wait until this branch's own
`user-list`/`designation-manager` components are being touched anyway, so the card layout doesn't
need to be re-verified against evolving Livewire component internals twice.

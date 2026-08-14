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

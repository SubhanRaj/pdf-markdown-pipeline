# Standalone "New Conversion" — Upload & Convert Without Picking a Destination First

## Context

Today the sidebar's **"Bulk Upload & Convert"** and the header's **"New Conversion"** button
are literally the same link (`route('documents.bulk-upload')`) — both land on
`documents/bulk-upload.blade.php`, which requires picking a Section/Folder/Division/RuleSet
destination *before* a file can even be added. The Additional Excise Commissioner pointed out
this is backwards for the common case of "I just want to convert this one PDF to Markdown" —
there's no such thing as "New Upload" on the dashboard, so "New Conversion" should mean exactly
that: drop a PDF, get Markdown, review it side-by-side (the same Docling/OCR/compare pipeline
that already exists), and *only then* decide whether to file it into a Section/Folder/Rule
Set/Policy — or let it quietly expire if the user walks away.

Confirmed via `DocumentController::store()` (`app/Http/Controllers/DocumentController.php:427`)
and `StoreDocumentRequest`: today a `Document` row is created **permanently**, in its final
vault location, the instant a file is uploaded — destination (`section_id`/`rule_set_id`/etc.)
is required at upload time, `department_id` is a `NOT NULL` FK, and there is no
staging/draft concept anywhere in the schema. Rewiring `Document` itself to support a
"no destination yet" state would mean loosening a mature, tightly-validated model (slug
uniqueness per section/rule-set, approval workflow, browse-page queries that assume a real
section/rule_set) — too much blast radius for what the Commissioner actually asked for.

Instead: a small, separate, **ephemeral** model (`QuickConversion`) holds the file + its
conversion result until the user either saves it into the real vault (at which point it
becomes a normal, permanent `Document` — same as any other upload) or lets it expire. This
keeps the existing `Document`/`RuleSet`/approval pipeline completely untouched, and confines
all new risk to a self-contained, deletable-any-time table.

## Design

### 1. New table + model: `QuickConversion`

Migration `create_quick_conversions_table`:
- `id`, `user_id` (FK, `cascadeOnDelete` — no meaning to an orphaned quick conversion)
- `title` (nullable — defaults to the filename at save-time, not required to start converting)
- `original_filename`, `pdf_path`, `markdown_path` (nullable), `structure_path` (nullable)
- `status` — reuse the exact vocabulary already in `Document::STATUSES`
  (`uploaded|processing|ocr_pending|review|failed`; `verified`/`pending_approval` unused here)
- `metadata` (json) — same shape as `Document.metadata`: `extraction_method`, `ocr_engine`,
  `needs_ocr_review`, `structure_*`
- `error_message` (nullable text) — job failure detail (Document uses `DocumentStatusHistory`
  for this; a single column is enough here since there's no audit/history requirement)
- `expires_at` (timestamp)
- `timestamps()` — no `softDeletes()`, discarding/expiring is a real delete (file cleanup needs
  a real "gone" signal, not a trashed row lingering)

`App\Models\QuickConversion` — plain Eloquent model, `belongsTo(User::class)`, fillable list,
`metadata` cast to `array`. No policy class (mirrors `DocumentController::authorizeEdit()`'s
inline-check convention) — every action checks `$quickConversion->user_id === auth()->id() ||
auth()->user()->isAdmin()` inline.

### 2. Reuse the conversion pipeline via extraction, not duplication

`ConvertDocumentToMarkdown` (`app/Jobs/ConvertDocumentToMarkdown.php`) and `RunOcrExtraction`
(`app/Jobs/RunOcrExtraction.php`) only touch the `Document` model for: reading
`original_pdf_path`/`markdown_path` (plain strings), and writing `status`/`metadata` +
`DocumentStatusHistory` on completion. The actual conversion logic (`tryStructuredExtract`,
`runDoclingStructureAnalysis`, `isGoodQuality`, `countPages`, `runOcr`) only needs path strings
and an id for logging — never the model itself.

Extract those five methods verbatim into a new `App\Services\PdfConversionEngine` (parameters
become explicit paths/ids instead of `$document->original_pdf_path`/`$document->id`). Update
`ConvertDocumentToMarkdown`/`RunOcrExtraction` to call the service — behavior-preserving,
mechanical change, same commands/timeouts/comments carried over. This is the one place that
touches proven production code, so keep the diff to "move method body, thread through
parameters instead of `$document->`" — no logic changes.

Then two new, thin jobs mirror the existing two `handle()` orchestrations but against
`QuickConversion`:
- `App\Jobs\ConvertQuickConversionToMarkdown`
- `App\Jobs\RunQuickConversionOcrExtraction`

Same status flow (`processing` → `review` or `ocr_pending` → auto-dispatches OCR job → `review`
on success, `failed` + `error_message` on exception). No `DocumentStatusHistory`-equivalent
needed — just update the row's own `status`/`metadata`/`error_message`.

### 3. Expiry — delayed job, no new cron dependency

`bootstrap/app.php` has no `->withSchedule()` today (verified — no scheduler infra exists at
all). Rather than introduce a cron/scheduler dependency for one feature, use a **delayed queue
job**, dispatched once at creation time onto the same single serial queue worker the app
already runs (per CLAUDE.md):

```php
PruneQuickConversion::dispatch($quickConversion->id)->delay($quickConversion->expires_at);
```

`PruneQuickConversion::handle()` — if the row still exists (i.e. wasn't already saved/discarded),
delete its files (`pdf_path`, `markdown_path`, `structure_path` on the `public` disk) and the
row. `expires_at = now()->addHours(config('quick-conversions.ttl_hours', 48))` — new tiny config
file `config/quick-conversions.php` reading `QUICK_CONVERSION_TTL_HOURS` env (default 48h —
"hold it for a while" per the request, nothing more precise was specified).

### 4. Controller + routes — new `QuickConversionController`

New route group in `routes/web.php`, prefix `/conversions`, name `conversions.` (no collision
with existing `documents.convert`/`documents.*` names):

- `GET /conversions/new` → `create()` — the single-file drop form (`quick_conversions/create.blade.php`)
- `POST /conversions` → `store()` — validates via new slim `StoreQuickConversionRequest`
  (reuses `StoreDocumentRequest::ACCEPTED_MIMETYPES` — keep that constant on `StoreDocumentRequest`
  and reference it from the new request rather than duplicating the list), stores the file to
  `quick_conversions/{id}/original.pdf` on the `public` disk, creates the row
  (`status: uploaded`), dispatches `ConvertQuickConversionToMarkdown::dispatch()` immediately (no
  manual "click Convert" step — the whole point of this flow is auto-convert), dispatches the
  delayed prune job, returns JSON `{redirect: route('conversions.show', $quickConversion)}`
- `GET /conversions/{quickConversion}` → `show()` — status banner + polling while
  processing/ocr_pending (same polling pattern as `documents/show.blade.php` /
  `documents.convert-status`), then the side-by-side PDF/Markdown compare view once `review` or
  `failed`, plus "Save to…" and "Discard" actions and a visible "auto-deletes at {expires_at}
  unless saved" notice
- `GET /conversions/{quickConversion}/status` → `status()` — JSON, mirrors
  `DocumentController::conversionStatus()` (`DocumentController.php:1495`)
- `POST /conversions/{quickConversion}/ocr` → `runOcr()` — manual retry, mirrors `convertOcr()`
- `PATCH /conversions/{quickConversion}` → `updateMarkdown()` — save edits to the markdown text
  in place, pre-placement (small, mirrors `updateMarkdown()` but no verified/history bookkeeping)
- `POST /conversions/{quickConversion}/place` → `place()` — the "move to a Section/Folder/Rule
  Set/Policy" action. Validates `title`, `document_type`, `language`, `visibility` +
  destination fields using the **same** destination-resolution logic as
  `StoreDocumentRequest::authorize()`/`rules()` — extract that shared bit (destination
  validation + `canUploadTo`/`canManagePolicy` authorization) into a trait
  `ResolvesUploadDestination` used by both `StoreDocumentRequest` and a new
  `PlaceQuickConversionRequest`, rather than duplicating it. `place()` itself reuses
  `DocumentController::store()`'s vault-path-building `match`/if-chain (same four destination
  branches, same `Document::uniqueSlugFor*()` calls) but instead of storing an uploaded file, it
  **moves** the QuickConversion's existing `pdf_path`/`markdown_path` into the resolved vault
  dir (same move-then-delete pattern as `ManagesDocumentFiles::moveFile()` —
  `app/Http/Controllers/Concerns/ManagesDocumentFiles.php:96`), creates the `Document` row with
  `status` carried over from the QuickConversion's current status (`review`/`failed`) — or
  `pending_approval` if `shouldRequireApproval()` says so, exactly like today's `store()` — then
  deletes the QuickConversion row (files already moved, nothing left to prune). Redirects to the
  new `Document`.
- `DELETE /conversions/{quickConversion}` → `destroy()` — discard: delete files + row
  immediately (the delayed prune job finds the row already gone later and no-ops).

Known accepted edge case (documented, not solved): if placement requires approval, the new
Document is created `pending_approval` even though it already has a `markdown_path` — same as
today's approval flow, except an approver who later clicks "Convert" on it would re-run
conversion unnecessarily. Not fixing this now — narrow, cosmetic, pre-existing-pattern-adjacent.

### 5. Views

- `resources/views/quick_conversions/create.blade.php` — single dropzone (reuse the drag/drop +
  `fileToTitle()` JS pattern from `bulk-upload.blade.php:401`, simplified to one file, title
  optional).
- `resources/views/quick_conversions/show.blade.php` — reuses the compare-pane markup from
  `documents/show.blade.php:740-1362`. Extract that block into a shared partial
  (`resources/views/documents/_compare_panel.blade.php`, parameterized by PDF url / markdown
  text / save-url) used by both pages — if inspection at implementation time shows it's too
  entangled with Document-specific fields to extract cleanly, fall back to a standalone
  (visually matching, not code-sharing) compare UI in `quick_conversions/show.blade.php` rather
  than force a bad abstraction.
- Destination picker for "Save to…" reuses the scope-tree `<select>` cascade + JS
  (`refreshDestination()` etc., `bulk-upload.blade.php:200-401`) in a modal on the show page.

### 6. Navigation

- Sidebar (`resources/views/components/sidebar.blade.php:127`): keep "Bulk Upload & Convert" →
  `documents.bulk-upload` unchanged (multi-file, destination-first, for filing straight into a
  known Section/Folder/etc.). Add a new sidebar entry **"New Conversion"** →
  `conversions.create`, same `uploadScope() !== 'none'` gate, placed above/below the existing one.
- Header (`resources/views/components/header.blade.php:52`): point the existing "New
  Conversion" CTA at `conversions.create` instead of `documents.bulk-upload`.

## Files touched

- `database/migrations/xxxx_create_quick_conversions_table.php` (new)
- `app/Models/QuickConversion.php` (new)
- `config/quick-conversions.php` (new) + `.env`/`.env.example`: `QUICK_CONVERSION_TTL_HOURS=48`
- `app/Services/PdfConversionEngine.php` (new — extracted from the two existing jobs)
- `app/Jobs/ConvertDocumentToMarkdown.php`, `app/Jobs/RunOcrExtraction.php` (refactored to call
  the service; behavior unchanged)
- `app/Jobs/ConvertQuickConversionToMarkdown.php`, `app/Jobs/RunQuickConversionOcrExtraction.php`,
  `app/Jobs/PruneQuickConversion.php` (new)
- `app/Http/Controllers/QuickConversionController.php` (new)
- `app/Http/Requests/StoreQuickConversionRequest.php`, `PlaceQuickConversionRequest.php` (new)
- `app/Http/Requests/Concerns/ResolvesUploadDestination.php` (new trait, extracted from
  `StoreDocumentRequest`) — `StoreDocumentRequest` updated to use it
- `routes/web.php` — new `conversions.*` group
- `resources/views/quick_conversions/create.blade.php`, `show.blade.php` (new)
- `resources/views/documents/_compare_panel.blade.php` (new, extracted — best-effort per above)
- `resources/views/components/sidebar.blade.php`, `components/header.blade.php`

## Verification

1. `php artisan migrate` — confirm `quick_conversions` table created.
2. As a user with upload permission, click "New Conversion" in the header → lands on the new
   single-file form (not the old destination-first bulk-upload page).
3. Drop a PDF → redirected to the show page → status polls through
   `uploaded → processing → review` (or `ocr_pending → review` for a scanned doc), same as the
   existing pipeline monitor does for regular documents today.
4. Reload the show page mid-conversion — confirm the file/row persisted (no vanishing on
   reload) and polling resumes correctly.
5. Once `review`: confirm the PDF/Markdown compare view renders, matches the existing
   compare-and-verify UX.
6. Click "Save to…" → pick a Section (or Rule Set/Folder/Division) → confirm a real `Document`
   row is created in that destination, files physically land under `document_vault/...`, and the
   `quick_conversions` row + its own copy of the files are gone.
7. On a second conversion, click "Discard" → confirm immediate deletion of row + files.
8. On a third conversion, do nothing — confirm the delayed `PruneQuickConversion` job (check
   `php artisan queue:work` output / `jobs` table) deletes it automatically once `expires_at`
   passes (test with a short TTL override, e.g. `QUICK_CONVERSION_TTL_HOURS` set very low
   temporarily, or manually inspect `PruneQuickConversion::dispatch(...)->delay(...)` fired).
9. Confirm `documents/bulk-upload` (sidebar's "Bulk Upload & Convert") still works exactly as
   before — zero regression, since `Document`/`StoreDocumentRequest`/`DocumentController::store()`
   are untouched except for the extracted `ResolvesUploadDestination` trait (behavior-preserving).
10. Confirm existing Document conversions (regular upload → Convert button) still work
    identically after the `PdfConversionEngine` extraction — convert a real PDF through the
    normal flow and check the Markdown output matches what it produced before the refactor.

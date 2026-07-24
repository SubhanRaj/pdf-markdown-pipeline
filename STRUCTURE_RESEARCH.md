# Structure Detection Research — Docling Evaluation

**Date:** 2026-07-15 (last updated 2026-07-24)
**Status:** Live in production. Phase 1 (structure detection, M32), partial Phase 2 (table splice,
M33), heading splice + pipeline reorder (M34), the legacy-font force-ocr fix (M37), and the
Docling-table-priority fix (M38) are all shipped — see `summary.md`. This file
records what was tried and found, so the reasoning trail isn't lost — same purpose
`OCR_RESEARCH.md` serves for character accuracy. Supersedes `STRUCTURE_HANDOFF.md`'s original
three-pass proposal (structural map → raw extraction → structured reconstruction); see git
history for that original text.

## Why this was investigated

Character-recognition accuracy was already solved (`OCR_RESEARCH.md`, M30/M31). But even a
correctly-recognized page loses *structure* — tables collapse into run-on paragraphs, headings
disappear into body text — because neither `markitdown`/pdfminer nor plain OCR understands
layout, only characters. Docling was tested as the tool to fix this with a purpose-built layout
model instead of an LLM.

## Test setup

Docling (`pip install docling`, 2.113.0) in its own venv:
`storage/app/private/ocr-engines/docling/` (pyenv 3.12.8, same convention as the other engines).
Tested against two real documents: **Uttar Pradesh Excise Policy 2026-27** (112 pages, native
text layer) and **Odisha Excise Policy 2026-29** (54 pages, fully scanned, no text layer).

## Findings

1. **Structure detection works, and is fast.** Docling's layout model (DocLayNet) + TableFormer
   correctly reconstructed headings and real Markdown tables on both documents — Odisha (fully
   scanned) took 2m47s for 54 pages (~3s/page), 114 headings and 277 table rows detected
   correctly including multi-column fiscal tables and nested section numbering. No LLM/Ollama
   involved.
2. **Default OCR backend silently picks the wrong language.** With no `--ocr-engine`/`--ocr-lang`
   flags, Docling's default (RapidOCR) resolved to a Chinese-pretrained model and produced CJK
   hallucinations. Fixed by always pinning `--ocr-engine tesseract --ocr-lang hin+eng` (or the
   equivalent per engine) — `config/docling.php` stores this explicitly, never relies on the
   default.
3. **`--force-ocr` is impractical at real page counts, as a blanket setting.** Forcing full-page
   OCR on the 112-page UP document timed out past 10 minutes with zero output (Docling only
   writes after full completion, no partial results). Default mode (OCR only on detected bitmap
   regions) stayed fast (~2 min). Never force it for every document — but see M37 below: there is
   exactly one case (legacy-font-encoded PDFs) where the default mode is not just slow to fix but
   actively wrong, and forcing OCR is unavoidable there, scoped to only that case.
4. **Docling can't call Paddle or Surya as an OCR backend** — only `tesseract`/`easyocr`/
   `rapidocr`/a few others. Matters little for body text (the reviewer's own OCR-engine choice
   handles that, unrelated to Docling), but does matter for **table cell text**: Docling's own
   recognized cell text is retained (not discarded) and, as of M33, spliced into the final
   Markdown — so for tables specifically, `config/docling.php`'s engine choice affects accuracy,
   not just structure shape.
5. **A real, pre-existing font-encoding gap, independent of Docling.** The UP document's body
   text extracted as visible-but-wrong Latin characters — the signature of legacy Kruti
   Dev/Chanakya-style font encoding (glyphs remapped to Latin code points instead of real Unicode
   Devanagari). Any extractor reading this PDF's cmap (Docling, pdfminer, markitdown) gets the
   same garbage, and it wasn't caught by the existing `isGoodQuality()` check (readable-looking
   but wrong text, not cid-fallback or near-empty). OCR is naturally immune — it reads rendered
   pixels, never the corrupted cmap. Fixed in M32: detect the legacy font *name* from pdfminer's
   per-character metadata and force `needs_ocr_review = true`, rather than attempting a
   character-remapping table (too risky for legal government text). **This only fixed body text —
   see M37 below for the table-splice half of the same bug, found 2026-07-24.**

## What shipped

**M32 (Phase 1, 2026-07-15)** — structure-only detection: Docling runs as Pass 0, producing a
compact `structure.json` (headings + table cells + bboxes) shown to reviewers. Deliberately
stopped short of merging it into the rendered Markdown until real output had been reviewed
against real documents in the UI.

**M33 (Phase 2, partial — 2026-07-16)** — reviewing that real output surfaced the expected gap: a
scanned-page table Docling detected correctly but the existing geometric heuristic
(`detect_tables()` in `pdf_structure_extractor.py`) missed. Since Docling's `structure.json`
already retains each table cell's recognized text, the fix reuses it directly instead of building
a full geometric merge:

- `docling_table_blocks()` (new) loads `structure.json`, turns each table into a `TableBlock`
  keyed by page.
- `classify_and_render()` takes an optional `docling_tables` list and inserts Docling's version
  at the correct point in a page's content wherever the heuristic found no table itself.
- Wired via a new `--structure-json PATH` flag, passed by both `ConvertDocumentToMarkdown` and
  `RunOcrExtraction` whenever Pass 0 produced a structure.json for that document.
- No LLM anywhere in this path — pure reuse of a model that already ran.

**Known limitation, not fixed this round: duplicate table content on some OCR-derived
documents.** `detect_tables()` only tags a row run as `table_fragment` (suppressible once Docling
supplies a replacement) when it *attempts* to cluster rows and rejects them as too sparse. On the
real Odisha document, Tesseract's hOCR line boxes for one table were fragmented enough that
row-grouping never even reached that candidate stage — so nothing was tagged, and garbled
fragments still appear as ordinary paragraph text alongside the correct spliced table. Properly
suppressing this needs comparing each OCR line's bbox against Docling's table bbox for that page —
i.e. the full geometric merge below, since Docling reports bboxes in PDF-point/bottom-left space
while every OCR engine here reports pixel/top-left space tied to `pdftoppm`'s rasterization DPI.

**M34 (2026-07-17) — heading splice + pipeline reorder + auto-OCR-trigger.**

- **Heading splice**, symmetric to M33's table splice: `docling_heading_blocks()` (new) loads each
  detected heading (text + page) from `structure.json`. Docling doesn't report a nesting depth, so
  level is inferred from a numbered prefix (`1.2.1` → deeper) the same way the existing
  `heading_level_from_caps()` heuristic already does, defaulting to level 2 when unnumbered.
  `classify_and_render()` now also takes `docling_headings` and, page by page, inserts Docling's
  headings at the top of any page where the geometric heuristic found zero headings of its own —
  same page-level granularity as the table splice, not a per-heading text match. A shared
  `_insert_index()` helper replaces the table splice's inline position-finding logic (used by both,
  parameterized by whether the new block goes at the start or end of the page's other content).
- **Pipeline reorder.** `ConvertDocumentToMarkdown` now runs Pass 1 (pdfminer text-layer
  extraction — the fast half of the job) *before* Pass 0 (Docling), instead of after. This means
  the quality/legacy-font check result is known before Docling's per-page structure-detection time
  is spent, not after. Docling still always runs afterward (needed for the splice either way); the
  text is then re-rendered once structure.json exists so the splice can apply.
- **Auto-OCR-trigger.** Previously, a low-quality result just sat at `status: review` with
  `needs_ocr_review: true` until a reviewer noticed the flag and clicked "Run OCR" themselves.
  Now, since the reorder means this is already known by the end of the job, `RunOcrExtraction` is
  dispatched automatically (`config('ocr.default')` engine) and status goes straight to
  `ocr_pending` — no manual click needed for the common "this is clearly a scan" case. A reviewer
  can still manually re-run OCR with a different engine afterward, same as before.
- Verified end-to-end against two real documents: a scanned/empty-text-layer document (correctly
  auto-queued `RunOcrExtraction`), and a genuine text-layer document with 66 headings/88 tables
  detected by Docling (correctly stayed at `status: review`, headings/tables spliced into the
  278KB rendered Markdown).
- No LLM anywhere in this path — same as M32/M33, pure reuse of what Docling already detected.

**M37 (2026-07-24) — force-ocr for legacy-font tables.** Found while reviewing a real court-matter
document (a district scan, urgent): M32's legacy-font fix only ever protected *body text*. Table
cell text is a completely separate extraction path — `runDoclingStructureAnalysis()` in
`ConvertDocumentToMarkdown.php` calls Docling in its default mode, which trusts the PDF's native
text layer for any region it doesn't detect as a scanned bitmap. A Kruti Dev PDF's text is
technically "selectable" (not an image), so Docling never OCRs those regions either — table cells
came out with the exact same garbled codepoints Finding 5 already described, and
`RunOcrExtraction` then spliced that same (unfixed) `structure.json` into the otherwise-correctly-
OCR'd body text. Net effect: a reviewer would see correct Hindi paragraphs and garbage tables in
the same document.

Fix: the legacy-font sentinel is already detected *before* Docling runs (`$legacyFont`, set from
Pass 1's output at the top of `handle()`) — it just wasn't being passed through.
`runDoclingStructureAnalysis()` now takes a `bool $forceOcr` parameter and adds `--force-ocr` to
the Docling command only when `$legacyFont !== null`, so Docling re-reads every region — tables
included — from rendered pixels instead of the broken text layer, for that one flagged case only.
Finding 3's blanket "never force it" still holds for the general case; this is the one documented
exception, and only reachable through the already-detected legacy-font path, never for a document
otherwise flagged good or merely low-quality/sparse. Timeout for this path bumped to 900s (was
600s for the default path) since force-OCR is measurably slower — still inside the job's overall
1200s ceiling.

Verified against the real production document that surfaced Finding 5 (`Excise Policy Uttar
Pradesh`, id 16, 112 pages, `verified` status — not re-converted to avoid touching a live/possibly-
cited document): extracted the affected page standalone (`pdfseparate`), ran Docling directly with
and without `--force-ocr`. Before: `'Ø0la0'`, `"rhozrk ¼çfr'kr oh@oh½"`. After: `'क्र0सं0'`,
`'तीव्रता (प्रतिशत वी / वी)'` — correct, readable Devanagari, same page, same table. Also stamped
`force_ocr`/`structure_force_ocr` into the structure JSON and document metadata respectively, so
which documents needed this path is visible later without re-deriving it.

## M38 (2026-07-24) — Docling tables now win over a wrong geometric table, not just a missing one

`classify_and_render()`'s table splice (`docling_table_blocks`) only filled pages where
`detect_tables()`'s geometric heuristic found *no* table at all (`covered_pages` skip-if-present
check). That was backwards for the actual failure mode: a page where the heuristic's x0-column-
clustering misfires (columns merged, rows split wrong) still produces *some* `TableBlock` — just
a wrong one — so Docling's genuinely more reliable TableFormer-derived table was silently
discarded for exactly the pages that most needed it, and only ever used on pages the heuristic
gave up on entirely.

Fix: table splice no longer checks "does this page already have a table" — it removes any
geometric `TableBlock` on a page Docling also detected a table for, then always inserts Docling's
version. Headings keep the original "only fill if this page has zero heuristic-detected
headings" behavior unchanged — Docling's structure JSON has no heading-level data, so a page
where the font-size/caps heuristic already found a heading is presumed better classified by it,
not a reliability gap the way tables are (see `docling_heading_blocks`' docstring).

Verified with a standalone script (`Line`/`TableBlock` objects built directly, no PDF needed):
a page with both a wrong 2-column geometric table and a correct Docling table for the same page —
before the fix, output kept the wrong one; after, only Docling's survives.

## Open follow-ups, not implemented

- **Full geometric merge** — reconcile Docling's PDF-point/bottom-left bboxes against each OCR
  engine's pixel/top-left bboxes, so garbled table fragments can be dropped by spatial overlap
  rather than only when the row-clustering heuristic happens to flag them. Would close the known
  limitation above.
- **PaddleOCR's Hindi-only recognition model** — see `OCR_RESEARCH.md`'s open follow-ups; same
  item, tracked there since it's a character-accuracy concern, not structure.
- **Docling's structure-pass OCR engine is hardcoded to Tesseract** — see `OCR_RESEARCH.md`'s
  current-status section; switching the default to EasyOCR would directly improve spliced
  table/heading text accuracy on scanned documents, one config line, not yet done.
- **Docling heading levels are inferred, not exact** — no real outline depth in `structure.json`,
  only text + page. Good enough for review; not a guaranteed-correct document outline.

## Review UI changes (2026-07-16)

Testing M32/M33 against real documents in the review screen surfaced UX problems fixed
alongside the merge work:

- The structure summary now lives *inside* the Compare & Verify modal (previously a page-level
  banner, easy to miss behind the modal once opened) as a collapsible "View structure" panel,
  right above the OCR-quality warning — same place the reviewer decides Markdown vs. OCR.
- That panel renders tables via [Grid.js](https://gridjs.io/) (CDN, no build step — same
  convention as `marked`/SweetAlert2/Chart.js) for sortable/searchable/paginated tables, instead
  of a hand-rolled static `<table>`.
- The modal itself is now full-screen (was a centered `min(1400px, 96vw)` box) to fit the
  original PDF, extracted Markdown, and structure panel together.

## Reproducing this evaluation

```bash
storage/app/private/ocr-engines/docling/bin/docling convert --to md --to json \
    --ocr-engine tesseract --ocr-lang hin+eng \
    --output /tmp/docling-test "<path-to-pdf>"
```

Always pass `--ocr-engine`/`--ocr-lang` explicitly (Finding 2). Never pass `--force-ocr` on a
multi-page document unless you already know it's legacy-font-encoded (Finding 3, M37) — the app
itself only ever does this automatically, scoped to that one case; manually forcing it on an
arbitrary multi-page document for a one-off test still risks the timeout Finding 3 describes.
Docling's raw JSON export is large (100MB+ per document) — only needed transiently to build the
compact structure map, never worth keeping around.

# Structure Detection Research — Docling Evaluation

**Date:** 2026-07-15 (last updated 2026-07-16)
**Status:** Live in production. Phase 1 (structure detection, M32) plus a partial Phase 2
(Docling table text spliced into the final Markdown where the existing heuristic misses a
table, M33) are both implemented — see `summary.md`. This file records what was tried and found
during evaluation, so the reasoning trail isn't lost — same purpose `OCR_RESEARCH.md` serves for
the character-accuracy side of this pipeline. Continues from the three-pass idea proposed in
chat and originally recorded in `STRUCTURE_HANDOFF.md` — since retired now that this file
supersedes it; see git history if the original handoff text is needed.

## Why this was investigated

`OCR_RESEARCH.md` and M30/M31 (`summary.md`) already solved *character*-recognition accuracy —
picking the right OCR engine, tuning quality checks. But a separate problem remained: even a
correctly-recognized page loses *structure* — tables collapse into run-on paragraphs, headings
disappear into body text — because neither `markitdown`/pdfminer nor plain OCR (Tesseract/
EasyOCR/PaddleOCR) understands layout, only characters. The original handoff proposed a
three-pass fix (structural map → raw extraction → structured reconstruction) and pointed at
Docling as the tool that already implements this pattern with a purpose-built layout model
rather than a general-purpose LLM. This file is the result of actually testing that.

## Test setup

Docling installed via `pip install docling` (2.113.0) in its own venv, matching the existing
per-engine convention: `storage/app/private/ocr-engines/docling/` (pyenv 3.12.8, same pattern as
EasyOCR/PaddleOCR/Surya). Tested against two real documents from the "other states" excise
policy batch (`/home/subhan/Excise policies of states/` on the Ubuntu AIO box):

- **Uttar Pradesh Excise Policy 2026-27.pdf** (112 pages) — has a native text layer.
- **Odisha Excise Policy 2026-29.pdf** (54 pages) — confirmed scanned, no text layer at all
  (per the earlier Tesseract/EasyOCR/PaddleOCR comparison in `OCR_RESEARCH.md`).

## Finding 1 — structure detection genuinely works, and is fast

Docling's layout-detection model (trained on DocLayNet) + TableFormer table-structure model
correctly identified headings and reconstructed real Markdown tables (matching header rows,
correct column alignment) on both documents:

- UP document (text-layer, default OCR-on-bitmap-regions-only mode): ~2 minutes for 112 pages.
- Odisha document (fully scanned, every page needs OCR): 2 min 47 sec for 54 pages (~3s/page) —
  114 headings and 277 table rows correctly detected across the full document, multi-column
  fiscal tables (2026-27/2027-28/2028-29 columns) reconstructed with correct row/column
  alignment, section numbering (`1.1`, `1.2`, `1.8.1`...) preserved as real heading hierarchy.

No LLM/Ollama involved anywhere — confirms the original handoff's hypothesis that a
specialist vision model clears this bar far faster than a general VLM would.

## Finding 2 — Docling's default OCR backend silently picks the wrong language

Running with no `--ocr-engine`/`--ocr-lang` flags, Docling's default backend (RapidOCR) silently
resolved to a **Chinese**-pretrained model (`ch_PP-OCRv4`) and produced CJK hallucinations mixed
into headings (`## 1一 2026-27 尼 - l`). Fixed by always pinning `--ocr-engine tesseract
--ocr-lang hin+eng` (or the equivalent for whichever engine is selected) — never leave this
unset. This is why `config/docling.php` stores an explicit `ocr_lang` per engine rather than
relying on any default.

## Finding 3 — `--force-ocr` is impractical at real page counts

Tried forcing full-page OCR (bypassing the embedded text layer entirely) on the 112-page UP
document to test whether it would sidestep the Kruti Dev font problem (Finding 5). It **timed
out past 10 minutes with zero output** — Docling only writes output files after full document
completion, no partial/streaming results. Default mode (OCR only on detected bitmap regions,
trust the text layer everywhere else) stayed fast (~2 min for the same 112 pages). Conclusion:
never use `--force-ocr` in production; the existing default-mode behavior is the right one.

## Finding 4 — Docling cannot call Paddle or Surya as an OCR backend

`docling convert --ocr-engine` only accepts: `auto`, `easyocr`, `kserve_v2_ocr`, `nemotron-ocr`,
`ocrmac`, `rapidocr`, `tesserocr`, `tesseract`. There is no way to have Docling itself invoke
PaddleOCR or Surya directly. This matters less than it sounds for headings/body text: the
character-accurate text there still comes from whichever engine (Tesseract/EasyOCR/PaddleOCR/
Surya) the reviewer picks in the existing OCR-engine dropdown, unrelated to Docling entirely.
**Update 2026-07-16:** table *cell* text is the one exception — Docling's own recognized cell
text is retained and, as of M33 below, spliced into the final Markdown when the heuristic misses
a table, so for tables specifically Docling's own OCR backend choice (`config/docling.php`) does
matter for accuracy, not just structure shape.

## Finding 5 — a real, pre-existing font-encoding gap, independent of Docling

The UP document's body text extracted as `Hkkjr ds lafo/kku dh 7oha vuqlwph...` — the
well-known signature of **Kruti Dev/Chanakya-style legacy font encoding** (`Hkkjr` = "भारत"/
Bharat, remapped byte-for-byte to Latin code points rather than real Unicode Devanagari). Any
text extractor reading this PDF's cmap — Docling, pdfminer, markitdown — gets the same garbage,
because the font itself lies about what its glyphs mean. Confirmed this is **not** caught by the
existing `isGoodQuality()` quality check (`(cid:\d+)` tokens or near-empty text) — this document
has plenty of readable-looking (but wrong) characters, so it would have silently passed through
to `review` with corrupted Hindi throughout, no flag raised.

**Also confirmed:** OCR-based extraction is naturally immune. Headings pulled from image regions
on the same document decoded correctly (`## उत्तर प्रदेशीय सरकार द्वारा प्रकाशित` via
Tesseract), because OCR reads rendered pixels, never the corrupted cmap. This confirmed the fix
implemented in M32: detect the legacy font *name* directly from pdfminer's per-character
metadata and force `needs_ocr_review = true`, rather than attempting a character-remapping
table (which risks silently producing subtly-wrong "fixed" text in a legal government document).

## Two combination strategies considered

**Strategy A — Docling with its own OCR, one self-contained pass.** Run
`docling convert --ocr-engine {easyocr|tesseract} ...` and use its Markdown output directly,
end to end. Simplest integration, but ties the final text quality to whichever engine Docling
can call (no Paddle/Surya), giving up the character-accuracy work already done in
`OCR_RESEARCH.md`.

**Strategy B — Docling structure-only + separate engine + geometric merge (chosen path).**
Run Docling purely for its structure map (headings/tables + bounding boxes), keep the existing
OCR-engine pipeline (Tesseract/EasyOCR/PaddleOCR/Surya, whichever the reviewer picks) for the
actual text, then align the OCR engine's word-level bounding boxes into Docling's region boxes
to reconstruct structured Markdown. This preserves the character-accuracy work already invested
in Paddle/EasyOCR while adding real structure.

**M32 (Phase 1, 2026-07-15) shipped the structure-detection half of Strategy B only** — the
compact structure map was produced and shown to reviewers, with the bounding-box merge into
rendered Markdown deferred until real structure output had been reviewed against enough real
documents in the UI.

**M33 (Phase 2, partial — 2026-07-16) — Docling table text spliced into the Markdown.**
Reviewing real structure output in the UI (per M32's own deferral condition) surfaced the exact
gap expected: the existing geometric heuristic (`detect_tables()` in
`pdf_structure_extractor.py`) missed a real table on a scanned page even though Docling's own
structure map had detected it correctly (54-page Odisha document, PaddleOCR text pass). Since
Docling's compact `structure.json` already retains each table cell's own recognized text (not
discarded, contrary to what an earlier note in this file implied), the fix doesn't need the full
geometric merge — it reuses that already-recognized text directly:

- `docling_table_blocks()` (new, `pdf_structure_extractor.py`) loads the compact structure.json
  and turns each table into a `TableBlock`, keyed by (0-indexed) page.
- `classify_and_render()` now accepts an optional `docling_tables` list and, for any page where
  the heuristic's own `detect_tables()` found no table, inserts Docling's version at the correct
  point in that page's content — not just appended at the end of the document.
- Wired via a new `--structure-json PATH` CLI flag, passed by both `ConvertDocumentToMarkdown`
  (text-layer pass) and `RunOcrExtraction` (OCR pass) whenever Pass 0's structure.json exists for
  that document.
- No LLM/Ollama anywhere in this path — same as Phase 1, this is pure reuse of a model that
  already ran.

**Known limitation, not fixed this round: duplicate table content on some OCR-derived
documents.** `detect_tables()` tags a multi-cell row run as `table_fragment: true` when it
*attempts* to cluster rows into a table candidate and rejects it as too sparse (the existing
fill-ratio check) — `classify_and_render()` strips those tagged lines when Docling supplies a
clean replacement for that page. But verified against the real Odisha document: Tesseract's hOCR
line boxes for that specific table were fragmented enough that row-grouping never even reached
the "candidate" stage (each line landing in its own single-cell row, outside the `ROW_Y_TOLERANCE`
window) — so nothing was tagged, and the garbled fragments still appear as ordinary paragraph
text alongside the correct spliced table. Properly suppressing this needs comparing each OCR
line's bounding box against Docling's table bbox for that page, which means reconciling two
different coordinate systems: Docling reports bboxes in PDF-point space with a bottom-left
origin, while Tesseract's hOCR (and every other OCR engine here) reports pixel space tied to
whatever DPI `pdftoppm` rasterized at, top-left origin. That reconciliation is exactly the full
geometric merge described below — still deferred, now with a concretely observed case rather
than a hypothetical one.

## Open follow-ups, not part of this round

- **Full geometric merge** — reconcile Docling's PDF-point/bottom-left bboxes against each OCR
  engine's pixel/top-left bboxes (Tesseract hOCR, EasyOCR/PaddleOCR boxes already emitted by
  `pdf_structure_extractor.py`), so garbled fragments of a table can be identified and dropped by
  spatial overlap rather than only when the row-clustering heuristic happens to attempt and then
  reject them. Would close the known limitation above.
- **PaddleOCR's Hindi-only recognition model** (`devanagari_PP-OCRv5_mobile_rec`, pinned in
  `extract_paddleocr_dir()`) has no English-specific recognition — mixed Hindi/English pages may
  see English text misread. Fixing this means evaluating a multilingual recognition model with
  the same rigor as the original engine evaluations above (accuracy/memory tradeoffs) — not a
  quick config change, deferred until a real document shows this as an actual problem in
  practice.
- **Increasing PaddleOCR's CPU/resource limits** — raised in passing during this evaluation
  (yesterday's Paddle run gave good results; more threads/resources could help further), not
  investigated this round. `extract_paddleocr_dir()` currently runs with whatever PaddleOCR's
  defaults are beyond the already-pinned `enable_mkldnn=False`/mobile-model settings; a future
  pass could expose `cpu_threads`/similar as a tuned config value if a real accuracy or speed
  ceiling is hit.

## Review UI changes (2026-07-16)

Testing M32/M33 against real documents in the actual review screen (not just the CLI) surfaced
UX problems fixed alongside the merge work above:

- The structure summary ("N headings, M tables detected") originally lived as a banner on the
  document page, outside the Compare & Verify modal — easy to miss since it sat behind the modal
  once opened, and the only way to inspect it was a raw-JSON link on a separate tab. It now lives
  *inside* the modal, right above the OCR-quality warning, as a collapsible "View structure"
  panel — the reviewer sees it in the same place they decide between accepting the Markdown or
  running OCR, instead of two disconnected surfaces.
- That panel renders headings as a plain list and tables via [Grid.js](https://gridjs.io/) (CDN,
  no build step — consistent with this project's existing `marked`/SweetAlert2/Chart.js CDN
  convention), giving sortable/searchable/paginated tables instead of a hand-rolled static
  `<table>`.
- The Compare & Verify modal itself is now full-screen (was a centered `min(1400px, 96vw)` box)
  — there's meaningfully more to look at per document now (original PDF, extracted Markdown,
  and the structure panel) than when the modal was first built.

## Reproducing this evaluation

```bash
storage/app/private/ocr-engines/docling/bin/docling convert --to md --to json \
    --ocr-engine tesseract --ocr-lang hin+eng \
    --output /tmp/docling-test "<path-to-pdf>"
```

Always pass `--ocr-engine`/`--ocr-lang` explicitly (Finding 2). Never pass `--force-ocr` on a
multi-page document (Finding 3). Docling's raw JSON export is large (100MB+ per document
observed) — only ever needed transiently to build the compact structure map, never worth keeping
around, same as OCR's rasterized page images.

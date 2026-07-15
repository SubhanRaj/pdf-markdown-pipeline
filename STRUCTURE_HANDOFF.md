# Three-Pass Structured OCR — Handoff Context for Claude Code

**Project:** `pdf-markdown-pipeline` (Laravel 13 / PHP 8.4 / MariaDB 12)
**Repo:** https://github.com/SubhanRaj/pdf-markdown-pipeline
**Context date:** 2026-07-15
**Purpose of this file:** Continues from an earlier OCR engine evaluation (see `OCR_HANDOFF.md` if present, and `OCR_RESEARCH.md` in the repo root). This one is about a separate, newer idea: fixing structure loss (tables, headings, layout) during PDF-to-Markdown conversion, not OCR character accuracy. Read `OCR_RESEARCH.md` first for background on the Tesseract/EasyOCR/PaddleOCR comparison already done — this is additive to that, not a replacement.

---

## 1. The problem Subhan identified

Current pipeline (`markitdown` for text-layer extraction, Tesseract for OCR) extracts text fine but loses page structure: tables collapse into run-on text, headings disappear into paragraph flow, figures/charts aren't marked. Neither `markitdown` nor plain OCR (Tesseract/EasyOCR/PaddleOCR) understands layout — they detect and recognize characters, not document structure.

## 2. Subhan's proposed fix — three-pass approach

Subhan's own idea, described in chat on 2026-07-15:

1. **Pass 1 — structural map.** Go through the whole document once and build a "table of context" — where headings are, where tables are, where figures/charts are, how content is arranged on each page. Not text extraction yet, just layout understanding.
2. **Pass 2 — raw extraction.** Run OCR (or use the existing text layer if the PDF already has one — OCR is only needed when it doesn't), but don't convert to Markdown yet. Write the extracted content somewhere in an intermediate format, preserving position/structure info from Pass 1.
3. **Pass 3 — structured reconstruction.** Convert the raw extraction into proper Markdown — correct heading levels, proper table syntax, correct reading order and indexing — using the structural map from Pass 1 to guide it.

Proposed to run locally, using Ollama for the LLM piece, on the AIO (i7-13700, 32GB RAM) already earmarked for local OCR testing (see `OCR_HANDOFF.md`).

## 3. What already exists — don't rebuild this from scratch

This is a known, actively-researched problem. Purpose-built open-source tools already implement close to this exact three-pass pattern:

- **Docling** (IBM, Apache 2.0 license, `pip install docling`) — the one to test first. Runs a dedicated layout-detection model (trained on DocLayNet: detects headings, tables, figures, lists, reading order) as an early pass, then a specialized table-structure model (TableFormer), then assembles clean Markdown from both. Has a `do_ocr` flag for scanned/no-text-layer pages. Fully local — no cloud calls, no telemetry requirement, no license key. CPU-only works; GPU just speeds it up.
- **MinerU** and **Marker** — similar layout-detection → OCR → structured-reconstruction pipelines, also open source, also locally runnable. Worth knowing as alternatives if Docling doesn't clear the bar.
- **Dolphin** (ByteDance) — vision-transformer-based, combines layout + OCR in one model. Also local.

**Key point: Pass 1 (layout/structure detection) is a specialist vision task, not a general LLM task.** A purpose-built layout model (like the one inside Docling) is far smaller and faster than running a general vision-language model through Ollama for the same job, because it's classifying regions, not generating text. Running a VLM (Qwen2-VL, MiniCPM-V, Llama 3.2 Vision) via Ollama for this on CPU-only hardware would likely cost seconds-to-tens-of-seconds *per page*, which adds up fast across a batch of other-state policy PDFs running to hundreds of pages. Use Docling's specialist model for structure detection, not a VLM.

## 4. Where Ollama / local LLM actually fits well

**Pass 3 (raw OCR text + structure map → clean Markdown) is a good fit for a local LLM via Ollama.** This is a text-in, text-out task: feed the model OCR/extraction text plus the structure/bounding-box data, ask for well-formed Markdown (correct heading levels, table syntax, reading order). A mid-size model (Qwen2.5 or Llama 3.1 class, 7B–14B) should run at reasonable speed on the AIO's CPU alone — no GPU required for this piece.

**Recommended order of attack:**
1. Test Docling on real sample PDFs (both other-state English policy scans and existing UP documents) — check how much of the structure problem it solves on its own, using its default Markdown export.
2. Only if Docling's raw output still needs cleanup/reformatting — add an Ollama pass (7B–14B text model) on top to refine what Docling already extracted, rather than trying to infer structure from nothing.
3. Don't build a custom VLM-based layout-detection pass unless both of the above fail to clear the bar — it's the most expensive option in both dev time and runtime.

## 5. Integration into the existing Laravel pipeline

Docling is Python-only (no PHP/Laravel bindings, no official REST wrapper found). This matches the existing `markitdown` integration pattern already in the repo (`innobrain/markitdown` package), so the same approach applies:

- **Option A — subprocess/CLI** (matches current `markitdown` pattern): Laravel queue job shells out via `Process::run()` to `docling input.pdf --to md` (or a small Python wrapper script using the Docling API), reads the result back. Least new surface area — closest fit to how the pipeline already works.
- **Option B — local Python microservice**: small FastAPI/Flask service running Docling, called over `localhost` HTTP from the Laravel queue job. More setup, but avoids paying Docling's layout-model load time on every single document — worth considering once processing real batch volume, less important for initial testing.

**Suggested starting point: Option A**, since it's the smallest change and matches the pattern already proven with `markitdown`. Revisit Option B if warm-start latency becomes a real bottleneck once running the actual other-state document batch.

## 6. What to help with from here (Claude Code)

- Install Docling in a venv on the AIO (alongside the EasyOCR/PaddleOCR/Tesseract test setup already planned per `OCR_HANDOFF.md`)
- Run Docling against a handful of real sample PDFs — both a text-layer-present UP document and a no-text-layer other-state scan — and compare Markdown output structure (tables, headings) against current `markitdown`/Tesseract output
- If results are promising: build a small Python wrapper script or CLI call Laravel can invoke via `Process::run()`, following the same integration shape as the existing `markitdown` package call
- If Docling's raw output needs cleanup: prototype a Pass 3 Ollama step — a prompt that takes Docling's Markdown (or raw extraction + structure data) and returns corrected Markdown, tested with a 7B–14B class model already pulled via Ollama
- Log findings the same way `OCR_RESEARCH.md` already does — what was tried, what happened, why adopted or not — so this doesn't need re-investigating from scratch later. Consider whether this belongs in `OCR_RESEARCH.md` itself or a new `STRUCTURE_RESEARCH.md`, since it's a related but distinct problem (structure loss vs. character-recognition accuracy)

## 7. Constraints to carry over from prior discussion

- Everything here is local/on-prem by default per Subhan's current plan — no cloud dependency needed for this, and Docling supports that natively
- OCR (and now structure extraction) stays human-triggered, never automatic — consistent with the existing `RunOcrExtraction` design decision
- Any new dependency (Docling's model downloads, Ollama, a Python microservice if Option B is chosen) needs the same explicit sign-off / documentation treatment already established for EasyOCR in `OCR_RESEARCH.md` — no silent swaps into production job classes

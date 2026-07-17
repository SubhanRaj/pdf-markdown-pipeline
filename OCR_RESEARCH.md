# OCR Engine Research — On-Premise Alternatives to Tesseract

**Date:** 2026-07-13 (last updated 2026-07-16)
**Status:** Live in production. Default extraction is `markitdown` (Microsoft, MIT, text-layer)
— fast and correct whenever a real text layer exists. When it's not good enough, a reviewer
picks one of **four selectable OCR engines** in the Compare & Verify modal: Tesseract
(Google/HP, default), EasyOCR (JaidedAI), PaddleOCR (Baidu), or Surya (VikParuchuri) — all wired
into `RunOcrExtraction`/`config/ocr.php`/`pdf_structure_extractor.py`. Structure detection
(tables/headings, a separate concern from character OCR) uses Docling (IBM) — see
`STRUCTURE_RESEARCH.md`.

## Why this was investigated

Real gazette text (Devanagari) coming out via Tesseract showed digit corruption (`1904` →
`4904`), stray conjunct/halant artifacts, and silent substitution of plausible English words for
misread glyphs. Two on-prem alternatives were evaluated against the same page image to see if
either clears Tesseract's accuracy ceiling without unreasonable resource cost. Cloud OCR (Google
Vision/Azure) was excluded by choice, not architectural rule — CLAUDE.md's on-prem stance
reflects a real data-privacy requirement, but stays open for a future discussion if no on-prem
engine clears the bar.

## Verdict summary

| Engine | Verdict | Key tradeoff |
|---|---|---|
| Tesseract | Default | Fast, but accuracy issues above prompted this whole investigation |
| PaddleOCR | Adopted | Best accuracy+speed once two real bugs were fixed (below); dedicated Devanagari model |
| EasyOCR | Adopted | Clear accuracy win on Tesseract's failure modes; heavier (~4.4GB peak) |
| Surya | Wired in, impractical | Correct but a vision-LLM decode with no GPU here — too slow for full pages |

## PaddleOCR — two real bugs, both fixed

`pip install paddlepaddle paddleocr` in an isolated venv. Ships a dedicated
`devanagari_PP-OCRv5_mobile_rec` recognition model — a real point in its favor for Hindi. Two
bugs blocked it initially, both root-caused and fixed:

1. **Memory** — `lang="hi"` defaults to the server-tier `PP-OCRv5_server_det` model, which tried
   to consume nearly all available RAM on a single page. Fixed by pinning
   `text_detection_model_name="PP-OCRv5_mobile_det"` explicitly.
2. **Crash** — pinning the mobile model alone crashed with a Paddle-inference error. Root cause:
   PaddleX defaults to a broken oneDNN (MKL-DNN) CPU backend for text detection, unrelated to the
   model choice. Fixed by passing `enable_mkldnn=False`.

With both fixes, a real 54-page fully-scanned document (Odisha Excise Policy) ran end-to-end
through the app's queue worker in ~14.4 minutes (~16s/page), ~880% CPU, RSS steady at
0.9–1.6GB — no crash, no manual intervention. Now the recommended engine for bulk same-language
batches. Config: `config/ocr.php`, `pdf_structure_extractor.py`'s `extract_paddleocr_dir()`.

## EasyOCR — adopted for accuracy

`pip install easyocr` (needs `numpy<2` — its PyTorch stack isn't NumPy-2-ready). Peaked ~4.4GB
during detection, settled to ~700MB after — heavy but survivable. Side-by-side against
Tesseract on the same known trouble spots (UP Beer Retail Rules gazette):

| Issue | Tesseract | EasyOCR |
|---|---|---|
| Year 1904/1910 | `4904`/`4940` (wrong) | correct |
| Devanagari conjuncts | stray ZWJ/halant artifacts | clean |
| Word hallucination | substitutes plausible English words for misread glyphs | none observed |

Adopted as a selectable engine (own venv, `storage/app/private/ocr-engines/easyocr/`,
`config/ocr.php`, `extract_easyocr_dir()`). No engine-specific regressions since.

## Surya — wired in, impractical on this hardware

Current release (`surya-ocr` 0.21.1) is a vision-language model served via `llama.cpp`
(GGUF checkpoint, ~1.2GB), not the older torch-only pipeline expected. Server starts fine
(`libggml-cpu-x64.so` backend, extracted manually into
`storage/app/private/ocr-engines/surya/llama-cpp/` — no oneDNN issue here, that was PaddleOCR's
separate bug). The problem is throughput: a single dense gazette page didn't finish within
Surya's own 600s per-request timeout, CPU-only with no GPU loaded. Left enabled in the dropdown
(not broken, just slow for large pages) — a Vulkan backend for this box's iGPU is the untested,
more-promising path if this gets revisited.

## Reproducing a comparison

```bash
python3 -m venv /path/to/venv && /path/to/venv/bin/pip install easyocr "numpy<2"
pdftoppm -png -r 300 -f 1 -l 1 <pdf_path> /path/to/out/page   # rasterize page 1
# then run a small script calling easyocr.Reader(["hi","en"], gpu=False).readtext(...)
```
Watch memory with a polling loop (`ps -o rss= -p <pid>` every 2s) — PaddleOCR's default config
will otherwise consume the whole machine unattended if the mobile-model pin is ever dropped.

## Open follow-ups, not implemented

- **PaddleOCR is Hindi-only for recognition** (`devanagari_PP-OCRv5_mobile_rec`, no
  English-specific counterpart) — on a mixed Hindi/English page, English text runs through the
  same recognizer and may be misread. Not yet an observed problem (documents tested so far are
  Hindi- or English-dominant per page, not finely interleaved). If revisited, evaluating a
  multilingual PP-OCRv5 recognition variant needs the same rigor as the comparisons above — a
  real accuracy/memory test, not a blind config swap.
- **PaddleOCR CPU/thread tuning beyond current defaults** — the Odisha run already used ~880%
  CPU without explicit thread config; whether tuning `cpu_threads` further helps hasn't been
  tested.

# OCR Engine Research — On-Premise Alternatives to Tesseract

**Date:** 2026-07-13
**Status:** Research complete for this round. **No pipeline change made** — production OCR
remains `innobrain/markitdown` (text-layer) + Tesseract (`hin+eng`, on-demand), exactly as
documented in `CLAUDE.md`. This file records what was tried and why nothing was swapped in,
so the investigation doesn't need repeating from scratch next time accuracy is questioned.

## Why this was investigated

Real, uploaded government gazette text (Devanagari) coming out of the pipeline showed digit
corruption (`1904` → `4904`), stray conjunct/halant artifacts (`प्रान्‍्त`), and occasional
silent substitution of plausible-looking English words for misread glyphs (`Ho`, `Ve`, `XC`,
`go`) — all from **Tesseract**, not from the text-layer path (confirmed by checking
`Document::find(6)`'s stored `metadata.extraction_method`, which is `"ocr"`). Two on-prem
alternatives were evaluated against the exact same page image to see whether either one
clears Tesseract's accuracy ceiling on this document class without an unreasonable resource
cost. Cloud OCR (Google Vision / Azure) was intentionally excluded from this round — on-prem
is the current preference, not a hard constraint (see note at the end).

## Candidate 1: PaddleOCR — rejected

Installed cleanly (`pip install paddlepaddle paddleocr`) in an isolated venv. It does ship a
dedicated `devanagari_PP-OCRv5_mobile_rec` recognition model (a real point in its favour —
most engines treat Hindi as an afterthought), but two separate failures disqualify it for this
deployment target:

1. **Memory.** The default `lang="hi"` preset resolves to `PP-OCRv5_server_det`, the
   server-tier detection model, not mobile. Running detection on a single 300dpi page pushed
   system-wide unused RAM from 9.7GB down to 474MB before the process was killed — i.e. it
   tried to consume essentially the entire machine for one page. This is disqualifying on its
   own for a target of "dev Mac / departmental PC / local server, no guaranteed GPU."
2. **Stability.** Pinning explicitly to `text_detection_model_name="PP-OCRv5_mobile_det"` to
   avoid the memory problem instead crashed with a Paddle-inference engine error:
   `ValueError: (InvalidArgument) Type of attribute: strides is not right` — a real
   version-compatibility bug between the installed `paddlex`/`paddlepaddle` builds, not
   something worth patching around for a dependency this heavy (PyTorch-class ML runtime).

**Verdict:** not adopted. Both the memory profile and the crash are blocking, independent of
accuracy — never got to a clean accuracy comparison because it couldn't run reliably at all.

## Candidate 2: EasyOCR — promising, not adopted yet

Installed via `pip install easyocr` (needed `numpy<2` pinned — EasyOCR's PyTorch stack hasn't
caught up to NumPy 2.x yet, otherwise `torch.from_numpy` throws `RuntimeError: Numpy is not
available`). Tested against the same page-1 image of doc 6 (UP Beer Retail Rules, 22nd
amendment — the real gazette with the known Tesseract accuracy problems).

**Memory:** peaked around 4.4GB during text detection (one-time model load + inference on a
full page), settled to ~700MB after. Heavy, but survivable — nothing like PaddleOCR's
near-total system exhaustion.

**Accuracy, side by side on the same known trouble spots:**

| Issue | Tesseract (fast, current prod) | EasyOCR |
|---|---|---|
| Year 1904 | `4904` (wrong) | `१९०४` (correct) |
| Year 1910 | `4940` (wrong) | `१९१०` (correct) |
| Devanagari conjuncts | stray `्‍` (ZWJ/halant) artifacts | clean |
| Word-level hallucination | silently substitutes plausible English words for misread glyphs (`Ho`, `Ve`, `XC`, `go`) | none observed |

EasyOCR is a genuine accuracy improvement on exactly the failure modes that prompted this
investigation, with a workable (if heavy) memory footprint. **Not integrated into the app** —
this was an isolated CLI test only, no changes to `RunOcrExtraction.php` or any job class.

**Before adopting, still needed:**
- A multi-page test (this was one page only) and a check of sustained memory when the real
  queue worker runs it back-to-back with other jobs, not in isolation.
- A decision on packaging: this needs its own venv (like markitdown's `markitdown:install`
  pattern), wired into `RunOcrExtraction.php` via `Process::run()` similar to the existing
  `tesseract` call — a new PyTorch dependency (~1GB+ install) is a meaningful addition to an
  on-premise, resource-constrained deployment target and shouldn't be silently swapped in.
- Explicit sign-off given the dependency weight, before touching production code.

## Not yet tried

**Surya OCR** — named as a candidate earlier, never actually installed or tested. If revisited,
test it the same way: isolated venv, same page image, same memory-watchdog loop, same trouble
spots (digit corruption, conjunct rendering, hallucinated words), before drawing conclusions.

## Reproducing this test

```bash
python3 -m venv /path/to/venv && /path/to/venv/bin/pip install easyocr "numpy<2"
pdftoppm -png -r 300 -f 1 -l 1 <pdf_path> /path/to/out/page   # rasterize page 1
# then run a small script calling easyocr.Reader(["hi","en"], gpu=False).readtext(...)
```
Watch memory with a simple polling loop (`ps -o rss= -p <pid>` every 2s) and kill above a hard
ceiling — PaddleOCR's default config will otherwise consume the whole machine unattended.

## Note on cloud OCR

Not evaluated in this round by explicit choice — on-premise is the current investigative focus,
not a hard architectural rule (CLAUDE.md's stated "100% on-premise" mandate reflects a real
data-privacy requirement for GO/gazette content, but the user has indicated cloud OCR APIs
remain open for a future department-level discussion if no on-prem engine clears the bar).

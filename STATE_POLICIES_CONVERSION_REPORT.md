# State Excise/Export Policies — Bulk Conversion Report

**Date:** 2026-07-21
**Run:** All 14 state policy PDFs seeded via `php artisan policies:seed` (from
`/home/subhan/Excise policies of states/`) converted to Markdown in one bulk pass — `php artisan serve`
+ multiple concurrent `php artisan queue:work` processes, `structure_engine=tesseract` (Docling
default) for all.

## Result

All 14 documents completed the pipeline, landed at `review` with all quality checks passed
(`needs_ocr_review: false` on every document), and were then **bulk-verified as-is** at the user's
request (accepted without per-document manual comparison — normally a human clicks Verify per
document in the split-pane editor; done here via the same status-transition/history-log path,
skipped for time). All 14 are now **`verified`**.

| Document | Structure engine | Headings | Tables | Notes |
|---|---|---:|---:|---|
| Excise Policy Chandigarh | tesseract | 28 | 19 | |
| Excise Policy Haryana | tesseract | 156 | 64 | |
| Excise Policy Himachal Pradesh | tesseract | 66 | 88 | converted in an earlier session |
| Excise Policy Jammu and Kashmir | tesseract | 67 | 24 | |
| Excise Policy Jharkhand | tesseract | 29 | 19 | |
| Excise Policy Madhya Pradesh | tesseract | 27 | 8 | |
| Excise Policy Odisha | tesseract | 113 | 53 | converted in an earlier session |
| **Excise Policy Punjab** | — | — | — | structure metadata missing despite `review` status — see below |
| Excise Policy Rajasthan | tesseract | 41 | 15 | |
| Excise Policy Uttar Pradesh | tesseract | 157 | 77 | |
| Export Policy Uttar Pradesh | tesseract | 157 | 77 | |
| Excise Policy Uttarakhand | tesseract | 34 | 22 | |
| Bar Policy Chhattisgarh | tesseract | 22 | 10 | |
| Excise Policy Chhattisgarh | tesseract | 34 | 10 | |

All 14 Markdown drafts live on the `public` disk at
`document_vault/department_level/excise/rules/{rule-set-slug}/{slug}_{timestamp}.md`, next to
their original PDFs.

## Bugs found and fixed during this run

1. **`/documents/pipeline` 429 Too Many Requests** — the pipeline monitor polls every in-flight
   document's `convert-status` every 5s, and that route shared the 60/min-per-user mutation rate
   limiter with actual state-changing requests. A single viewer watching this bulk run alone blew
   past it. Fixed: new `throttle:reads` limiter (600/min/user), moved `pipeline`, `convert-status`,
   `structure`, `trash`, `trash/{id}/pdf`, `bulk-upload` off `throttle:mutations` onto it. See `M35`
   in `summary.md`.
2. **Queue `retry_after` (90s) shorter than real job runtime** — concurrent `queue:work` processes
   caused the same slow OCR/Docling job to be picked up twice once it ran past 90s, since the
   database queue driver considered it abandoned. The loser of each race failed with
   `MaxAttemptsExceededException` — 12 such failures logged in `failed_jobs` during this run, one
   document (Uttar Pradesh Excise Policy) briefly left with no live job at all and needed a manual
   redispatch. Fixed: `retry_after` raised to 2000s in `config/queue.php` (committed — an initial
   `.env`-only fix was a dead end since `.env` is gitignored). See `M36` in `summary.md`.

**Separate, pre-existing gap noticed (not part of today's two bugs, not fixed):** Excise Policy
Punjab (id 14) is missing `structure_analyzed`/headings/tables metadata. Its status history shows
why — `processing → ocr_pending` (text layer flagged sparse/unreadable) → `ocr_pending → review`
via `RunOcrExtraction`. Docling's structure pass only runs inside `ConvertDocumentToMarkdown`
(Pass 0); `RunOcrExtraction` writes the OCR'd Markdown but never re-runs it. So any document whose
text layer fails the quality check and falls through to OCR ends up with OCR'd Markdown but no
heading/table structure splice — worth a look at whether `RunOcrExtraction` should trigger (or
reuse) the Docling pass too, as a follow-up.

## Next step

None required — all 14 are `verified`. Since verification was bulk-accepted rather than
per-document reviewed, it's still worth spot-checking a few of the larger/scanned ones (Odisha,
Uttar Pradesh) against the source PDF when you have time, especially Punjab given the missing
structure metadata noted above.

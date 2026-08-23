<div align="center">

<img src="public/favicon-32.png" width="64" height="64" alt="pdf-markdown-pipeline logo">

# PDF → Markdown Pipeline

**On-premise document digitization for UP Excise & Sugarcane departments**

Turns dense, scanned, or born-digital government PDFs — Government Orders, Acts, Rules, service
codes, policies — into clean, structured, AI-ready Markdown, with a human reviewer checking every
result before it's marked verified. Runs entirely on-premise: no document or page image ever
leaves the box, by design.

[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![MariaDB](https://img.shields.io/badge/MariaDB-DB-003545?logo=mariadb&logoColor=white)](https://mariadb.org)
[![OCR Engines](https://img.shields.io/badge/OCR-4%20engines-orange)](./OCR_RESEARCH.md)
[![On-Premise](https://img.shields.io/badge/deployment-on--premise-blue)](./DEPLOY.md)
[![Status](https://img.shields.io/badge/status-live-success)](https://docsrepo.exciseup.in)

</div>

---

## What it does

- **Multi-engine text extraction** — every conversion first runs `markitdown`/pdfminer (fast,
  correct whenever a real text layer exists), then a [Docling](https://github.com/docling-project/docling)
  structure-detection pass (its own ML layout/table model) fills in headings and tables the fast
  pass's own heuristic missed. A legacy-font check catches Kruti Dev/Chanakya/DevLys documents
  whose "text" decodes to readable-looking garbage.
- **OCR on demand, four engines** — Tesseract (default, Hindi + English), EasyOCR, PaddleOCR, and
  Surya, each in its own isolated Python environment. OCR triggers automatically when the text
  layer looks unreadable, or a reviewer can re-run any engine manually to compare results on a
  hard document — never a silent fallback. See [OCR_RESEARCH.md](./OCR_RESEARCH.md) for the
  engine comparison and tradeoffs.
- **Human-in-the-loop verification** — a Compare & Verify split-pane modal sits the original PDF
  next to the extracted Markdown, with live editing and a rendered preview, before a reviewer
  marks a document verified or discards the draft.
- **Government-shaped document taxonomy** — a Section/Rule-Set/Policy/Folder structure that
  mirrors real department organization (Level → Body → Section, Acts & Rules, named state
  policies with year-over-year supersession), not a generic file cabinet.
- **Maker-checker approval** — bulk-uploaded documents can be held in `pending_approval` until a
  designated approver reviews, rejects with a reason, or reclassifies them — the entire flow
  audit-logged.
- **Full Rajbhasha / Unicode support** — titles, section names, and rule-set names accept
  Devanagari natively, including combining marks, in both storage and URL slugs.
- **Full audit trail** — every document state transition and every authenticated write is logged
  with the acting user, IP, and timestamp.

## Docs

| File | Covers |
|---|---|
| [claude.md](./claude.md) | Living architecture reference — schema, routes, auth, conventions |
| [DEPLOY.md](./DEPLOY.md) | Local setup, PHP upload limits, production deployment |
| [SECURITY.md](./SECURITY.md) | Full audited security posture and checklist |
| [APP_FLOW.md](./APP_FLOW.md) | Mermaid diagrams — document lifecycle, upload taxonomy, authorization |
| [OCR_RESEARCH.md](./OCR_RESEARCH.md) | The four OCR engines compared — accuracy, speed, setup |
| [STRUCTURE_RESEARCH.md](./STRUCTURE_RESEARCH.md) | Docling structure detection — evaluation and the table/heading splice |
| [POLICY_PERIODS.md](./POLICY_PERIODS.md) | Policy taxonomy — supersession, controlled vocabularies |
| [DESIGNATIONS_PLAN.md](./DESIGNATIONS_PLAN.md) | Role/designation/privilege model |
| [summary.md](./summary.md) | What's actually built, in build order — the real changelog |
| [ROADMAP.md](./ROADMAP.md) | Planned features not yet built |

## Stack

PHP 8.4 · Laravel 13 · MariaDB · Blade + Tailwind (Play CDN) + Alpine.js (self-hosted, no build
step) · Apache · Python `markitdown`/pdfminer (text extraction) · Docling (ML structure
detection) · Tesseract / EasyOCR / PaddleOCR / Surya (OCR, each its own venv) · Parsedown +
`marked.js` (Markdown rendering) · `Grid.js` (structure tables) · database queue driver, no
Redis.

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan db:provision   # creates local MariaDB DB + user, writes .env
php artisan migrate
```

A fresh install has no accounts yet — bootstrap the first `system_admin` directly (see
[DEPLOY.md](./DEPLOY.md) for the full command), then create everyone else through `/admin/users`.

Two things run together for local dev:

```bash
php artisan serve       # http://127.0.0.1:8000
php artisan queue:work  # required — conversion/OCR jobs run through the queue
```

PHP's default upload limits (2 MB) reject real government PDFs — see
[DEPLOY.md](./DEPLOY.md#4-php-upload-limits) before testing a real upload.

## Status

Live at **[docsrepo.exciseup.in](https://docsrepo.exciseup.in)**. Document upload/browse/convert,
OCR (all four engines), Docling structure detection, maker-checker approval, policy taxonomy, and
full-text search are all built and wired up — see [summary.md](./summary.md) for exactly what's
built, what's next, and the full build history.

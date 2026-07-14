# Running & Deploying pdf-markdown-pipeline

This is an **on-premise, no-cloud** Laravel app (see [CLAUDE.md](./CLAUDE.md) for full
architecture). There is no CI/CD and no cloud provider — "deployment" here means getting the
app + its Python/OCR toolchain running correctly on a given machine: this developer Mac today,
and eventually a departmental PC or local server. This doc is the source of truth for both.

## Current machine state (this Mac, as of 2026-07-13)

| Component | State |
|---|---|
| PHP | 8.5.7 (Homebrew, `/usr/local/opt/php/bin/php`) |
| MariaDB | 12.2.2 — running via `brew services` |
| Apache (`httpd`) | Installed via Homebrew but **not running**, no vhost configured — local dev currently uses `php artisan serve`, not Apache |
| Queue | `QUEUE_CONNECTION=database`, **no persistent worker running** — must be started manually (or as a background service, see below) |
| Python (markitdown) | Self-contained venv at `vendor/innobrain/markitdown/python/venv/` (Python 3.12.13) — do not use system `/usr/bin/python3` (3.9.6, too old for the `markitdown` PyPI package) |
| Tesseract OCR | 5.5.2 + `tesseract-lang` (all langs incl. `hin`), via Homebrew |
| Poppler (`pdftoppm`, `pdfinfo`) | 26.07.0, via Homebrew |
| `APP_URL` | `http://localhost` |

## Daily local dev — starting everything

Three things must be running simultaneously for the app to work end-to-end (web + queue +
DB). Node/npm are **not** required — despite `composer.json`'s default `dev` script
referencing `npm run dev`, this project ships no compiled frontend assets (Tailwind Play CDN,
no build step — see CLAUDE.md's Tech Stack table). Don't run the stock `composer run dev`
script as-is; start these individually instead:

```bash
brew services list                 # confirm mariadb is "started"; start if not:
brew services start mariadb

php artisan serve                  # terminal 1 — http://127.0.0.1:8000
php artisan queue:work             # terminal 2 — required for markdown conversion jobs to run
```

Without `queue:work` running, clicking "Convert to Markdown" on a document dispatches the job
to the `jobs` table and it just sits there — nothing processes it until a worker picks it up.
`queue:listen` (auto-reloads on code changes) is fine for active job-code development;
`queue:work` is what you'd actually run day-to-day since it doesn't reload and is measurably
faster.

Optional: `php artisan pail` in a third terminal for live log tailing instead of tailing
`storage/logs/laravel.log` by hand.

## Fresh machine setup (new Mac, departmental PC, or local server)

Follow in order — later steps depend on earlier ones (the markitdown venv step specifically
depends on a working Python 3.10+ being on `PATH` before `composer install` runs its
post-install hook).

### 1. System packages

**macOS (Homebrew):**
```bash
brew install php@8.4 mariadb httpd composer tesseract tesseract-lang poppler python@3.12
brew services start mariadb
```

**Debian/Ubuntu:**
```bash
sudo apt install php8.4 php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl \
                  mariadb-server apache2 composer \
                  tesseract-ocr tesseract-ocr-hin tesseract-ocr-eng poppler-utils python3.12 python3.12-venv
sudo systemctl enable --now mariadb apache2
```

**RHEL/CentOS:** equivalent packages via `dnf`, package names vary by EPEL/Remi repo — see
CLAUDE.md's PHP upload-limits section for the RHEL php.ini path convention used elsewhere in
this doc.

Confirm Hindi is actually installed, not just the base package (a common miss —
`tesseract-ocr` alone only ships `eng`):
```bash
tesseract --list-langs   # must include "hin" and "eng"
```

### 2. Python version — the step that silently breaks markitdown if skipped

The `innobrain/markitdown` Composer package creates its own venv by running whatever `python3`
resolves to on `PATH`. If that resolves to an old system Python (macOS ships 3.9.6 at
`/usr/bin/python3`), `pip` will be too old to resolve the real `markitdown` PyPI package and
`markitdown:install` fails with a confusing "no matching distribution" error pointing at a
stale `0.0.1a1` pre-release.

**Fix before running `composer install`** — make sure a modern `python3` (3.10+) wins on
`PATH`:

```bash
# macOS — Homebrew's python@3.12 installs versioned binaries only; symlink python3 explicitly
ln -sf /usr/local/opt/python@3.12/bin/python3.12 /usr/local/bin/python3
hash -r
python3 --version   # must print 3.10+, not 3.9.x
```

On Linux this is rarely an issue since distro Python is usually current enough — but run the
same `python3 --version` check first regardless.

### 3. Project setup

```bash
git clone <repo> pdf-markdown-pipeline && cd pdf-markdown-pipeline
composer install
# post-autoload-dump automatically runs `php artisan markitdown:install`,
# which creates vendor/innobrain/markitdown/python/venv/ and pip-installs markitdown[all].
# This step downloads ~150MB of Python deps (pandas, onnxruntime, etc.) and can take
# several minutes — if it times out at 300s, re-run the pip install directly with a longer
# timeout instead of re-running the whole artisan command:
#   cd vendor/innobrain/markitdown/python && ./venv/bin/pip install -r requirements.txt

php artisan key:generate

php artisan db:provision
# Dev-only tool (subhanraj/laravel-db-provisioner, require-dev — see
# https://github.com/SubhanRaj/laravel-db-provisioner). Copies .env.example to .env if missing,
# generates a random per-project database name/user/16-char password, writes them into .env, then
# prompts for your MariaDB/MySQL *admin* username/password (e.g. root) once to create that
# database + user via CREATE DATABASE/CREATE USER — your real admin credentials are never written
# to .env or stored anywhere. Skip this and edit .env's DB_* fields by hand instead if you'd rather
# reuse an existing database (e.g. a shared/production one).

php artisan migrate
php artisan db:seed --class=UserSeeder   # demo accounts — see CLAUDE.md table; change passwords before real use
php artisan storage:link
```

Verify the toolchain actually works before trusting the app:
```bash
./vendor/innobrain/markitdown/python/venv/bin/markitdown --version   # → markitdown 0.1.3
tesseract --version                                                   # → tesseract 5.5.x
pdftoppm -v                                                           # → poppler 26.x
```

### 4. PHP upload limits

Already handled by `public/.htaccess` (Option A in CLAUDE.md) — works immediately under
Apache + `mod_php` provided `AllowOverride All` (or `AllowOverride Options FileInfo`) is set
in the vhost's `<Directory>` block. If serving via `php-fpm` instead, use `public/.user.ini`
(Option B) — see CLAUDE.md for both. Do not skip this: PHP's stock 2MB upload limit rejects
real government PDFs immediately.

## Apache production vhost (not yet configured on this Mac)

No vhost currently exists for this project — `httpd` is installed but not running, and
`/usr/local/etc/httpd/extra/httpd-vhosts.conf` still has Homebrew's dummy example entries.
When this moves off `php artisan serve` onto real Apache (departmental PC / local server):

```apache
<VirtualHost *:80>
    ServerName pdf-pipeline.local
    DocumentRoot "/path/to/pdf-markdown-pipeline/public"

    <Directory "/path/to/pdf-markdown-pipeline/public">
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog "/usr/local/var/log/httpd/pdf-pipeline-error_log"
    CustomLog "/usr/local/var/log/httpd/pdf-pipeline-access_log" common
</VirtualHost>
```

Then uncomment the `Include /usr/local/etc/httpd/extra/httpd-vhosts.conf` line in
`/usr/local/etc/httpd/httpd.conf` (currently commented out, line ~507) if not already active,
and:
```bash
apachectl configtest        # must print "Syntax OK" before restarting
brew services restart httpd
```

Update `.env`: `APP_URL=http://pdf-pipeline.local` (or the real LAN hostname/IP for a
departmental deployment), then `php artisan optimize:clear` — stale cached config referencing
the old `APP_URL` is a common source of broken asset/route URLs after this switch.

For Linux (`systemctl restart apache2`) the same `<VirtualHost>` block goes in
`/etc/apache2/sites-available/`, enabled via `a2ensite` + `a2enmod rewrite`.

## Keeping the queue worker running persistently

`php artisan queue:work` in a foreground terminal is fine for active development but dies the
moment the terminal closes or the machine reboots — not acceptable for a departmental PC that
needs to process conversions unattended. No persistent worker is currently configured on this
Mac. Options, in order of how much this project actually needs right now:

**Simplest — `queue:work` with auto-restart via a loop** (fine for a single-user dev machine,
not recommended long-term):
```bash
while true; do php artisan queue:work --tries=3 --timeout=900; sleep 2; done
```

**macOS — `launchd` (recommended for the departmental Mac)**: create
`~/Library/LaunchAgents/com.pdfpipeline.queue.plist` pointing at
`php /path/to/artisan queue:work --tries=3 --timeout=900`, with `RunAtLoad` and `KeepAlive`
both `true`, then `launchctl load` it. This survives reboots and restarts the worker if it
crashes.

**Linux — `systemd` (recommended for a local Linux server)**:
```ini
# /etc/systemd/system/pdf-pipeline-queue.service
[Unit]
Description=pdf-markdown-pipeline queue worker
After=network.target mariadb.service

[Service]
User=www-data
WorkingDirectory=/path/to/pdf-markdown-pipeline
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=900
Restart=always

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl enable --now pdf-pipeline-queue
```

`--timeout=900` matches both `ConvertDocumentToMarkdown::$timeout` and
`RunOcrExtraction::$timeout` — OCR (`RunOcrExtraction`, the explicit-trigger job) on a
multi-page scanned Gazette PDF can legitimately take several minutes; don't lower this without
checking real conversion times first. After deploying code changes that touch a job class,
restart the worker (`queue:restart` signals workers to finish their current job and exit; the
supervisor — launchd/systemd/the loop above — then starts a fresh one that picks up the new
code — **this applies to any edit to an `App\Jobs\*` class**, PHP does not hot-reload a running
worker's in-memory bytecode):
```bash
php artisan queue:restart
```

## Verifying a deployment

```bash
php artisan tinker --execute="echo config('markitdown.use_venv_package') ? 'ok' : 'MISCONFIGURED';"
./vendor/innobrain/markitdown/python/venv/bin/markitdown --version
tesseract --list-langs | grep -E "^(hin|eng)$"
pdftoppm -v
php artisan queue:work --once   # process exactly one queued job, confirm it completes, then Ctrl+C
```

Then in a browser: log in as a seeded admin account (see CLAUDE.md's seeder table for demo
credentials), open any document, click **Convert to Markdown**, and confirm the status
badge moves `Processing` → `Review` and the Formatted/Raw markdown card renders. This
exercises the full chain (Apache/serve → queue table → worker → markitdown → file write →
status update) in one action, and is a more reliable check than any of the individual CLI
checks above. OCR is a separate, explicit "Run OCR-Based Extraction" trigger inside the
Compare & Verify modal — it never runs automatically, so it isn't part of this basic check;
verify it separately if the deployment needs to confirm Tesseract specifically.

Two more pages worth checking on a fresh deploy, since they read live DB state rather than
just static config:
- `/documents/pipeline` — table of every document not yet verified, with live status polling.
  If `queue:work` isn't running, documents will visibly sit at `Uploaded` here forever — this
  page is the fastest way to notice a dead/un-started worker without digging into the `jobs`
  table by hand.
- `/documents/bulk-upload` — the department/section/division/folder/rule-set picker. If it
  renders empty ("You don't have upload access..."), check the logged-in user's
  `department_id`/`section_id`/`division_id` and privileges (`User::uploadScope()`) rather
  than assuming the page is broken — an empty picker is often correct behaviour for a
  narrowly-scoped operator account.

## Known local constraints

- **No CI/CD** — this is a single-machine, on-premise deployment with no automated pipeline.
  All steps above are manual by design; do not introduce a hosted CI service without a
  reason grounded in an actual multi-developer or multi-environment need.
- **No Redis, no S3, no managed services anywhere** — `QUEUE_CONNECTION=database` and a
  single local filesystem disk are deliberate architecture decisions (CLAUDE.md, "Architecture
  decisions already made"), not gaps to fill in later.
- **The markitdown venv is per-checkout, not shared** — a fresh `git clone` on a new machine
  needs its own `composer install` → `markitdown:install` cycle; the venv is gitignored and
  is not something to copy between machines (Python wheel binaries are platform-specific).
- **The alternative OCR engine venvs (EasyOCR/PaddleOCR/Surya) are also per-checkout and not
  provisioned by `composer install`** — see "Alternative OCR engines" below; they must be set up
  once per machine, same platform-specific-binary caveat as the markitdown venv above.

## Alternative OCR engines (EasyOCR / PaddleOCR / Surya)

Added 2026-07-14 on the Ubuntu i7-13700 box, alongside the default Tesseract path (see
`CLAUDE.md`'s Text Extraction section and `OCR_RESEARCH.md`). Each engine lives in its own venv
under `storage/app/private/ocr-engines/{engine}/`, registered in `config/ocr.php` and selectable
from the Compare & Verify modal's engine dropdown — Tesseract stays the default and none of this
is provisioned automatically by `composer install`.

**Why a separate pyenv-managed Python, not the system one:** these engines' PyTorch/Paddle wheels
don't yet support very new Python releases (this box's system Python was 3.14) — use
[pyenv](https://github.com/pyenv/pyenv) to install a 3.12.x interpreter and build each venv from
that instead of `/usr/bin/python3`:

```bash
pyenv install 3.12.8   # if not already installed
PY312="$(pyenv root)/versions/3.12.8/bin/python3"

$PY312 -m venv storage/app/private/ocr-engines/easyocr
storage/app/private/ocr-engines/easyocr/bin/pip install "numpy<2" easyocr

$PY312 -m venv storage/app/private/ocr-engines/paddleocr
storage/app/private/ocr-engines/paddleocr/bin/pip install paddlepaddle paddleocr

$PY312 -m venv storage/app/private/ocr-engines/surya
storage/app/private/ocr-engines/surya/bin/pip install surya-ocr requests
```

**PaddleOCR needs one extra fix:** PaddleX's default oneDNN (MKL-DNN) CPU backend crashes on this
Paddle build (`NotImplementedError: ConvertPirAttribute2RuntimeAttribute not support
[pir::ArrayAttribute<pir::DoubleAttribute>]`) — already worked around in
`pdf_structure_extractor.py` via `enable_mkldnn=False`, nothing to do here, just don't remove
that flag if refactoring that file.

**Surya needs a `llama.cpp` binary + shared libs, which are not a pip dependency** — its current
release runs OCR through a real vision-LLM served by `llama-server`. On Debian/Ubuntu, extract
(don't `apt install`, no sudo needed) the binary and libs directly from the distro packages into
the engine's own venv dir:

```bash
cd storage/app/private/ocr-engines/surya
mkdir -p llama-cpp/bin llama-cpp/lib/ggml/backends0
apt-get download llama.cpp-tools libllama0 libggml0
for deb in *.deb; do dpkg-deb -x "$deb" extracted; done
cp extracted/usr/bin/llama-server llama-cpp/bin/
cp extracted/usr/lib/x86_64-linux-gnu/*.so* llama-cpp/lib/ 2>/dev/null
cp extracted/usr/lib/x86_64-linux-gnu/llama/*.so* llama-cpp/lib/
cp extracted/usr/lib/x86_64-linux-gnu/ggml/backends0/*.so llama-cpp/lib/ggml/backends0/
rm -rf extracted *.deb
```

`RunOcrExtraction` passes `LLAMA_CPP_BINARY`/`LD_LIBRARY_PATH`/`GGML_BACKEND_PATH` (pointing at
`libggml-cpu-x64.so`, the generic CPU backend variant — safe on any x86-64, not the fastest
possible for this specific CPU) through `Process::env()` from `config('ocr.engines.surya.env')`;
no shell profile changes needed. **Known limitation, not a bug:** CPU-only inference of Surya's
vision-LLM does not reliably finish a single dense A4 page within its own 600-second timeout on
this hardware — see `OCR_RESEARCH.md` for the Vulkan/iGPU acceleration option that wasn't pursued.

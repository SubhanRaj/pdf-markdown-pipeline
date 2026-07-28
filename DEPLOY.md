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

## Production deployment (Ubuntu server, `docsrepo.exciseup.in`)

The live deployment does **not** use `php artisan serve` — that's dev-only. Production is
Apache (`mod_php`) + Cloudflare Tunnel, both supervised by systemd user services alongside the
queue worker:

- **Apache vhost** — `/etc/apache2/sites-available/pdf-markdown-pipeline.conf`, listening on
  `127.0.0.1:8080` (not `:80` — that port stays on the box's default site so this vhost can't
  collide with anything else already using it):
  ```apache
  <VirtualHost *:8080>
      ServerName docsrepo.exciseup.in
      DocumentRoot ~/Sites/pdf-markdown-pipeline/public

      <Directory ~/Sites/pdf-markdown-pipeline/public>
          Options -Indexes +FollowSymLinks
          AllowOverride All
          Require all granted
      </Directory>

      ErrorLog ${APACHE_LOG_DIR}/pdf-markdown-pipeline-error.log
      CustomLog ${APACHE_LOG_DIR}/pdf-markdown-pipeline-access.log combined
  </VirtualHost>
  ```
  `Listen 8080` added to `/etc/apache2/ports.conf`, enabled via `a2ensite`. `mod_rewrite` and
  `mod_php` were already enabled on this box.
- **Permissions gotcha** — Apache runs as `www-data`, which by default can't even traverse into
  a user's home directory (`~` ships `750`). Two fixes were required, both one-time:
  `chmod o+x ~` (traverse-only, doesn't expose file listings) and
  `chown -R subhan:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache` so
  Apache can write logs/cache/sessions without changing ownership away from `subhan`.
- **Cloudflare Tunnel** (`~/.cloudflared/config.yml`) points at the vhost, not at `artisan
  serve`'s old `:8000`:
  ```yaml
  ingress:
    - hostname: docsrepo.exciseup.in
      service: http://127.0.0.1:8080
    - service: http_status:404
  ```
- **`pdf-pipeline-app.service`** (the old `artisan serve` systemd unit) is stopped and disabled
  — Apache replaces it entirely. `pdf-pipeline-queue.service` (queue worker) and
  `pdf-pipeline-tunnel.service` (cloudflared) are unaffected and still required.
- **Cloudflare's own edge upload cap** — a hostname proxied through Cloudflare (which a Tunnel
  hostname always is) is subject to Cloudflare's per-plan max request body size **regardless of
  any origin/Apache/PHP setting**: Free/Pro = 100 MB, Business = 200 MB, Enterprise = up to 500 MB
  (configurable). Raising `upload_max_filesize`/the app's validation limit (currently 300 MB, see
  CLAUDE.md's "PHP upload limits") only helps for uploads that reach Apache — a file over the
  zone's Cloudflare plan cap is rejected at Cloudflare's edge before it ever reaches this box. If a
  document is legitimately larger than the zone's plan allows, either upload it from a machine on
  the same LAN hitting `http://127.0.0.1:8080` directly (bypassing the tunnel entirely), or raise
  the Cloudflare plan/limit for this zone.
- **`ProtectHome` gotcha** — Ubuntu's `apache2.service` systemd unit ships with
  `ProtectHome=read-only`, which makes all of `/home` (including this app) read-only to Apache
  regardless of Unix file permissions. Since the app lives under `~`, this blocked
  every write (logs, Blade view cache, sessions) with confusing `tempnam()`/"Read-only file
  system" errors even though `storage/`/`bootstrap/cache` were correctly `775`. Fixed with a
  drop-in at `/etc/systemd/system/apache2.service.d/override.conf`. **This same file also carries
  `ProcSubset=all`** (added 2026-07-25 so PHP can read `/proc/meminfo` for the Pipeline Health
  dashboard — see `infra-notes/cpu-thermal-and-apache-procfs.md` for why) — **both directives must
  stay in this one file together**:
  ```ini
  [Service]
  ReadWritePaths=~/Sites/pdf-markdown-pipeline/storage ~/Sites/pdf-markdown-pipeline/bootstrap/cache
  ProcSubset=all
  ```
  ⚠️ **If you ever need to touch this file again, always read its current contents first and
  amend in place — never blindly `tee`/overwrite it.** Doing exactly that on 2026-07-25 silently
  dropped `ReadWritePaths` while adding `ProcSubset`, which took the live site down (every request
  failing on "Read-only file system" writing to `storage/`) until both lines were restored
  together. After any edit: `sudo systemctl daemon-reload && sudo systemctl restart apache2`. Only
  needed because the app sits under `/home`; deploying under `/var/www` instead avoids this
  `ProtectHome` half of it entirely (the `ProcSubset` half would still be needed either way).

After changing the vhost or tunnel config: `sudo systemctl reload apache2` and
`systemctl --user restart pdf-pipeline-tunnel.service` respectively.

For a from-scratch setup on a new box, the general pattern (any Linux, any paths) is the same
`<VirtualHost>` block above with your own paths, dropped in `/etc/apache2/sites-available/`,
enabled via `a2ensite` + `a2enmod rewrite`, plus `apachectl configtest` before reloading.

## Mail (onboarding + login-OTP emails, added 2026-07-26)

Onboarding links and login OTP codes are real emails now — `MAIL_MAILER=log` (the safe default
in `.env.example`) just no-ops them, so nothing breaks with no config, but no officer receives
anything either. Two supported paths, `.env`-only switch, nothing in the app references either
transport directly:

- **Resend** (recommended, currently active): `MAIL_MAILER=resend`, `RESEND_API_KEY=<key>`. Needs
  Laravel's native `resend` transport dependency, `resend/resend-php` (already in
  `composer.json` — nothing further to install). Note: `symfony/resend-mailer` looks like the
  obvious package name but is the *wrong* one — Laravel's own `MailManager::createResendTransport()`
  instantiates `Resend\Contracts\Client` via `Resend::client()`, which only `resend/resend-php`
  provides; the Symfony bridge was tried first here and failed with "Class Resend not found."
- **SMTP** (NIC/Gmail/etc): `MAIL_MAILER=smtp`, plus `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/
  `MAIL_PASSWORD`/`MAIL_SCHEME` for whichever provider.

Either way, `MAIL_FROM_ADDRESS=noreply@mail.exciseup.in` / `MAIL_FROM_NAME="UP Excise Document
Vault"` — same address already in use for this purpose elsewhere. After changing any `MAIL_*`
value: `php artisan config:clear` (or `optimize:clear` if configs are cached).

## Keeping the queue worker running persistently

`php artisan queue:work` in a foreground terminal is fine for active development but dies the
moment the terminal closes or the machine reboots — not acceptable for a departmental PC that
needs to process conversions unattended. No persistent worker is currently configured on this
Mac. Options, in order of how much this project actually needs right now:

**Simplest — `queue:work` with auto-restart via a loop** (fine for a single-user dev machine,
not recommended long-term):
```bash
while true; do php artisan queue:work --tries=3 --timeout=1900; sleep 2; done
```

**macOS — `launchd` (recommended for the departmental Mac)**: create
`~/Library/LaunchAgents/com.pdfpipeline.queue.plist` pointing at
`php /path/to/artisan queue:work --tries=3 --timeout=1900`, with `RunAtLoad` and `KeepAlive`
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
ExecStart=/usr/bin/php artisan queue:work --tries=3 --timeout=1900
Restart=always

[Install]
WantedBy=multi-user.target
```
```bash
sudo systemctl enable --now pdf-pipeline-queue
```

**Laravel Pulse's Servers card** (2026-07-25) needs its own always-running process,
`php artisan pulse:check` — same pattern, separate unit
(`pdf-pipeline-pulse.service` in this deployment, a `--user` unit alongside
`pdf-pipeline-queue.service` rather than a system one, since it doesn't need
`www-data` — it only reads `/proc`/`/sys` and writes to the `pulse_*` tables):
```ini
[Unit]
Description=pdf-markdown-pipeline Pulse server-metrics recorder
After=network.target mariadb.service

[Service]
WorkingDirectory=/path/to/pdf-markdown-pipeline
ExecStart=/usr/bin/php artisan pulse:check
Restart=always
RestartSec=2

[Install]
WantedBy=default.target
```
Deliberately run as a plain CLI process, not through Apache — Apache's systemd unit has its own
`ProcSubset=pid` hardening (see the `ProtectHome`/`ProcSubset` note above) that would otherwise
block it from reading `/proc/meminfo` the same way it blocked the Pipeline Health endpoint until
that override was added.

`--timeout=1900` is just the worker's *default* for jobs that don't declare their own — Laravel
enforces a job's own `public int $timeout` property via `pcntl_alarm` regardless of what
`--timeout` says (`Worker::timeoutForJob()`), so it's not a ceiling on `RunOcrExtraction` or
`ConvertDocumentToMarkdown`. `ConvertDocumentToMarkdown::$timeout` is a flat 1200s.
`RunOcrExtraction::$timeout` (2026-07-28) is no longer flat — it scales with the PDF's page count
(`RunOcrExtraction::timeoutForDocument()`, checked via `pdfinfo` at dispatch time, before the job
is serialized onto the queue):

| Pages | Timeout |
|---|---|
| ≤ 50 | 1900s (~32 min) |
| 51–150 | 3600s (1 hr) |
| 151–250 | 5400s (1.5 hr) |
| 251–500 | 7200s (2 hr) |
| 501–1000 | 10800s (3 hr) |
| 1000+ | 14400s (4 hr) |

A 500-page scanned document (e.g. a large Gazette PDF needing OCR) used to get killed and
restarted from page 1 every ~32 minutes under the old flat 1900s timeout — this fixes that at the
source instead of needing an ever-larger flat number. After deploying code changes that touch a
job class, restart the worker (`queue:restart` signals workers to finish their current job and
exit; the supervisor — launchd/systemd/the loop above — then starts a fresh one that picks up the
new code — **this applies to any edit to an `App\Jobs\*` class**, PHP does not hot-reload a
running worker's in-memory bytecode):
```bash
php artisan queue:restart
```

**`retry_after` must exceed the *largest possible* job timeout** — `config('queue.connections.database.retry_after')`
is 14700s (`config/queue.php`), just above `RunOcrExtraction`'s new 14400s ceiling for 1000+ page
scans. This isn't optional headroom: the database queue driver considers a job "abandoned" and
hands it to another worker once `retry_after` seconds pass, even if the original worker is still
legitimately running it. Found this the hard way running a bulk 14-document backfill with several
concurrent `queue:work` processes against Laravel's stock 90s default — every OCR pass past 90s
got picked up a second time by another worker, and the loser of the race failed with
`MaxAttemptsExceededException`, wasting a full duplicate OCR/Docling run for nothing. Keep
`retry_after` above whatever the largest job timeout can now reach whenever either changes.

**Running more than one `queue:work` process concurrently** (for throughput on a bulk
backfill, or a multi-core departmental server) is safe with the database queue driver — it
row-locks each job on pop, so two workers never process the same *available* job — as long as
`retry_after` is correctly set above; that's the only thing that made concurrent workers unsafe
here.

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

## Docling (structure detection)

Added 2026-07-15, same per-checkout venv convention as the OCR engines above — not provisioned
by `composer install`, must be set up once per machine:

```bash
$PY312 -m venv storage/app/private/ocr-engines/docling
storage/app/private/ocr-engines/docling/bin/pip install docling
```

Verify: `storage/app/private/ocr-engines/docling/bin/docling --version` (confirmed working:
`Docling version: 2.113.0` on the Ubuntu AIO box). No extra binaries or system packages needed
beyond the venv itself — unlike Surya's `llama.cpp` dependency above.

**Always pass `--ocr-engine`/`--ocr-lang` explicitly when invoking Docling directly** — its
default OCR backend (RapidOCR) silently resolves to a Chinese-pretrained model otherwise (see
`STRUCTURE_RESEARCH.md` Finding 2). The app's own `config/docling.php` and
`ConvertDocumentToMarkdown::runDoclingStructureAnalysis()` already do this correctly; this note
is only for anyone testing the CLI by hand.

**Never pass `--force-ocr` on a multi-page document** — timed out past 10 minutes with zero
output on a 112-page real document during evaluation (Docling has no partial-result streaming).
Default mode (OCR only on detected bitmap regions, trust the text layer elsewhere) is the only
mode this app uses in production.

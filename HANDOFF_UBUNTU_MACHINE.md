# Handoff — New Ubuntu Dev/OCR Box

**Written:** 2026-07-14, on the Mac, for continuation by Claude Code running in VS Code on the
new Ubuntu Desktop 26.04 LTS box (i7-13700, 32GB RAM). This is a working dump, not a permanent
doc — read it once, act on it, then it can be deleted/archived once the machine is settled.

Read `CLAUDE.md`, `DEPLOY.md`, and `OCR_RESEARCH.md` in this repo root for full project context
before doing anything below — this file only covers what changed *this session* and what's left.

---

## 1. Machine provisioning — already done via script

A provisioning script (bash, not Ansible — deliberate call: one-time single-desktop setup
doesn't justify Ansible's inventory/playbook overhead) was run on this box. Canonical copy lives
as a public gist, **not** in this repo (removed from `scripts/` on purpose — no need to duplicate
it once the gist was live):

**https://gist.github.com/SubhanRaj/f330f93abf1c6bc38ab1362a7ea455e8**

What it installed: git, PHP (tries `packages.sury.org` for 8.4 first, falls back to Ubuntu's
bundled PHP if that repo has no build yet for this Ubuntu codename — `composer.json` only needs
`^8.3` so either is fine), MariaDB (user/password entered interactively at run time, not
hardcoded), Apache, Composer, Node 24 + pnpm (via corepack), Python 3 (whatever Ubuntu 26.04
ships as default — already newer than the Mac's Homebrew 3.12), Tesseract (`hin`+`eng`) +
Poppler, VS Code, Warp, Ollama, Cloudflare toolchain (`wrangler` + `cloudflared`, with the same
codename-fallback pattern as PHP), oh-my-zsh + Powerlevel10k, and a handful of CLI QoL tools
(btop-equivalent via htop, tmux, fzf, ripgrep, bat, jq, tree, fd, zoxide).

**Claude Code** was installed via Anthropic's native curl installer, not npm —
`curl -fsSL https://claude.ai/install.sh | bash` — deliberately, per explicit preference (npm
global installs were flagged as a perf/reliability annoyance). It drops the binary in
`~/.local/bin`. The script's generated `.zshrc` already has `~/.local/bin` on `PATH` (this was a
real bug caught and fixed mid-session — earlier runs before the fix would show `claude` as
"command not found" until a new shell was opened or `~/.local/bin` was added manually).

The script's error handling: no `set -e` — an `ERR` trap logs every failed step loudly and lets
the script keep going, printing a full "these steps failed" summary at the end. It's fully
idempotent (every install checks "already present?" first), so if anything is in that failed-list,
just re-run the script from the gist — it'll only redo what's missing.

**Known, already-handled non-failures:**
- `packages.sury.org` and `pkg.cloudflare.com` are both built per-Ubuntu-codename — on a
  brand-new release they may 404 until the maintainer rebuilds. The script already falls back
  automatically (Ubuntu's own PHP; direct `.deb` download for `cloudflared`).
- **Playwright** (`npx playwright install --with-deps chromium`) failed during the run — this is
  expected, not a bug. Playwright needs to run *inside* a repo that has `@playwright/test` in its
  `package.json` to know which browser build to fetch. It's needed for
  `~/Projects/excise-revenue-recovery-portal/frontend`'s e2e tests — run it there, after
  `npm install`, when you get to that project:
  ```bash
  npx playwright install --with-deps
  ```

## 2. Fonts — install directly on Ubuntu, no scp needed

Checked `~/fonts`, `/fonts`, `~/Library/Fonts`, `/Library/Fonts` on the Mac — nothing custom
beyond the 4 standard MesloLGS NF files Powerlevel10k needs (no other personal fonts to carry
over). Install them **system-wide** (not user-local) directly on the Ubuntu box:

```bash
sudo mkdir -p /usr/local/share/fonts/MesloLGS-NF
cd /tmp
for style in Regular Bold Italic "Bold%20Italic"; do
  curl -fLo "MesloLGS NF ${style//%20/ }.ttf" "https://github.com/romkatv/powerlevel10k-media/raw/master/MesloLGS%20NF%20${style}.ttf"
done
sudo mv "MesloLGS NF"*.ttf /usr/local/share/fonts/MesloLGS-NF/
sudo fc-cache -f
```

Then set the terminal's font to **MesloLGS NF** (Warp: Settings → Appearance → Text → Font).

Still need to bring over: `~/.p10k.zsh` from the Mac (this is a generated config file, not
something worth hand-rewriting):
```bash
scp subhan@192.168.29.42:~/.p10k.zsh ~/.p10k.zsh
```
(Mac IP may have changed since — re-check via System Settings → Wi-Fi → Details on the Mac if
this doesn't connect. Requires Remote Login enabled on the Mac: System Settings → General →
Sharing → Remote Login.)

## 3. `.zshrc` — build it fresh here, don't port the Mac's

Explicit decision this session: **do not copy the Mac's `.zshrc` verbatim.** It's full of
Homebrew-specific macOS paths (`/usr/local/opt/php`, `HOMEBREW_PREFIX`, `path_helper`, an
Antigravity IDE PATH entry) that mean nothing on Linux and would just be dead weight/confusion.

The provisioning script already writes a fresh, Linux-native `.zshrc` from scratch (oh-my-zsh +
Powerlevel10k theme, `plugins=(git)`, Composer bin + `~/.local/bin` on `PATH`) — it is **not** a
copy of the Mac's file. It only runs once (guarded by a `~/.zshrc.provisioned` marker) so it
won't clobber further hand-edits.

**What's being asked of you (Claude Code, running here in VS Code):** review/extend that
generated `.zshrc` as needed for this box specifically — more oh-my-zsh plugins if useful
(`zsh-autosuggestions`, `zsh-syntax-highlighting` are common additions not in the base script),
any aliases worth adding for this workflow, etc. Don't just diff it against the Mac's file and
port differences blindly — decide what actually makes sense for a Linux dev/OCR-testing box.

## 4. Still to do manually (not scripted, needs a human or a fresh decision)

- SSH key for GitHub (`ssh-keygen -t ed25519 -C "..."`) + `git config --global user.name/email`
- Clone this repo into `~/Sites`, `composer install`, `.env` + `key:generate`, `migrate`,
  `db:seed --class=UserSeeder`, `storage:link` — see `DEPLOY.md` "Fresh machine setup" for the
  full sequence (identical on this box; the provisioning script covers everything above the
  project-specific steps in that doc).
- `gh` (GitHub CLI) and any other personal tools — user said they'll install these themselves,
  not part of the provisioning script.
- VS Code / Warp / Claude Code sign-ins (OAuth — can't be scripted).

## 5. Why this project needs a new machine at all — OCR task context

New task from the Excise Commissioner: convert excise **policy documents from other Indian
states** (not UP) into Markdown. Mix of English/Hindi, **none have a selectable text layer** —
OCR is mandatory for this batch, unlike UP's own document flow where OCR stays optional/on-demand
only after a bad text-layer result (see `CLAUDE.md`'s pipeline section — that human-gated design
is not changing).

This Ubuntu box (i7-13700/32GB) exists specifically to be a closer hardware proxy to production
than a dev laptop, for re-testing OCR engines under realistic conditions. Plan, from
`OCR_RESEARCH.md`'s open threads:
1. Baseline: plain Tesseract `eng` (not `hin`) against real "other state" English policy PDFs —
   Tesseract's English accuracy is historically much better than Devanagari; check whether
   preprocessing (DPI/deskew/binarization) is the actual gap before assuming a new engine is
   needed.
2. Re-run EasyOCR: multi-page (not single-page like the original test), with a queue worker
   running concurrently, to get a realistic memory/stability read under load.
3. Retry PaddleOCR once more with 32GB of headroom and a clean venv — the original failure
   (RAM exhaustion + a paddlex/paddlepaddle version-compat crash) was on a lower-resource dev
   machine.
4. Only if none of the above clear the accuracy bar: cloud OCR APIs (Google Vision, Azure
   Document Intelligence, Cloudflare Workers AI's Moondream 3) become fair game for this specific
   batch — **important scope clarification, confirmed explicitly by Subhan this session, may not
   yet be reflected in `CLAUDE.md`'s wording:** the "100% on-premise" principle is about where
   *confidential documents live at rest* (the auth-gated vault, private disk), not a ban on where
   OCR *compute* can run. Real production target is SDC/NIC's Meghraj cloud PaaS, not necessarily
   a fully on-prem box. Cloud OCR is a weaker privacy concern for this specific batch too, since
   these source PDFs are other states' *already-published, public* policies — not UP's own
   confidential GOs. Still: exhaust local/on-prem options first, cloud only if none clear the bar.
5. If/when a new engine is adopted: wire it into `RunOcrExtraction.php` as an *additional*
   reviewer-selectable option (never a silent replacement for Tesseract), record which engine
   produced a draft via `metadata.extraction_method`. OCR stays human-triggered only — that
   constraint is not up for revisiting as part of this task.
6. Keep `OCR_RESEARCH.md` updated in the same log-of-record style as its existing entries.

## 6. Two known-open items from earlier passes, unrelated to the above, worth remembering

- **`SECURITY.md` H-03** (open): `requires_approval` toggle on Folders/Divisions/Sections is
  settable by any scoped uploader due to a Laravel empty-rules-array validation quirk — real
  authz bypass, fix identified in `SECURITY.md` but not yet applied.
- **`SECURITY.md` L-04** (open): `convert-status` endpoint has no visibility/scope check —
  low-severity metadata leak, fix also identified but not applied.

Neither is related to the OCR work above — just don't forget they're sitting there open.

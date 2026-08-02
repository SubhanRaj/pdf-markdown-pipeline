<?php
$statusMeta = \App\Models\Document::STATUSES[$quickConversion->status] ?? ['label' => $quickConversion->status, 'color' => 'slate'];
$statusColors = [
    'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
    'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300',
    'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
    'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300',
    'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',
];
$statusClass = $statusColors[$statusMeta['color']] ?? $statusColors['slate'];
$isConverting = in_array($quickConversion->status, ['uploaded', 'processing', 'ocr_pending'], true);
$isDone = in_array($quickConversion->status, ['review', 'failed'], true);
$pdfUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($quickConversion->pdf_path);
$needsOcrReview = (bool) ($quickConversion->metadata['needs_ocr_review'] ?? false);
?>
<x-layout
    title="New Conversion"
    page-title="{{ $quickConversion->title ?: $quickConversion->original_filename }}"
    page-subtitle="Not saved anywhere yet — decide below"
    :full-height="$isDone"
>

@unless($isDone)
<x-breadcrumb :items="[
    ['name' => 'Home',           'url' => route('home')],
    ['name' => 'New Conversion', 'url' => route('conversions.create')],
    ['name' => 'My Conversions', 'url' => route('conversions.index')],
    ['name' => $quickConversion->title ?: $quickConversion->original_filename, 'url' => null],
]" />
@endunless

@php
$pageData = [
    'statusUrl'   => route('conversions.status', $quickConversion),
    'ocrUrl'      => route('conversions.ocr', $quickConversion),
    'updateUrl'   => route('conversions.update', $quickConversion),
    'placeUrl'    => route('conversions.place', $quickConversion),
    'destroyUrl'  => route('conversions.destroy', $quickConversion),
    'downloadUrl' => route('conversions.download', $quickConversion),
    'csrfToken'   => csrf_token(),
    'tree'        => $tree,
];
@endphp
<script id="page-data" type="application/json">@json($pageData)</script>

@if(!$isDone)
<div class="flex flex-wrap items-center gap-3 mb-4">
    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusClass }}" id="status-badge">
        {{ $statusMeta['label'] }}
    </span>
    <span class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1">
        <i class="ti ti-clock"></i>
        Auto-deletes {{ $quickConversion->expires_at->format('d M Y, H:i') }} ({{ $quickConversion->expires_at->diffForHumans() }}) unless you save or download it
    </span>
</div>

<div id="convert-progress" class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-xl px-6 py-10 text-center" data-poll-url="{{ route('conversions.status', $quickConversion) }}">
    <i class="ti ti-loader-2 animate-spin text-3xl text-indigo-500 dark:text-indigo-400"></i>
    <p class="mt-3 text-sm font-medium text-indigo-700 dark:text-indigo-300">Converting to Markdown…</p>
    <p class="mt-1 text-xs text-indigo-500 dark:text-indigo-400">Elapsed <span id="convert-elapsed">0:00</span> — OCR on scanned documents can take several minutes. This page updates automatically.</p>
    <p id="convert-queue-note" class="mt-1 text-xs text-indigo-400 dark:text-indigo-500 hidden">Waiting behind another conversion in the queue…</p>
</div>
@endif

{{-- ── Compare layout, sized to fill the page (sidebar/header stay visible — this is a normal
     page, not a dismissible modal) so the PDF/Markdown panes take the remaining height and the
     action bar stays pinned at the bottom, instead of living in the normal page scroll flow
     (where a long document would push the Save/Discard buttons off-screen). Works via the
     `full-height` layout prop on <x-layout> above: <main> becomes a flex column with min-h-0, so
     this div's flex-1 min-h-0 fills exactly the space below the header. ── --}}
@if($isDone)
<div class="flex-1 min-h-0 flex flex-col bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">

    <div class="flex items-center justify-between gap-3 flex-wrap px-4 sm:px-6 py-3 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
        <div class="flex items-center gap-3 min-w-0">
            <a href="{{ route('conversions.index') }}"
               class="w-8 h-8 flex-shrink-0 flex items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
               title="Back to My Conversions">
                <i class="ti ti-arrow-left"></i>
            </a>
            <span class="text-sm font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $quickConversion->title ?: $quickConversion->original_filename }}</span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold {{ $statusClass }} flex-shrink-0">
                {{ $statusMeta['label'] }}
            </span>
        </div>
        <span class="text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1 flex-shrink-0">
            <i class="ti ti-clock"></i>
            Auto-deletes {{ $quickConversion->expires_at->diffForHumans() }} unless you save or download it
        </span>
    </div>

    @if($quickConversion->status === 'failed')
    <div class="mx-2 mt-2 px-4 py-2.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg flex-shrink-0 flex items-start gap-2">
        <i class="ti ti-alert-triangle text-red-500 dark:text-red-400 mt-0.5"></i>
        <div>
            <p class="text-sm font-medium text-red-700 dark:text-red-300">Conversion failed</p>
            <p class="text-xs text-red-500 dark:text-red-400 mt-0.5">{{ $quickConversion->error_message ?: 'Unknown error.' }}</p>
        </div>
    </div>
    @endif

    @if($needsOcrReview)
    <div class="mx-2 mt-2 px-4 py-2 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg flex-shrink-0 flex items-center gap-2">
        <i class="ti ti-alert-circle text-orange-500 dark:text-orange-400 text-sm flex-shrink-0"></i>
        <p class="text-xs text-orange-700 dark:text-orange-300">
            The text layer looks sparse or unreadable — possibly a scanned page. If the Markdown below is missing or garbled, run OCR instead of editing it by hand.
        </p>
    </div>
    @endif

    <div class="flex flex-1 min-h-0 gap-2 p-2">
        <div class="w-1/2 flex flex-col min-h-0 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="px-4 py-2 bg-red-50 dark:bg-red-900/20 border-b border-red-100 dark:border-red-900/40 flex-shrink-0 flex items-center gap-2">
                <i class="ti ti-file-type-pdf text-sm text-red-500 dark:text-red-400"></i>
                <span class="text-xs font-bold uppercase tracking-widest text-red-700 dark:text-red-400">Original</span>
            </div>
            <iframe src="{{ $pdfUrl }}#view=FitH&toolbar=1&navpanes=0" class="w-full flex-1 border-0" title="Original PDF"></iframe>
        </div>
        <div class="w-1/2 flex flex-col min-h-0 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 border-b border-indigo-100 dark:border-indigo-900/40 flex-shrink-0 flex items-center gap-2 flex-wrap">
                <i class="ti ti-markdown text-sm text-indigo-500 dark:text-indigo-400"></i>
                <span class="text-xs font-bold uppercase tracking-widest text-indigo-700 dark:text-indigo-400">Extracted Markdown</span>
                <div class="inline-flex rounded-lg border border-indigo-200 dark:border-indigo-800 overflow-hidden text-[11px] font-medium ml-auto flex-shrink-0">
                    <button type="button" id="qc-tab-edit" class="px-2.5 py-1 bg-indigo-500 text-white">Edit</button>
                    <button type="button" id="qc-tab-preview" class="px-2.5 py-1 bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400">Preview</button>
                </div>
            </div>
            <textarea id="qc-md-textarea"
                      class="flex-1 w-full px-4 py-3 text-xs font-mono bg-white dark:bg-slate-950 text-slate-800 dark:text-slate-100 border-0 resize-none focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500"
                      spellcheck="false">{{ $mdRaw ?? '' }}</textarea>
            <div id="qc-md-preview" class="hidden flex-1 overflow-y-auto px-4 py-3 prose prose-sm dark:prose-invert max-w-none text-slate-700 dark:text-slate-300"></div>
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-2 sm:gap-3 px-4 sm:px-6 py-3 sm:py-4 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 flex-shrink-0">
        @if($quickConversion->markdown_path)
        <button type="button" id="btn-save-draft"
                class="inline-flex items-center gap-1.5 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-device-floppy text-base"></i> Save Edits
        </button>
        @endif

        <select id="ocr-engine-select"
                class="text-sm font-medium border border-orange-200 dark:border-orange-800 bg-orange-50 dark:bg-orange-900/20 text-orange-700 dark:text-orange-300 rounded-lg px-2 py-2 focus:outline-none focus:ring-2 focus:ring-orange-400 dark:[color-scheme:dark]">
            @foreach(config('ocr.engines') as $engineKey => $engineConfig)
                <option value="{{ $engineKey }}" @selected($engineKey === config('ocr.default'))>{{ $engineConfig['label'] }}</option>
            @endforeach
        </select>
        <button type="button" id="btn-run-ocr"
                class="inline-flex items-center gap-1.5 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 hover:bg-orange-100 dark:hover:bg-orange-900/40 text-orange-700 dark:text-orange-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-scan text-base"></i> Run OCR
        </button>

        @if($quickConversion->markdown_path)
        <a href="{{ route('conversions.download', $quickConversion) }}"
           class="inline-flex items-center gap-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-download text-base"></i> Download Markdown
        </a>

        <button type="button" id="btn-save-to"
                class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-folder-plus text-base"></i> Save to…
        </button>
        @endif

        <button type="button" id="btn-discard"
                class="inline-flex items-center gap-1.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 dark:hover:bg-red-900/40 text-red-700 dark:text-red-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-trash text-base"></i> Discard
        </button>

        <span id="action-status" class="text-xs text-slate-500 dark:text-slate-400"></span>
    </div>
</div>
@endif

{{-- ── "Save to…" modal — destination picker, same scope-tree pattern as bulk-upload.blade.php ── --}}
<div id="save-to-modal"
     style="display:none;position:fixed;inset:0;z-index:50;background:rgba(15,23,42,0.75)"
     onclick="if(event.target===this)document.getElementById('save-to-modal').style.display='none'">
    <div class="absolute inset-4 md:inset-x-1/4 md:inset-y-10 bg-white dark:bg-slate-900 rounded-xl shadow-2xl flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Save to…</span>
            <button type="button" onclick="document.getElementById('save-to-modal').style.display='none'"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                <i class="ti ti-x"></i>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-4">
            <div class="flex gap-2" id="context-mode-tabs">
                <button type="button" data-mode="section" class="ctx-tab flex-1 text-xs font-medium px-3 py-2 rounded-lg border transition-colors">
                    <i class="ti ti-folder-open text-sm mr-1"></i> Section / Division / Folder
                </button>
                <button type="button" data-mode="ruleset" class="ctx-tab flex-1 text-xs font-medium px-3 py-2 rounded-lg border transition-colors">
                    <i class="ti ti-gavel text-sm mr-1"></i> Rule Set / Policy
                </button>
            </div>

            <div>
                <label for="pick-department" class="field-label">Department</label>
                <select id="pick-department" class="field-input"></select>
            </div>

            <div id="section-path" class="space-y-3">
                <div>
                    <label for="pick-section" class="field-label">Section</label>
                    <select id="pick-section" class="field-input"></select>
                </div>
                <div>
                    <label for="pick-division" class="field-label">Division <span class="text-slate-400 font-normal">(optional)</span></label>
                    <select id="pick-division" class="field-input"><option value="">— Direct in section —</option></select>
                </div>
                <div>
                    <label for="pick-folder" class="field-label">Folder / Patravali <span class="text-slate-400 font-normal">(optional)</span></label>
                    <select id="pick-folder" class="field-input"><option value="">— No folder (direct) —</option></select>
                </div>
            </div>

            <div id="ruleset-path" class="space-y-3" style="display:none">
                <div>
                    <label for="pick-ruleset" class="field-label">Rule Set / Policy</label>
                    <select id="pick-ruleset" class="field-input"></select>
                </div>
            </div>

            <div class="bg-slate-50 dark:bg-slate-800/60 rounded-lg px-4 py-3">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Saving to</p>
                <p id="vault-preview" class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">— select a destination —</p>
            </div>

            <div>
                <label for="qc-title" class="field-label">Title <span class="text-red-500">*</span></label>
                <input type="text" id="qc-title" class="field-input" value="{{ $quickConversion->title ?: \Illuminate\Support\Str::of($quickConversion->original_filename)->beforeLast('.')->replace(['-', '_'], ' ') }}" maxlength="255">
                <p id="err-title" class="field-err-msg" style="display:none"></p>
            </div>

            <div>
                <label for="qc-doc-type" class="field-label">Document Type <span class="text-red-500">*</span></label>
                <select id="qc-doc-type" class="field-input">
                    <option value="">— Select type —</option>
                    @foreach(\App\Models\Document::DOCUMENT_TYPES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p id="err-type" class="field-err-msg" style="display:none"></p>
            </div>

            <div>
                <label class="field-label">Visibility</label>
                <div class="flex gap-3 mt-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="qc-visibility" value="public" checked class="text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-world text-sm text-green-500"></i> Public</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="qc-visibility" value="authenticated" class="text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only</span>
                    </label>
                </div>
            </div>

            <div>
                <label for="qc-parent" class="field-label">Amends Previous Document <span class="text-slate-400 font-normal">(optional)</span></label>
                <select id="qc-parent" class="field-input"><option value="">— None (original document) —</option></select>
            </div>
        </div>

        <div class="flex items-center gap-3 px-6 py-4 border-t border-slate-200 dark:border-slate-700 flex-shrink-0">
            <button type="button" id="btn-confirm-save"
                    class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-check"></i> Save
            </button>
            <span id="save-to-status" class="text-xs text-red-500"></span>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/marked@13/marked.min.js"></script>
<script>
(function () {
    let page;
    try {
        page = JSON.parse(document.getElementById('page-data').textContent);
    } catch (e) { console.error('page-data JSON parse failed', e); return; }

    // ── Edit / Preview toggle (same pattern as documents/show.blade.php's compare modal) ──
    (function () {
        const textarea   = document.getElementById('qc-md-textarea');
        const previewEl  = document.getElementById('qc-md-preview');
        const editTab    = document.getElementById('qc-tab-edit');
        const previewTab = document.getElementById('qc-tab-preview');
        const activeTabClasses = ['bg-indigo-500', 'text-white'];
        const idleTabClasses   = ['bg-white', 'dark:bg-slate-900', 'text-indigo-600', 'dark:text-indigo-400'];

        function sanitizeHtml(html) {
            return html.replace(/\b(href|src)\s*=\s*(["'])(?:javascript|data|vbscript):[^"']*\2/gi, '$1=$2#$2');
        }

        if (textarea && previewEl && editTab && previewTab && window.marked) {
            previewTab.addEventListener('click', () => {
                previewEl.innerHTML = sanitizeHtml(marked.parse(textarea.value));
                textarea.classList.add('hidden');
                previewEl.classList.remove('hidden');
                previewTab.classList.add(...activeTabClasses);
                previewTab.classList.remove(...idleTabClasses);
                editTab.classList.add(...idleTabClasses);
                editTab.classList.remove(...activeTabClasses);
            });
            editTab.addEventListener('click', () => {
                textarea.classList.remove('hidden');
                previewEl.classList.add('hidden');
                editTab.classList.add(...activeTabClasses);
                editTab.classList.remove(...idleTabClasses);
                previewTab.classList.add(...idleTabClasses);
                previewTab.classList.remove(...activeTabClasses);
            });
        }
    })();

    // ── Conversion status polling ──────────────────────────────────────────
    const progressCard = document.getElementById('convert-progress');
    if (progressCard) {
        const startedAt = Date.now();
        const elapsedEl = document.getElementById('convert-elapsed');
        const elapsedTimer = setInterval(() => {
            const secs = Math.floor((Date.now() - startedAt) / 1000);
            if (elapsedEl) elapsedEl.textContent = Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
        }, 1000);
        const pollTimer = setInterval(() => {
            fetch(page.statusUrl, { headers: { Accept: 'application/json' } })
                .then(res => res.json())
                .then(data => {
                    if (data.status !== 'processing' && data.status !== 'ocr_pending' && data.status !== 'uploaded') {
                        clearInterval(pollTimer);
                        clearInterval(elapsedTimer);
                        window.location.reload();
                        return;
                    }
                    const note = document.getElementById('convert-queue-note');
                    if (note) note.classList.toggle('hidden', !data.queued_behind_other_job);
                })
                .catch(() => { clearInterval(pollTimer); clearInterval(elapsedTimer); });
        }, 3000);
    }

    function jsonFetch(url, opts) {
        return fetch(url, opts).then(async res => {
            const ct = res.headers.get('Content-Type') || '';
            const data = ct.includes('application/json') ? await res.json() : {};
            if (!res.ok) throw new Error(data.message || ('HTTP ' + res.status));
            return data;
        });
    }

    // ── Save edits ──────────────────────────────────────────────────────────
    const saveDraftBtn = document.getElementById('btn-save-draft');
    if (saveDraftBtn) {
        saveDraftBtn.addEventListener('click', () => {
            saveDraftBtn.disabled = true;
            const statusEl = document.getElementById('action-status');
            jsonFetch(page.updateUrl, {
                method: 'PATCH',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': page.csrfToken },
                body: JSON.stringify({ content: document.getElementById('qc-md-textarea').value }),
            }).then(() => {
                statusEl.textContent = 'Saved.';
                setTimeout(() => statusEl.textContent = '', 2000);
            }).catch(err => Swal.fire({ icon: 'error', text: err.message })).finally(() => { saveDraftBtn.disabled = false; });
        });
    }

    // ── Run OCR ─────────────────────────────────────────────────────────────
    const ocrBtn = document.getElementById('btn-run-ocr');
    if (ocrBtn) {
        ocrBtn.addEventListener('click', () => {
            ocrBtn.disabled = true;
            jsonFetch(page.ocrUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': page.csrfToken },
                body: JSON.stringify({ engine: document.getElementById('ocr-engine-select').value }),
            }).then(() => window.location.reload())
              .catch(err => { ocrBtn.disabled = false; Swal.fire({ icon: 'error', text: err.message }); });
        });
    }

    // ── Discard ─────────────────────────────────────────────────────────────
    const discardBtn = document.getElementById('btn-discard');
    if (discardBtn) {
        discardBtn.addEventListener('click', () => {
            const isDark = document.documentElement.classList.contains('dark');
            Swal.fire({
                title: 'Discard this conversion?',
                text: 'The uploaded file and its converted Markdown will be deleted permanently.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<i class="ti ti-trash mr-1"></i> Discard',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ef4444',
                background: isDark ? '#1e293b' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#0f172a',
            }).then(result => {
                if (!result.isConfirmed) return;
                jsonFetch(page.destroyUrl, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': page.csrfToken },
                }).then(() => { window.location.href = '{{ route('conversions.create') }}'; })
                  .catch(err => Swal.fire({ icon: 'error', text: err.message }));
            });
        });
    }

    // ── "Save to…" modal — destination picker (same pattern as bulk-upload.blade.php) ──
    const saveToBtn = document.getElementById('btn-save-to');
    const modal = document.getElementById('save-to-modal');
    if (saveToBtn && modal && page.tree && page.tree.length) {
        const deptSelect  = document.getElementById('pick-department');
        const modeTabs    = document.querySelectorAll('.ctx-tab');
        const sectionPath = document.getElementById('section-path');
        const rulesetPath = document.getElementById('ruleset-path');
        const sectionSel  = document.getElementById('pick-section');
        const divisionSel = document.getElementById('pick-division');
        const folderSel   = document.getElementById('pick-folder');
        const rulesetSel  = document.getElementById('pick-ruleset');
        const vaultPreview = document.getElementById('vault-preview');
        const parentSelect = document.getElementById('qc-parent');

        let mode = 'section';

        function setMode(next) {
            mode = next;
            modeTabs.forEach(btn => {
                const active = btn.dataset.mode === mode;
                btn.classList.toggle('bg-indigo-600', active);
                btn.classList.toggle('text-white', active);
                btn.classList.toggle('border-indigo-600', active);
                btn.classList.toggle('bg-white', !active);
                btn.classList.toggle('dark:bg-slate-800', !active);
                btn.classList.toggle('text-slate-500', !active);
                btn.classList.toggle('border-slate-200', !active);
                btn.classList.toggle('dark:border-slate-700', !active);
            });
            sectionPath.style.display = mode === 'section' ? 'block' : 'none';
            rulesetPath.style.display = mode === 'ruleset' ? 'block' : 'none';
            refreshDestination();
        }
        modeTabs.forEach(btn => btn.addEventListener('click', () => setMode(btn.dataset.mode)));

        function currentDept() { return page.tree.find(d => String(d.id) === deptSelect.value) || null; }
        function currentSection(dept) { return dept ? (dept.sections || []).find(s => String(s.id) === sectionSel.value) || null : null; }
        function currentDivision(section) { return section ? (section.divisions || []).find(d => String(d.id) === divisionSel.value) || null : null; }
        function currentFolder(section, division) {
            const folders = division ? (division.folders || []) : (section ? section.folders || [] : []);
            return folders.find(f => String(f.id) === folderSel.value) || null;
        }
        function currentRuleSet(dept) { return dept ? (dept.ruleSets || []).find(r => String(r.id) === rulesetSel.value) || null : null; }

        function fillSelect(select, items, valueKey, labelFn, placeholder) {
            select.innerHTML = '';
            if (placeholder) {
                const opt = document.createElement('option');
                opt.value = ''; opt.textContent = placeholder;
                select.appendChild(opt);
            }
            items.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item[valueKey]; opt.textContent = labelFn(item);
                select.appendChild(opt);
            });
        }

        function populateDepartments() {
            fillSelect(deptSelect, page.tree, 'id', d => `${d.name} (${d.levelLabel})`, null);
            onDepartmentChange();
        }
        function onDepartmentChange() {
            const dept = currentDept();
            const hasRuleSets = dept && dept.ruleSets && dept.ruleSets.length > 0;
            document.querySelector('.ctx-tab[data-mode="ruleset"]').style.display = hasRuleSets ? '' : 'none';
            if (!hasRuleSets && mode === 'ruleset') setMode('section');
            fillSelect(sectionSel, dept ? dept.sections : [], 'id', s => s.name + (s.wing ? ' · ' + s.wing.replace(/_/g, ' ') : ''), null);
            onSectionChange();
            fillSelect(rulesetSel, dept ? dept.ruleSets : [], 'id', r => r.name, null);
            onRuleSetChange();
        }
        function onSectionChange() {
            const section = currentSection(currentDept());
            fillSelect(divisionSel, section ? section.divisions : [], 'id', d => d.name, '— Direct in section —');
            onDivisionChange();
        }
        function onDivisionChange() {
            const dept = currentDept();
            const section = currentSection(dept);
            const division = currentDivision(section);
            const folders = division ? division.folders : (section ? section.folders : []);
            fillSelect(folderSel, folders || [], 'id', f => f.name, '— No folder (direct) —');
            onFolderChange();
        }
        function onFolderChange() { refreshParentOptions(); refreshDestination(); }
        function onRuleSetChange() { refreshParentOptions(); refreshDestination(); }

        function refreshParentOptions() {
            let options = [];
            if (mode === 'ruleset') {
                const rs = currentRuleSet(currentDept());
                options = rs ? rs.parentOptions : [];
            } else {
                const dept = currentDept();
                const section = currentSection(dept);
                const division = currentDivision(section);
                const folder = currentFolder(section, division);
                options = folder ? folder.parentOptions : (section ? section.parentOptions : []);
            }
            fillSelect(parentSelect, options || [], 'id', o => `${o.title} (${o.date})`, '— None (original document) —');
        }

        function refreshDestination() {
            let crumbs = [];
            if (mode === 'ruleset') {
                const dept = currentDept();
                const rs = currentRuleSet(dept);
                crumbs = dept && rs ? [dept.levelLabel, dept.name, (rs.kind === 'policy' ? 'Policy' : 'Rules'), rs.name] : [];
            } else {
                const dept = currentDept();
                const section = currentSection(dept);
                const division = currentDivision(section);
                const folder = currentFolder(section, division);
                if (dept && section) {
                    crumbs = [dept.levelLabel, dept.name];
                    if (section.wing) crumbs.push(section.wing.replace(/_/g, ' '));
                    crumbs.push(section.name);
                    if (division) crumbs.push(division.name);
                    if (folder) { crumbs.push('folders'); crumbs.push(folder.name); }
                }
            }
            vaultPreview.textContent = crumbs.length ? crumbs.join(' › ') : '— select a destination —';
        }

        deptSelect.addEventListener('change', onDepartmentChange);
        sectionSel.addEventListener('change', onSectionChange);
        divisionSel.addEventListener('change', onDivisionChange);
        folderSel.addEventListener('change', onFolderChange);
        rulesetSel.addEventListener('change', onRuleSetChange);

        saveToBtn.addEventListener('click', () => {
            modal.style.display = 'block';
            populateDepartments();
            setMode('section');
        });

        function showErr(id, msg) {
            const el = document.getElementById(id);
            if (el) { el.textContent = msg; el.style.display = 'block'; }
        }
        function clearErr(id) {
            const el = document.getElementById(id);
            if (el) el.style.display = 'none';
        }

        document.getElementById('btn-confirm-save').addEventListener('click', () => {
            clearErr('err-title');
            clearErr('err-type');

            const title = document.getElementById('qc-title').value.trim();
            const docType = document.getElementById('qc-doc-type').value;
            if (!title) { showErr('err-title', 'Title is required.'); return; }
            if (!docType) { showErr('err-type', 'Select a document type.'); return; }

            let contextFields = {};
            if (mode === 'ruleset') {
                const rs = currentRuleSet(currentDept());
                if (!rs) { showErr('err-type', 'Select a rule set before saving.'); return; }
                contextFields = { rule_set_id: rs.id };
            } else {
                const dept = currentDept();
                const section = currentSection(dept);
                if (!section) { showErr('err-type', 'Select a section before saving.'); return; }
                const division = currentDivision(section);
                const folder = currentFolder(section, division);
                contextFields = { section_id: section.id };
                if (division) contextFields.division_id = division.id;
                if (folder) contextFields.folder_id = folder.id;
            }

            const btn = document.getElementById('btn-confirm-save');
            const statusEl = document.getElementById('save-to-status');
            btn.disabled = true;
            statusEl.textContent = '';

            const body = {
                ...contextFields,
                title,
                document_type: docType,
                visibility: document.querySelector('[name="qc-visibility"]:checked')?.value || 'public',
                parent_id: parentSelect.value || null,
            };

            jsonFetch(page.placeUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': page.csrfToken },
                body: JSON.stringify(body),
            }).then(data => { window.location.href = data.redirect; })
              .catch(err => { btn.disabled = false; statusEl.textContent = err.message; });
        });
    }
})();
</script>
@endpush

</x-layout>

<x-layout
    title="Conversion Pipeline"
    page-title="Conversion Pipeline"
    page-subtitle="Every document not yet verified — upload, conversion, and review status at a glance"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                'url' => route('home')],
    ['name' => 'Conversion Pipeline', 'url' => null],
]" />

@php
    $statusColors = [
        'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
        'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
        'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
        'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
        'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    ];
    // Same gate the single Convert/Retry button already used — bulk-select reuses it rather
    // than introducing a second authorization rule for the same action.
    $isAdmin = auth()->check() && auth()->user()->isAdmin();
@endphp

{{-- ── Status tabs ──────────────────────────────────────────────────────────── --}}
<div class="mb-4 flex items-center gap-1 flex-wrap border-b border-slate-200 dark:border-slate-700 pb-0">
    <a href="{{ route('documents.pipeline') }}"
       class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors
              {{ ! $activeStatus ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600' }}">
        All <span class="ml-1 text-[10px] text-slate-400">{{ $counts->sum() }}</span>
    </a>
    @foreach($pipelineStatuses as $s)
    @php $meta = \App\Models\Document::STATUSES[$s]; @endphp
    <a href="{{ route('documents.pipeline', ['status' => $s]) }}"
       class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors
              {{ $activeStatus === $s ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600' }}">
        {{ $meta['label'] }} <span class="ml-1 text-[10px] text-slate-400">{{ $counts[$s] ?? 0 }}</span>
    </a>
    @endforeach
</div>

@if($documents->isEmpty())
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col items-center justify-center py-20 text-center">
    <i class="ti ti-checkbox text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
    <p class="text-sm text-slate-500 dark:text-slate-400">Nothing in the pipeline right now.</p>
    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Everything is either verified or hasn't been uploaded yet.</p>
</div>
@else
@if($isAdmin)
<div id="bulk-convert-bar" class="hidden mb-3 flex items-center justify-between gap-3 px-4 py-2.5 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-xl">
    <span class="text-xs font-medium text-indigo-700 dark:text-indigo-300"><span class="bulk-count">0</span> selected</span>
    <div class="flex items-center gap-2">
        <button type="button" id="bulk-convert-btn" class="hidden text-xs font-semibold px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white transition-colors">
            Convert Selected
        </button>
        <button type="button" id="bulk-verify-btn" class="hidden text-xs font-semibold px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white transition-colors">
            Verify Selected
        </button>
    </div>
</div>
@endif
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm" id="pipeline-table">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700 text-left">
                    @if($isAdmin)
                    <th class="px-4 py-3 w-10">
                        <input type="checkbox" id="select-all-pipeline" class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-indigo-600">
                    </th>
                    @endif
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Title</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Context</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Status</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Method</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Uploaded</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($documents as $doc)
                @php
                    $statusMeta = \App\Models\Document::STATUSES[$doc->status];
                    $docUrl = match(true) {
                        $doc->folder && $doc->division => route('documents.divisions.folders.show', [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc->folder, $doc]),
                        (bool) $doc->folder            => route('documents.folders.show',           [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->folder, $doc]),
                        (bool) $doc->division           => route('documents.divisions.show',         [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc]),
                        (bool) $doc->section             => route('documents.show',                   [$doc->department->levelAlias(), $doc->department, $doc->section, $doc]),
                        default                           => route('documents.rules.show',            [$doc->department->levelAlias(), $doc->department, $doc->ruleSet, $doc]),
                    };
                    $contextName = $doc->folder?->name ?? $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name;
                    $isPolling = in_array($doc->status, ['processing', 'ocr_pending'], true);
                    $canConvert = in_array($doc->status, ['uploaded', 'failed'], true);
                    $canVerify = $doc->status === 'review' && $doc->markdown_path;
                @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors" data-doc-row="{{ $doc->id }}" @if($isPolling) data-poll="1" @endif>
                    @if($isAdmin)
                    <td class="px-4 py-3">
                        @if($canConvert)
                        <input type="checkbox" class="convert-select w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-indigo-600" value="{{ $doc->id }}" data-convert-url="{{ route('documents.convert', $doc->id) }}">
                        @elseif($canVerify)
                        <input type="checkbox" class="verify-select w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-emerald-600" value="{{ $doc->id }}" data-verify-url="{{ route('documents.verify', $doc->id) }}">
                        @endif
                    </td>
                    @endif
                    <td class="px-4 py-3">
                        <a href="{{ $docUrl }}" class="font-medium text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400">{{ $doc->title }}</a>
                        <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5">{{ \App\Models\Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type }}</p>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                        {{ $doc->department->name }}<br>
                        <span class="text-slate-400 dark:text-slate-500">{{ $contextName }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="doc-status-badge inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium {{ $statusColors[$statusMeta['color']] }}">
                            @if($isPolling)<i class="ti ti-loader-2 animate-spin text-[10px]"></i>@endif
                            {{ $statusMeta['label'] }}
                        </span>
                        @if($doc->metadata['structure_analyzed'] ?? false)
                        <i class="ti ti-layout-2 text-[11px] text-sky-500 dark:text-sky-400 ml-1" title="Structure analyzed (Docling): {{ $doc->metadata['structure_headings_count'] ?? 0 }} headings, {{ $doc->metadata['structure_tables_count'] ?? 0 }} tables"></i>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-400 dark:text-slate-500">
                        {{ $doc->metadata['extraction_method'] ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-400 dark:text-slate-500">
                        {{ $doc->created_at->format('d M Y, H:i') }}
                        @if($doc->user)<br><span>{{ $doc->user->name }}</span>@endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="doc-actions inline-flex items-center gap-2">
                            @if($isAdmin && $canConvert)
                            <button type="button" class="pipeline-convert-btn text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline"
                                    data-convert-url="{{ route('documents.convert', $doc->id) }}" data-doc-id="{{ $doc->id }}">
                                {{ $doc->status === 'failed' ? 'Retry' : 'Convert' }}
                            </button>
                            @endif
                            @if($isAdmin && $canVerify)
                            <button type="button" class="pipeline-verify-btn text-xs font-medium text-emerald-600 dark:text-emerald-400 hover:underline"
                                    data-verify-url="{{ route('documents.verify', $doc->id) }}" data-doc-id="{{ $doc->id }}">
                                Accept
                            </button>
                            @endif
                            <a href="{{ $docUrl }}" class="text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400">View</a>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700">
        {{ $documents->links() }}
    </div>
</div>
@endif

@push('scripts')
<script>
try {
    const statusColorClasses = {
        slate:  'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
        blue:   'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
        amber:  'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
        indigo: 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
        red:    'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
        green:  'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300',
    };
    const statusMeta = {
        uploaded:    { label: 'Uploaded',    color: 'slate'  },
        processing:  { label: 'Processing',  color: 'blue'   },
        ocr_pending: { label: 'OCR Pending', color: 'amber'  },
        review:      { label: 'Review',      color: 'indigo' },
        verified:    { label: 'Verified',    color: 'green'  },
        failed:      { label: 'Failed',      color: 'red'    },
    };

    // Marks a row as "now converting" in place — no reload, so the scroll position (and
    // everyone else's place in a long list) doesn't jump back to the top. The polling loop
    // below re-queries data-poll="1" rows on every tick, so a row marked here is picked up
    // on the next 5s tick with no extra wiring.
    function markRowConverting(row) {
        const badge = row.querySelector('.doc-status-badge');
        if (badge) {
            badge.className = 'doc-status-badge inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium ' + statusColorClasses.blue;
            badge.innerHTML = '<i class="ti ti-loader-2 animate-spin text-[10px]"></i> Processing';
        }
        row.dataset.poll = '1';
        const actionBtn = row.querySelector('.pipeline-convert-btn');
        if (actionBtn) actionBtn.remove();
        const checkbox = row.querySelector('.convert-select');
        if (checkbox) checkbox.closest('td').innerHTML = '';
    }

    // Marks a row as "now verified" in place — same no-reload reasoning as markRowConverting.
    function markRowVerified(row) {
        const badge = row.querySelector('.doc-status-badge');
        if (badge) {
            badge.className = 'doc-status-badge inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium ' + statusColorClasses.green;
            badge.textContent = 'Verified';
        }
        const actionBtn = row.querySelector('.pipeline-verify-btn');
        if (actionBtn) actionBtn.remove();
        const checkbox = row.querySelector('.verify-select');
        if (checkbox) checkbox.closest('td').innerHTML = '';
    }

    // Shared by the single Convert/Retry button and bulk "Convert Selected" — one fetch
    // wrapper instead of two copies of the same request/error handling.
    function convertDocument(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(res => res.json().then(data => ({ ok: res.ok, data })));
    }

    // Same request shape as convertDocument() — a separate function only because it hits a
    // different endpoint/verb-adjacent action, not because the transport differs.
    function verifyDocument(url) {
        return fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(res => res.json().then(data => ({ ok: res.ok, data })));
    }

    document.querySelectorAll('.pipeline-convert-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = 'Starting…';
            const row = btn.closest('tr');
            convertDocument(btn.dataset.convertUrl)
                .then(({ ok, data }) => {
                    if (!ok) throw new Error(data.message || 'Could not start conversion.');
                    markRowConverting(row);
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.textContent = 'Retry';
                    alert(err.message);
                });
        });
    });

    document.querySelectorAll('.pipeline-verify-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = 'Verifying…';
            const row = btn.closest('tr');
            verifyDocument(btn.dataset.verifyUrl)
                .then(({ ok, data }) => {
                    if (!ok) throw new Error(data.message || 'Could not verify.');
                    markRowVerified(row);
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.textContent = 'Accept';
                    alert(err.message);
                });
        });
    });

    // ── Bulk select + convert/verify ─────────────────────────────────────────
    // One "select all" checkbox covers both convert-eligible and verify-eligible rows (a row
    // is only ever one or the other) — the bulk bar shows whichever action button(s) apply to
    // the current selection, so a mixed selection (e.g. on the "All" tab) runs both in one go.
    const selectAllCb  = document.getElementById('select-all-pipeline');
    const bulkBar       = document.getElementById('bulk-convert-bar');
    const bulkConvertBtn = document.getElementById('bulk-convert-btn');
    const bulkVerifyBtn  = document.getElementById('bulk-verify-btn');

    function selectedConvertCheckboxes() {
        return Array.from(document.querySelectorAll('.convert-select:checked'));
    }
    function selectedVerifyCheckboxes() {
        return Array.from(document.querySelectorAll('.verify-select:checked'));
    }

    function refreshBulkBar() {
        if (!bulkBar) return;
        const convertCount = selectedConvertCheckboxes().length;
        const verifyCount  = selectedVerifyCheckboxes().length;
        bulkBar.classList.toggle('hidden', convertCount + verifyCount === 0);
        const label = bulkBar.querySelector('.bulk-count');
        if (label) label.textContent = convertCount + verifyCount;
        if (bulkConvertBtn) bulkConvertBtn.classList.toggle('hidden', convertCount === 0);
        if (bulkVerifyBtn) bulkVerifyBtn.classList.toggle('hidden', verifyCount === 0);
    }

    document.querySelectorAll('.convert-select, .verify-select').forEach(cb => {
        cb.addEventListener('change', refreshBulkBar);
    });

    if (selectAllCb) {
        selectAllCb.addEventListener('change', function () {
            document.querySelectorAll('.convert-select, .verify-select').forEach(cb => { cb.checked = selectAllCb.checked; });
            refreshBulkBar();
        });
    }

    if (bulkConvertBtn) {
        bulkConvertBtn.addEventListener('click', async function () {
            const boxes = selectedConvertCheckboxes();
            if (!boxes.length) return;
            bulkConvertBtn.disabled = true;
            bulkConvertBtn.textContent = 'Starting…';

            for (const cb of boxes) {
                const row = cb.closest('tr');
                try {
                    const { ok, data } = await convertDocument(cb.dataset.convertUrl);
                    if (ok) markRowConverting(row);
                } catch (e) { /* leave this row as-is; user can retry individually */ }
            }

            bulkConvertBtn.disabled = false;
            bulkConvertBtn.textContent = 'Convert Selected';
            if (selectAllCb) selectAllCb.checked = false;
            refreshBulkBar();
        });
    }

    if (bulkVerifyBtn) {
        bulkVerifyBtn.addEventListener('click', async function () {
            const boxes = selectedVerifyCheckboxes();
            if (!boxes.length) return;
            bulkVerifyBtn.disabled = true;
            bulkVerifyBtn.textContent = 'Verifying…';

            for (const cb of boxes) {
                const row = cb.closest('tr');
                try {
                    const { ok, data } = await verifyDocument(cb.dataset.verifyUrl);
                    if (ok) markRowVerified(row);
                } catch (e) { /* leave this row as-is; user can retry individually */ }
            }

            bulkVerifyBtn.disabled = false;
            bulkVerifyBtn.textContent = 'Verify Selected';
            if (selectAllCb) selectAllCb.checked = false;
            refreshBulkBar();
        });
    }

    // ── Status polling — re-queries data-poll="1" rows fresh every tick, so rows marked
    // converting after page load (single or bulk) are picked up without a page reload. ────
    const interval = setInterval(() => {
        const pollRows = document.querySelectorAll('tr[data-poll="1"]');
        if (!pollRows.length) return;

        let stillPolling = false;
        const checks = Array.from(pollRows).map(row => {
            const id = row.dataset.docRow;
            return fetch(`/documents/${id}/convert-status`, { headers: { Accept: 'application/json' } })
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'processing' || data.status === 'ocr_pending') {
                        stillPolling = true;
                    } else {
                        delete row.dataset.poll;
                        const badge = row.querySelector('.doc-status-badge');
                        const meta = statusMeta[data.status] || { label: data.status, color: 'slate' };
                        if (badge) {
                            badge.className = 'doc-status-badge inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium ' + statusColorClasses[meta.color];
                            badge.textContent = meta.label;
                        }
                    }
                })
                .catch(() => {});
        });
        Promise.all(checks).then(() => {
            if (!stillPolling) clearInterval(interval);
        });
    }, 5000);
} catch (e) {
    console.error('Pipeline page init failed:', e);
}
</script>
@endpush

</x-layout>

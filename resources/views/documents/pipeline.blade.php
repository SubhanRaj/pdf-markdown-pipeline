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
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm" id="pipeline-table">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700 text-left">
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
                @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors" data-doc-row="{{ $doc->id }}" @if($isPolling) data-poll="1" @endif>
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
                            @auth
                            @if(auth()->user()->isAdmin() && $canConvert)
                            <button type="button" class="pipeline-convert-btn text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline"
                                    data-convert-url="{{ route('documents.convert', $doc->id) }}" data-doc-id="{{ $doc->id }}">
                                {{ $doc->status === 'failed' ? 'Retry' : 'Convert' }}
                            </button>
                            @endif
                            @endauth
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
    };
    const statusMeta = {
        uploaded:    { label: 'Uploaded',    color: 'slate'  },
        processing:  { label: 'Processing',  color: 'blue'   },
        ocr_pending: { label: 'OCR Pending', color: 'amber'  },
        review:      { label: 'Review',      color: 'indigo' },
        failed:      { label: 'Failed',      color: 'red'    },
    };

    document.querySelectorAll('.pipeline-convert-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = 'Starting…';
            fetch(btn.dataset.convertUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then(res => res.json().then(data => ({ ok: res.ok, data })))
              .then(({ ok, data }) => {
                  if (!ok) throw new Error(data.message || 'Could not start conversion.');
                  window.location.reload();
              })
              .catch(err => {
                  btn.disabled = false;
                  btn.textContent = 'Retry';
                  alert(err.message);
              });
        });
    });

    const pollRows = document.querySelectorAll('tr[data-poll="1"]');
    if (pollRows.length) {
        const interval = setInterval(() => {
            let stillPolling = false;
            const checks = Array.from(pollRows).map(row => {
                const id = row.dataset.docRow;
                return fetch(`/documents/${id}/convert-status`, { headers: { Accept: 'application/json' } })
                    .then(res => res.json())
                    .then(data => {
                        if (data.status === 'processing' || data.status === 'ocr_pending') {
                            stillPolling = true;
                        } else {
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
                if (!stillPolling) {
                    clearInterval(interval);
                    window.location.reload();
                }
            });
        }, 5000);
    }
} catch (e) {
    console.error('Pipeline page init failed:', e);
}
</script>
@endpush

</x-layout>

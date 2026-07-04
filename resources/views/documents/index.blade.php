<x-layout
    title="All Documents"
    page-title="All Documents"
    page-subtitle="Browse documents across all departments"
>

<x-breadcrumb :items="[
    ['name' => 'Home',          'url' => route('home')],
    ['name' => 'All Documents', 'url' => null],
]" />

@if($byDepartment->isEmpty())
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col items-center justify-center py-20 text-center">
    <i class="ti ti-files text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
    <p class="text-sm text-slate-500 dark:text-slate-400">No documents yet</p>
    @guest<p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Verified documents will appear here.</p>@endguest
</div>
@else

{{-- ── Bulk action bar (admin only, hidden until selection) ─────────────────── --}}
@auth @if(auth()->user()->isAdmin())
<div id="bulk-bar"
     class="hidden fixed bottom-0 left-0 right-0 z-40 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 shadow-2xl px-6 py-3 flex items-center gap-4">
    <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">
        <span id="bulk-count">0</span> selected
    </span>
    <div class="flex-1"></div>
    <button type="button" id="bulk-deselect"
            class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
        Deselect all
    </button>
    <button type="button" id="bulk-delete-btn"
            class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
        <i class="ti ti-trash text-base"></i>
        Delete Selected
    </button>
</div>

{{-- Hidden form for bulk delete submission --}}
<form id="bulk-delete-form" method="POST" action="{{ route('documents.bulk-destroy') }}" style="display:none">
    @csrf
    <div id="bulk-ids-container"></div>
    <input type="hidden" name="reason" id="bulk-reason">
</form>
@endif @endauth

{{-- ── Department tabs ──────────────────────────────────────────────────────── --}}
<div class="mb-4 flex items-center gap-1 flex-wrap border-b border-slate-200 dark:border-slate-700 pb-0">
    @foreach($byDepartment as $deptId => $docs)
    @php $dept = $docs->first()->department; @endphp
    <button type="button"
            data-tab="dept-{{ $deptId }}"
            onclick="switchTab(this)"
            class="dept-tab px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors
                   {{ $loop->first
                       ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400'
                       : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600' }}">
        {{ $dept->name }}
        <span class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium
                     bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
            {{ $docs->count() }}
        </span>
    </button>
    @endforeach
</div>

{{-- ── Per-department document tables ──────────────────────────────────────── --}}
@foreach($byDepartment as $deptId => $docs)
@php $dept = $docs->first()->department; @endphp
<div id="dept-{{ $deptId }}" class="dept-panel {{ $loop->first ? '' : 'hidden' }}">

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

        {{-- Panel header --}}
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center gap-3">
            @auth @if(auth()->user()->isAdmin())
            <input type="checkbox"
                   class="panel-select-all rounded border-slate-300 dark:border-slate-600 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-0 cursor-pointer"
                   data-panel="dept-{{ $deptId }}"
                   title="Select all in this department">
            @endif @endauth
            <div class="flex-1 min-w-0">
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $dept->name }}</h3>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                    {{ $docs->count() }} {{ Str::plural('document', $docs->count()) }}
                    @guest · verified only @endguest
                </p>
            </div>
            <a href="{{ route('departments.show', [$dept->levelAlias(), $dept]) }}"
               class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1 flex-shrink-0">
                View department <i class="ti ti-arrow-right text-xs"></i>
            </a>
        </div>

        <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
            @foreach($docs as $doc)
            @php
                $statusMeta = \App\Models\Document::STATUSES[$doc->status] ?? ['label' => $doc->status, 'color' => 'slate'];
                $statusColors = [
                    'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
                    'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
                    'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
                    'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
                    'green'  => 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
                    'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
                ];
            @endphp
            <div class="doc-row flex items-center gap-3 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group"
                 data-panel="dept-{{ $deptId }}">

                {{-- Checkbox (admin only) --}}
                @auth @if(auth()->user()->isAdmin())
                <input type="checkbox"
                       class="doc-checkbox flex-shrink-0 rounded border-slate-300 dark:border-slate-600 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-0 cursor-pointer"
                       value="{{ $doc->id }}"
                       data-panel="dept-{{ $deptId }}">
                @endif @endauth

                {{-- Status icon --}}
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0
                    @if($doc->status === 'verified') bg-green-500/10 dark:bg-green-500/20
                    @elseif($doc->status === 'failed') bg-red-500/10 dark:bg-red-500/20
                    @elseif($doc->status === 'review') bg-indigo-500/10 dark:bg-indigo-500/20
                    @else bg-slate-100 dark:bg-slate-700 @endif">
                    <i class="ti ti-file-text text-base
                        @if($doc->status === 'verified') text-green-500 dark:text-green-400
                        @elseif($doc->status === 'failed') text-red-500 dark:text-red-400
                        @elseif($doc->status === 'review') text-indigo-500 dark:text-indigo-400
                        @else text-slate-400 dark:text-slate-500 @endif"></i>
                </div>

                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $doc->title }}</p>
                    <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                        <span class="text-xs text-slate-400 dark:text-slate-500">
                            {{ $doc->folder?->name ?? $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name ?? '—' }}
                        </span>
                        <span class="text-slate-300 dark:text-slate-600">·</span>
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                            {{ \App\Models\Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type }}
                        </span>
                        @auth
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $statusColors[$statusMeta['color']] ?? $statusColors['slate'] }}">
                            {{ $statusMeta['label'] }}
                        </span>
                        @endauth
                        <span class="text-slate-300 dark:text-slate-600">·</span>
                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $doc->created_at->format('d M Y') }}</span>
                        @auth @if($doc->user)
                        <span class="text-slate-300 dark:text-slate-600">·</span>
                        <span class="text-xs text-slate-400 dark:text-slate-500">{{ $doc->user->name }}</span>
                        @endif @endauth
                    </div>
                </div>

                {{-- Actions --}}
                @php
                    $docUrl = match(true) {
                        $doc->folder && $doc->division => route('documents.divisions.folders.show', [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc->folder, $doc]),
                        (bool) $doc->folder            => route('documents.folders.show',           [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->folder, $doc]),
                        (bool) $doc->division           => route('documents.divisions.show',         [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc]),
                        (bool) $doc->section             => route('documents.show',                   [$doc->department->levelAlias(), $doc->department, $doc->section, $doc]),
                        default                           => route('documents.rules.show',            [$doc->department->levelAlias(), $doc->department, $doc->ruleSet, $doc]),
                    };
                @endphp
                <a href="{{ $docUrl }}"
                   class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20"
                   title="View">
                    <i class="ti ti-eye text-base"></i>
                </a>

            </div>
            @endforeach
        </div>
    </div>

</div>
@endforeach

@endif

@push('scripts')
<script>
function switchTab(btn) {
    document.querySelectorAll('.dept-tab').forEach(t => {
        t.classList.remove('border-indigo-500', 'text-indigo-600', 'dark:text-indigo-400');
        t.classList.add('border-transparent', 'text-slate-500', 'dark:text-slate-400');
    });
    btn.classList.add('border-indigo-500', 'text-indigo-600', 'dark:text-indigo-400');
    btn.classList.remove('border-transparent', 'text-slate-500', 'dark:text-slate-400');

    document.querySelectorAll('.dept-panel').forEach(p => p.classList.add('hidden'));
    document.getElementById(btn.dataset.tab).classList.remove('hidden');
}
</script>

@auth @if(auth()->user()->isAdmin())
<script>
(function () {
    try {
        const bulkBar     = document.getElementById('bulk-bar');
        const bulkCount   = document.getElementById('bulk-count');
        const bulkDeselect = document.getElementById('bulk-deselect');
        const bulkDeleteBtn = document.getElementById('bulk-delete-btn');
        const bulkForm    = document.getElementById('bulk-delete-form');
        const bulkIds     = document.getElementById('bulk-ids-container');
        const bulkReason  = document.getElementById('bulk-reason');

        if (!bulkBar) return;

        function getChecked() {
            return Array.from(document.querySelectorAll('.doc-checkbox:checked'));
        }

        function syncBar() {
            const checked = getChecked();
            const n = checked.length;
            bulkCount.textContent = n;
            if (n > 0) {
                bulkBar.classList.remove('hidden');
                bulkBar.classList.add('flex');
                // Add bottom padding to page so content isn't hidden behind bar
                document.body.style.paddingBottom = '64px';
            } else {
                bulkBar.classList.add('hidden');
                bulkBar.classList.remove('flex');
                document.body.style.paddingBottom = '';
            }

            // Sync each panel's select-all checkbox state
            document.querySelectorAll('.panel-select-all').forEach(function (selectAll) {
                const panelId = selectAll.dataset.panel;
                const panelBoxes = document.querySelectorAll('.doc-checkbox[data-panel="' + panelId + '"]');
                const checkedInPanel = Array.from(panelBoxes).filter(cb => cb.checked).length;
                selectAll.indeterminate = checkedInPanel > 0 && checkedInPanel < panelBoxes.length;
                selectAll.checked = panelBoxes.length > 0 && checkedInPanel === panelBoxes.length;
            });
        }

        // Individual checkboxes
        document.querySelectorAll('.doc-checkbox').forEach(function (cb) {
            cb.addEventListener('change', syncBar);
        });

        // Panel "select all" checkboxes
        document.querySelectorAll('.panel-select-all').forEach(function (selectAll) {
            selectAll.addEventListener('change', function () {
                const panelId = this.dataset.panel;
                document.querySelectorAll('.doc-checkbox[data-panel="' + panelId + '"]').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
                syncBar();
            });
        });

        // Deselect all
        bulkDeselect.addEventListener('click', function () {
            document.querySelectorAll('.doc-checkbox').forEach(cb => { cb.checked = false; });
            document.querySelectorAll('.panel-select-all').forEach(cb => { cb.checked = false; cb.indeterminate = false; });
            syncBar();
        });

        // Delete selected
        bulkDeleteBtn.addEventListener('click', async function () {
            const checked = getChecked();
            if (checked.length === 0) return;

            const dark = document.documentElement.classList.contains('dark');

            const { value: reason, isConfirmed } = await Swal.fire({
                title: 'Move ' + checked.length + ' ' + (checked.length === 1 ? 'Document' : 'Documents') + ' to Trash',
                html: '<p class="text-sm mb-3">Provide a reason. It will be recorded in the audit trail for all selected documents.</p>' +
                      '<textarea id="swal-bulk-reason" class="swal2-textarea" placeholder="Reason for deletion (required)" rows="3" style="resize:vertical"></textarea>',
                showCancelButton: true,
                confirmButtonText: 'Move to Trash',
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'Cancel',
                background: dark ? '#1e293b' : '#fff',
                color: dark ? '#f1f5f9' : '#1e293b',
                preConfirm: () => {
                    const r = document.getElementById('swal-bulk-reason').value.trim();
                    if (!r || r.length < 5) {
                        Swal.showValidationMessage('Please enter a reason (at least 5 characters).');
                        return false;
                    }
                    if (r.length > 500) {
                        Swal.showValidationMessage('Reason must be 500 characters or fewer.');
                        return false;
                    }
                    return r;
                },
            });

            if (!isConfirmed || !reason) return;

            // Build hidden id inputs
            bulkIds.innerHTML = '';
            checked.forEach(function (cb) {
                const inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = 'ids[]';
                inp.value = cb.value;
                bulkIds.appendChild(inp);
            });
            bulkReason.value = reason;
            bulkForm.submit();
        });

    } catch (e) {
        console.error('Bulk delete init failed:', e);
    }
})();
</script>
@endif @endauth

@endpush

</x-layout>

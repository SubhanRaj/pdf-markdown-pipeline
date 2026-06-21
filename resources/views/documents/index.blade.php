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
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
            <div>
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $dept->name }}</h3>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                    {{ $docs->count() }} {{ Str::plural('document', $docs->count()) }}
                    @guest · verified only @endguest
                </p>
            </div>
            <a href="{{ route('departments.show', [$dept->levelAlias(), $dept]) }}"
               class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1">
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
            <div class="flex items-start gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group">

                {{-- Status icon --}}
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5
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
                            {{ $doc->section?->name ?? $doc->ruleSet?->name ?? '—' }}
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
                <a href="{{ $doc->section
                    ? route('documents.show',       [$doc->department->levelAlias(), $doc->department, $doc->section, $doc])
                    : route('documents.rules.show', [$doc->department->levelAlias(), $doc->department, $doc->ruleSet,  $doc]) }}"
                   class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all"
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
@endpush

</x-layout>

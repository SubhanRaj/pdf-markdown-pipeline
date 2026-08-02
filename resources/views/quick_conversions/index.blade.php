<x-layout
    title="My Conversions"
    page-title="My Conversions"
    page-subtitle="Conversions you started that haven't been saved, downloaded, or discarded yet"
>

<x-breadcrumb :items="[
    ['name' => 'Home',           'url' => route('home')],
    ['name' => 'New Conversion', 'url' => route('conversions.create')],
    ['name' => 'My Conversions', 'url' => null],
]" />

<div class="flex items-center justify-between mb-4">
    <p class="text-xs text-slate-400 dark:text-slate-500">Unsaved conversions auto-delete a while after upload — see each row.</p>
    <a href="{{ route('conversions.create') }}"
       class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
        <i class="ti ti-plus text-base"></i> New Conversion
    </a>
</div>

@if($quickConversions->isEmpty())
<div class="flex flex-col items-center justify-center py-16 text-center bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <i class="ti ti-file-off text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
    <p class="text-sm text-slate-500 dark:text-slate-400">No conversions in progress</p>
    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Start one with the button above.</p>
</div>
@else
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700/60">
    @foreach($quickConversions as $qc)
    @php
        $statusMeta = \App\Models\Document::STATUSES[$qc->status] ?? ['label' => $qc->status, 'color' => 'slate'];
        $statusColors = [
            'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
            'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
            'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
            'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
            'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
        ];
    @endphp
    <div class="relative flex items-start gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group">
        <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5
            @if($qc->status === 'failed') bg-red-500/10 dark:bg-red-500/20
            @elseif($qc->status === 'review') bg-indigo-500/10 dark:bg-indigo-500/20
            @else bg-slate-100 dark:bg-slate-700 @endif">
            <i class="ti ti-file-text text-base
                @if($qc->status === 'failed') text-red-500 dark:text-red-400
                @elseif($qc->status === 'review') text-indigo-500 dark:text-indigo-400
                @else text-slate-400 dark:text-slate-500 @endif"></i>
        </div>

        <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $qc->title ?: $qc->original_filename }}</p>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $statusColors[$statusMeta['color']] ?? $statusColors['slate'] }}">
                    {{ in_array($qc->status, ['uploaded', 'processing', 'ocr_pending']) ? 'Converting…' : $statusMeta['label'] }}
                </span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $qc->created_at->format('d M Y, H:i') }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-amber-600 dark:text-amber-400" title="{{ $qc->expires_at->format('d M Y, H:i') }}">
                    <i class="ti ti-clock text-[10px]"></i> auto-deletes {{ $qc->expires_at->diffForHumans() }}
                </span>
            </div>
        </div>

        <div class="relative z-10 flex items-center gap-1 flex-shrink-0 opacity-100 sm:opacity-0 sm:group-hover:opacity-100 transition-opacity">
            <a href="{{ route('conversions.show', $qc) }}"
               class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all"
               title="Open">
                <i class="ti ti-eye text-base"></i>
            </a>
        </div>
        <a href="{{ route('conversions.show', $qc) }}" class="absolute inset-0" aria-label="Open {{ $qc->title ?: $qc->original_filename }}"></a>
    </div>
    @endforeach
</div>
@endif

</x-layout>

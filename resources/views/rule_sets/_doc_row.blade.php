@php
    $sm = \App\Models\Document::STATUSES[$doc->status] ?? ['label' => $doc->status, 'color' => 'slate'];
    $sc = [
        'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
        'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
        'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
        'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
        'green'  => 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
        'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    ];
    $hasAmendments = !$isAmendment && $doc->amendments->isNotEmpty();
@endphp

<div class="flex items-start gap-3 px-5 py-3.5 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group
            {{ $isAmendment ? 'bg-amber-50/30 dark:bg-amber-900/5 border-l-2 border-amber-200 dark:border-amber-700 ml-6 pl-4' : '' }}">

    {{-- Tree connector for amendments --}}
    @if($isAmendment)
    <div class="flex-shrink-0 mt-2 text-amber-300 dark:text-amber-700">
        <i class="ti ti-corner-down-right text-sm"></i>
    </div>
    @else
    {{-- Expand indicator if has amendments --}}
    <div class="w-4 flex-shrink-0 mt-2.5 text-slate-300 dark:text-slate-600 text-xs">
        @if($hasAmendments)
        <i class="ti ti-git-branch text-sm text-amber-400 dark:text-amber-500"></i>
        @endif
    </div>
    @endif

    {{-- Status icon --}}
    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5
        @if($doc->status === 'verified') bg-green-500/10 dark:bg-green-500/20
        @elseif($doc->status === 'failed') bg-red-500/10 dark:bg-red-500/20
        @elseif($doc->status === 'review') bg-indigo-500/10 dark:bg-indigo-500/20
        @else bg-slate-100 dark:bg-slate-700 @endif">
        <i class="ti ti-file-text text-sm
            @if($doc->status === 'verified') text-green-500 dark:text-green-400
            @elseif($doc->status === 'failed') text-red-500 dark:text-red-400
            @elseif($doc->status === 'review') text-indigo-500 dark:text-indigo-400
            @else text-slate-400 dark:text-slate-500 @endif"></i>
    </div>

    {{-- Info --}}
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
            <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $doc->title }}</p>
            @if($isAmendment)
            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 flex-shrink-0">
                <i class="ti ti-git-merge text-[10px]"></i> Amendment
            </span>
            @elseif($hasAmendments)
            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 flex-shrink-0">
                {{ $doc->amendments->count() }} {{ Str::plural('amendment', $doc->amendments->count()) }}
            </span>
            @endif
        </div>
        @php
            $amendNo      = $doc->metadata['amendment_number'] ?? null;
            $effYear      = $doc->metadata['effective_year']   ?? null;
            $effMonth     = $doc->metadata['effective_month']  ?? null;
            $effDay       = $doc->metadata['effective_day']    ?? null;
            $monthNames   = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
            $effectiveDate = $effYear
                ? ($effDay && $effMonth
                    ? "{$effDay} {$monthNames[$effMonth]} {$effYear}"
                    : ($effMonth ? "{$monthNames[$effMonth]} {$effYear}" : (string) $effYear))
                : null;
        @endphp
        <div class="flex items-center gap-2 mt-0.5 flex-wrap">
            @if($amendNo)
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                #{{ $amendNo }}
            </span>
            @endif
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                {{ \App\Models\Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type }}
            </span>
            @auth
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $sc[$sm['color']] ?? $sc['slate'] }}">
                {{ $sm['label'] }}
            </span>
            @endauth
            <span class="text-slate-300 dark:text-slate-600">·</span>
            @if($effectiveDate)
            <span class="text-xs text-slate-600 dark:text-slate-300 font-medium" title="Effective date">{{ $effectiveDate }}</span>
            <span class="text-slate-300 dark:text-slate-600">·</span>
            <span class="text-xs text-slate-400 dark:text-slate-500" title="Uploaded">{{ $doc->created_at->format('d M Y') }}</span>
            @else
            <span class="text-xs text-slate-400 dark:text-slate-500">{{ $doc->created_at->format('d M Y') }}</span>
            @endif
            @auth @if($doc->user)
            <span class="text-slate-300 dark:text-slate-600">·</span>
            <span class="text-xs text-slate-400 dark:text-slate-500">{{ $doc->user->name }}</span>
            @endif @endauth
        </div>
    </div>

    {{-- Actions --}}
    <div class="flex items-center gap-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
        <a href="{{ route("documents.{$ruleSet->kind}.show", [$department->levelAlias(), $department, $ruleSet, $doc]) }}"
           class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all"
           title="View">
            <i class="ti ti-eye text-base"></i>
        </a>
        @auth @if(auth()->user()->isAdmin() || ($ruleSet->kind === 'policy' && auth()->user()->canManagePolicy($ruleSet)))
        <button type="button"
                class="doc-delete-btn inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all"
                data-action="{{ route("documents.{$ruleSet->kind}.destroy", [$department->levelAlias(), $department, $ruleSet, $doc]) }}"
                data-title="{{ e($doc->title) }}"
                title="Delete">
            <i class="ti ti-trash text-base"></i>
        </button>
        @endif @endauth
    </div>
</div>

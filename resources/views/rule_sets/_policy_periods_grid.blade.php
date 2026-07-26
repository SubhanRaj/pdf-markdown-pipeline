{{--
    Renders the periods (years) belonging to one policy container: the current period as a
    featured card, followed by a grid of previous years. Expects $periods (a collection of
    RuleSet rows, container_id set, already withCount('documents')) and $department/$ruleSet
    in scope from the including view.
--}}
@php
    $currentPeriod  = $periods->firstWhere('policy_status', 'current');
    $previousPeriods = $periods->where('policy_status', '!=', 'current')->values();
@endphp

@if($periods->isEmpty())
<div class="flex flex-col items-center justify-center py-10 text-center">
    <i class="ti ti-calendar-time text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
    <p class="text-sm text-slate-500 dark:text-slate-400">No periods yet</p>
</div>
@else

@if($currentPeriod)
<a href="{{ route('departments.policy.periods.show', [$department->levelAlias(), $department, $ruleSet, $currentPeriod]) }}"
   class="block bg-green-50 dark:bg-green-900/10 border border-green-200 dark:border-green-800/50 rounded-xl p-5 mb-4 hover:border-green-400 dark:hover:border-green-600 transition-colors">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-10 h-10 rounded-lg bg-green-500/10 dark:bg-green-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-circle-check text-green-500 text-lg"></i>
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <p class="text-sm font-semibold text-slate-800 dark:text-slate-100">{{ $currentPeriod->name }}</p>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400 flex-shrink-0">Current</span>
                </div>
                @if($currentPeriod->effective_start_date || $currentPeriod->effective_end_date)
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                    {{ $currentPeriod->effective_start_date?->format('d M Y') }}
                    @if($currentPeriod->effective_end_date) – {{ $currentPeriod->effective_end_date->format('d M Y') }} @endif
                </p>
                @endif
            </div>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <span class="text-xs text-slate-500 dark:text-slate-400">{{ $currentPeriod->documents_count }} {{ Str::plural('doc', $currentPeriod->documents_count) }}</span>
            <i class="ti ti-arrow-right text-green-500"></i>
        </div>
    </div>
</a>
@endif

@if($previousPeriods->isNotEmpty())
<p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-2">Previous Years</p>
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
    @foreach($previousPeriods as $period)
    <a href="{{ route('departments.policy.periods.show', [$department->levelAlias(), $department, $ruleSet, $period]) }}"
       class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-3.5 flex flex-col gap-1.5 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
        <div class="flex items-start gap-2">
            <i class="ti ti-clock-pause text-slate-400 dark:text-slate-500 text-sm flex-shrink-0 mt-0.5"></i>
            <p class="text-sm font-medium text-slate-700 dark:text-slate-200 leading-snug break-words min-w-0">{{ $period->name }}</p>
        </div>
        <p class="text-xs text-slate-400 dark:text-slate-500">
            {{ $period->documents_count }} {{ Str::plural('doc', $period->documents_count) }}
        </p>
    </a>
    @endforeach
</div>
@endif

@endif

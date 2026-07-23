<?php $canManage = auth()->check() && auth()->user()->canManagePolicy($ruleSet); ?>
<x-layout
    title="{{ $ruleSet->name }}"
    page-title="{{ $ruleSet->name }}"
    page-subtitle="{{ $department->name }} · {{ $ruleSet->state }} · {{ $periods->count() }} {{ Str::plural('period', $periods->count()) }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => 'Policies',                'url' => route('departments.policy.index', [$department->levelAlias(), $department])],
    ['name' => $ruleSet->name,            'url' => null],
]" />

{{-- ── Policy header ────────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-6">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-emerald-500/10 dark:bg-emerald-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-file-certificate text-emerald-500 dark:text-emerald-400 text-xl"></i>
        </div>
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $ruleSet->name }}</h2>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <span class="text-xs text-slate-500 dark:text-slate-400">{{ $ruleSet->state }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ \App\Models\RuleSet::POLICY_TYPES[$ruleSet->policy_type] ?? $ruleSet->policy_type }}</span>
            </div>
            @if($ruleSet->description)
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $ruleSet->description }}</p>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2 flex-shrink-0">
        @auth
        @if($canManage)
        <a href="{{ route('departments.policy.periods.create', [$department->levelAlias(), $department, $ruleSet]) }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-plus text-base"></i> Add Period
        </a>
        @endif
        @if(auth()->user()->isAdmin() || $canManage)
        <a href="{{ route('departments.policy.edit', [$department->levelAlias(), $department, $ruleSet]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-pencil text-base"></i>
        </a>
        @endif
        @endauth
    </div>
</div>

{{-- ── Periods list ─────────────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Policy Periods</h3>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">One per year/cycle — each holds its own document and amendments.</p>
    </div>

    @if($periods->isEmpty())
    <div class="flex flex-col items-center justify-center py-14 text-center">
        <i class="ti ti-calendar-time text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No periods yet</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Add the first period (e.g. "2024-25") to start uploading documents.</p>
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700">
        @foreach($periods as $period)
        <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <i class="ti {{ $period->policy_status === 'current' ? 'ti-circle-check text-green-500' : 'ti-clock-pause text-slate-400 dark:text-slate-500' }} flex-shrink-0"></i>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $period->name }}</p>
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium flex-shrink-0
                            {{ $period->policy_status === 'current' ? 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400' : 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400' }}">
                            {{ $period->policy_status === 'current' ? 'Current' : 'Superseded' }}
                        </span>
                    </div>
                    @if($period->effective_start_date || $period->effective_end_date)
                    <p class="text-xs text-slate-400 dark:text-slate-500">
                        {{ $period->effective_start_date?->format('d M Y') }}
                        @if($period->effective_end_date) – {{ $period->effective_end_date->format('d M Y') }} @endif
                    </p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-xs text-slate-400 dark:text-slate-500">
                    {{ $period->documents_count }} {{ Str::plural('doc', $period->documents_count) }}
                </span>
                <a href="{{ route('departments.policy.periods.show', [$department->levelAlias(), $department, $ruleSet, $period]) }}"
                   class="text-slate-400 dark:text-slate-500 hover:text-indigo-500 dark:hover:text-indigo-400 transition-colors">
                    <i class="ti ti-arrow-right text-base"></i>
                </a>
            </div>
        </div>
        @endforeach
    </div>
    @endif
</div>

</x-layout>

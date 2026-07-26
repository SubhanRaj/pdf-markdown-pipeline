<?php $isPolicy = $kind === 'policy'; ?>
<x-layout
    :title="$isPolicy ? 'Policies' : 'Rules & Regulations'"
    :page-title="$isPolicy ? 'Policies' : 'Rules & Regulations'"
    :page-subtitle="$isPolicy ? $department->name : ($department->name . ' · ' . $ruleSets->count() . ' ' . Str::plural('rule set', $ruleSets->count()))"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $isPolicy ? 'Policies' : 'Rules & Regulations', 'url' => null],
]" />

@if($isPolicy)
{{-- Landing page: two boxes — this state's own policy vs everyone else's --}}
<div class="flex items-center justify-between mb-4">
    <div>
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Policies</h3>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $department->name }}</p>
    </div>
    @auth
    @if(auth()->user()->canManagePolicyForDepartment($department))
    <a href="{{ route('departments.policy.create', [$department->levelAlias(), $department]) }}"
       class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
        <i class="ti ti-plus text-base"></i> Add Policy
    </a>
    @endif
    @endauth
</div>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

    <a href="{{ route('departments.policy.state', [$department->levelAlias(), $department, \App\Models\RuleSet::stateSlug(\App\Models\RuleSet::DEFAULT_STATE)]) }}"
       class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 flex flex-col gap-2 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
        <div class="flex items-center gap-3">
            <div class="stat-icon bg-emerald-50 dark:bg-emerald-900/30 flex-shrink-0">
                <i class="ti ti-map-pin text-emerald-500 dark:text-emerald-400"></i>
            </div>
            <p class="text-3xl font-bold text-slate-800 dark:text-slate-100">{{ $upCount }}</p>
        </div>
        <p class="text-sm font-medium text-slate-600 dark:text-slate-300">{{ \App\Models\RuleSet::DEFAULT_STATE }} Policy</p>
        <p class="text-xs text-slate-400 dark:text-slate-500">current {{ Str::plural('policy', $upCount) }}</p>
    </a>

    <a href="{{ route('departments.policy.other-states', [$department->levelAlias(), $department]) }}"
       class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 flex flex-col gap-2 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
        <div class="flex items-center gap-3">
            <div class="stat-icon bg-indigo-50 dark:bg-indigo-900/30 flex-shrink-0">
                <i class="ti ti-map-pin text-indigo-500 dark:text-indigo-400"></i>
            </div>
            <p class="text-3xl font-bold text-slate-800 dark:text-slate-100">{{ $otherStatesCount }}</p>
        </div>
        <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Other States' Policy</p>
        <p class="text-xs text-slate-400 dark:text-slate-500">{{ Str::plural('state', $otherStatesCount) }} with a current policy uploaded</p>
    </a>

</div>

@else
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Rules & Regulations</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                {{ $ruleSets->count() }} {{ Str::plural('rule set', $ruleSets->count()) }} in this department
            </p>
        </div>
        @auth
        @if(auth()->user()->isAdmin() || (auth()->user()->hasPrivilege('department.head') && auth()->user()->department_id === $department->id))
        <a href="{{ route('departments.rules.create', [$department->levelAlias(), $department]) }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-plus text-base"></i> Add Rule Set
        </a>
        @endif
        @endauth
    </div>

    @if($ruleSets->isEmpty())
    <div class="flex flex-col items-center justify-center py-14 text-center">
        <i class="ti ti-book text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No rule sets yet</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Rule sets group Acts, Rules, and their amendments.</p>
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700">
        @foreach($ruleSets as $ruleSet)
        <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <i class="ti ti-book text-slate-400 dark:text-slate-500 flex-shrink-0"></i>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $ruleSet->name }}</p>
                    @if($ruleSet->description)
                    <p class="text-xs text-slate-400 dark:text-slate-500 truncate">{{ $ruleSet->description }}</p>
                    @else
                    <p class="text-xs text-slate-400 dark:text-slate-500 font-mono">{{ $ruleSet->slug }}</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-xs text-slate-400 dark:text-slate-500">
                    {{ $ruleSet->documents_count }} {{ Str::plural('doc', $ruleSet->documents_count) }}
                </span>
                <a href="{{ route('departments.rules.show', [$department->levelAlias(), $department, $ruleSet]) }}"
                   class="text-slate-400 dark:text-slate-500 hover:text-indigo-500 dark:hover:text-indigo-400 transition-colors">
                    <i class="ti ti-arrow-right text-base"></i>
                </a>
            </div>
        </div>
        @endforeach
    </div>
    @endif

</div>
@endif

</x-layout>

<?php $isPolicy = $kind === 'policy'; ?>
<x-layout
    title="{{ $isPolicy ? 'Policies' : 'Rules & Regulations' }}"
    page-title="{{ $isPolicy ? 'Policies' : 'Rules & Regulations' }}"
    page-subtitle="{{ $department->name }} · {{ $ruleSets->count() }} {{ $isPolicy ? 'current ' . Str::plural('policy', $ruleSets->count()) : Str::plural('rule set', $ruleSets->count()) }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $isPolicy ? 'Policies' : 'Rules & Regulations', 'url' => null],
]" />

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $isPolicy ? 'Policies' : 'Rules & Regulations' }}</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                {{ $ruleSets->count() }} {{ $isPolicy ? 'current ' . Str::plural('policy', $ruleSets->count()) : Str::plural('rule set', $ruleSets->count()) }} in this department
            </p>
        </div>
        @auth
            @if($isPolicy ? auth()->user()->canManagePolicyForDepartment($department) : (auth()->user()->isAdmin() || (auth()->user()->hasPrivilege('department.head') && auth()->user()->department_id === $department->id)))
            <a href="{{ route($isPolicy ? 'departments.policy.create' : 'departments.rules.create', [$department->levelAlias(), $department]) }}"
               class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
                <i class="ti ti-plus text-base"></i> {{ $isPolicy ? 'Add Policy' : 'Add Rule Set' }}
            </a>
            @endif
        @endauth
    </div>

    @if($ruleSets->isEmpty())
    <div class="flex flex-col items-center justify-center py-14 text-center">
        <i class="ti {{ $isPolicy ? 'ti-file-certificate' : 'ti-book' }} text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">{{ $isPolicy ? 'No policies yet' : 'No rule sets yet' }}</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
            @if($isPolicy)
                Policies are the state/government's named policy documents (Excise, Cane, Sugar, ...) for a period.
            @else
                Rule sets group Acts, Rules, and their amendments.
            @endif
        </p>
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700">
        @foreach($ruleSets as $ruleSet)
        <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <i class="ti {{ $isPolicy ? 'ti-file-certificate' : 'ti-book' }} text-slate-400 dark:text-slate-500 flex-shrink-0"></i>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $ruleSet->name }}</p>
                        @if($isPolicy)
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 flex-shrink-0">{{ $ruleSet->state }}</span>
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex-shrink-0">
                            {{ \App\Models\RuleSet::POLICY_TYPES[$ruleSet->policy_type] ?? $ruleSet->policy_type }}
                        </span>
                        @endif
                    </div>
                    @if($ruleSet->description)
                    <p class="text-xs text-slate-400 dark:text-slate-500 truncate">{{ $ruleSet->description }}</p>
                    @elseif(!$isPolicy)
                    <p class="text-xs text-slate-400 dark:text-slate-500 font-mono">{{ $ruleSet->slug }}</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-xs text-slate-400 dark:text-slate-500">
                    {{ $ruleSet->documents_count }} {{ Str::plural('doc', $ruleSet->documents_count) }}
                </span>
                <a href="{{ route($isPolicy ? 'departments.policy.show' : 'departments.rules.show', [$department->levelAlias(), $department, $ruleSet]) }}"
                   class="text-slate-400 dark:text-slate-500 hover:text-indigo-500 dark:hover:text-indigo-400 transition-colors">
                    <i class="ti ti-arrow-right text-base"></i>
                </a>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    @if($isPolicy && $historicalPolicies->isNotEmpty())
    <details class="border-t border-slate-100 dark:border-slate-700">
        <summary class="px-5 py-3 text-xs font-medium text-slate-500 dark:text-slate-400 cursor-pointer hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
            {{ $historicalPolicies->count() }} historical {{ Str::plural('policy', $historicalPolicies->count()) }} (superseded)
        </summary>
        <div class="divide-y divide-slate-100 dark:divide-slate-700">
            @foreach($historicalPolicies as $policy)
            <div class="px-5 py-3 flex items-center justify-between opacity-70">
                <div class="flex items-center gap-3 min-w-0">
                    <i class="ti ti-clock-pause text-slate-400 dark:text-slate-500 flex-shrink-0"></i>
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-slate-600 dark:text-slate-300 truncate">{{ $policy->name }}</p>
                        <p class="text-xs text-slate-400 dark:text-slate-500">{{ $policy->state }} · {{ \App\Models\RuleSet::POLICY_TYPES[$policy->policy_type] ?? $policy->policy_type }}</p>
                    </div>
                </div>
                <a href="{{ route('departments.policy.show', [$department->levelAlias(), $department, $policy]) }}"
                   class="text-slate-400 dark:text-slate-500 hover:text-indigo-500 dark:hover:text-indigo-400 transition-colors flex-shrink-0">
                    <i class="ti ti-arrow-right text-base"></i>
                </a>
            </div>
            @endforeach
        </div>
    </details>
    @endif

</div>

</x-layout>

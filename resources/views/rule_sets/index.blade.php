<?php $isPolicy = $kind === 'policy'; ?>
<x-layout
    title="{{ $isPolicy ? 'Policies' : 'Rules & Regulations' }}"
    page-title="{{ $isPolicy ? 'Policies' : 'Rules & Regulations' }}"
    page-subtitle="{{ $department->name }} · {{ $ruleSets->count() }} {{ $isPolicy ? Str::plural('state', $ruleSets->count()) : Str::plural('rule set', $ruleSets->count()) }}"
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
                {{ $ruleSets->count() }} {{ $isPolicy ? Str::plural('state', $ruleSets->count()) : Str::plural('rule set', $ruleSets->count()) }} in this department
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
                Policies are the state/government's named policy documents (Excise, Cane, Sugar, ...) — create the state once, then add each year's period underneath it.
            @else
                Rule sets group Acts, Rules, and their amendments.
            @endif
        </p>
    </div>

    @elseif($isPolicy)
    {{-- Grouped by state — each policy is created once, then holds many yearly periods --}}
    @foreach($ruleSets->groupBy('state') as $state => $statePolicies)
    <div class="px-5 py-2 bg-slate-50 dark:bg-slate-900/40 border-t first:border-t-0 border-b border-slate-100 dark:border-slate-700">
        <span class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{{ $state }}</span>
    </div>
    <div class="divide-y divide-slate-100 dark:divide-slate-700">
        @foreach($statePolicies as $policy)
        @php $currentPeriod = $policy->periods->first(); @endphp
        <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <i class="ti ti-file-certificate text-slate-400 dark:text-slate-500 flex-shrink-0"></i>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $policy->name }}</p>
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex-shrink-0">
                            {{ \App\Models\RuleSet::POLICY_TYPES[$policy->policy_type] ?? $policy->policy_type }}
                        </span>
                    </div>
                    <p class="text-xs text-slate-400 dark:text-slate-500 truncate">
                        {{ $policy->periods_count }} {{ Str::plural('period', $policy->periods_count) }}
                        @if($currentPeriod)
                            · current: {{ $currentPeriod->name }} ({{ $currentPeriod->documents_count }} {{ Str::plural('doc', $currentPeriod->documents_count) }})
                        @else
                            · no periods yet
                        @endif
                    </p>
                </div>
            </div>
            <a href="{{ route('departments.policy.show', [$department->levelAlias(), $department, $policy]) }}"
               class="text-slate-400 dark:text-slate-500 hover:text-indigo-500 dark:hover:text-indigo-400 transition-colors flex-shrink-0">
                <i class="ti ti-arrow-right text-base"></i>
            </a>
        </div>
        @endforeach
    </div>
    @endforeach

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

</x-layout>

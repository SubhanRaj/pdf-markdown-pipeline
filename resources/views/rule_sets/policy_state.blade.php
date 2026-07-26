<x-layout
    :title="$stateName . ' Policy'"
    :page-title="$stateName . ' Policy'"
    :page-subtitle="$department->name"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => 'Policies',                'url' => route('departments.policy.index', [$department->levelAlias(), $department])],
    ['name' => $stateName,                'url' => null],
]" />

@if($containers->isEmpty())
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col items-center justify-center py-16 text-center">
    <i class="ti ti-file-certificate text-4xl text-slate-200 dark:text-slate-600 mb-3"></i>
    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No policy uploaded yet for {{ $stateName }}</p>
    @auth
    @if(auth()->user()->canManagePolicyForDepartment($department))
    <a href="{{ route('departments.policy.create', [$department->levelAlias(), $department]) }}"
       class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors mt-4">
        <i class="ti ti-plus text-base"></i> Add Policy
    </a>
    @endif
    @endauth
</div>
@else
<div class="flex flex-col gap-6">
    @foreach($containers as $ruleSet)
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('departments.policy.show', [$department->levelAlias(), $department, $ruleSet]) }}"
                   class="text-sm font-semibold text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                    {{ $ruleSet->name }}
                </a>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400">
                    {{ \App\Models\RuleSet::POLICY_TYPES[$ruleSet->policy_type] ?? $ruleSet->policy_type }}
                </span>
            </div>
            @auth
            @if(auth()->user()->canManagePolicy($ruleSet))
            <a href="{{ route('departments.policy.periods.create', [$department->levelAlias(), $department, $ruleSet]) }}"
               class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition-colors">
                <i class="ti ti-plus text-sm"></i> Add Period
            </a>
            @endif
            @endauth
        </div>
        <div class="p-5">
            @include('rule_sets._policy_periods_grid', ['periods' => $ruleSet->periods])
        </div>
    </div>
    @endforeach
</div>
@endif

</x-layout>

<x-layout
    title="{{ $department->name }}"
    page-title="{{ $department->name }}"
    page-subtitle="{{ $department->level === 'secretariat_level' ? 'Secretariat Level' : 'Department Level' }} · {{ $department->sections_count }} {{ Str::plural('section', $department->sections_count) }} · {{ $department->documents_count }} {{ Str::plural('document', $department->documents_count) }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'Departments', 'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name, 'url' => null],
]" />

{{-- Info + actions bar --}}
<div class="flex items-start justify-between gap-4 mb-6">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-indigo-500/10 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-building text-indigo-500 dark:text-indigo-400 text-xl"></i>
        </div>
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $department->name }}</h2>
            <p class="text-xs text-slate-400 dark:text-slate-500 font-mono mt-0.5">{{ $department->slug }}</p>
        </div>
    </div>
    @auth @if(auth()->user()->isAdmin())
    <a href="{{ route('departments.edit', [$department->levelAlias(), $department]) }}"
       class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
        <i class="ti ti-pencil text-base"></i> Edit
    </a>
    @endif @endauth
</div>

{{-- Sections list --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Sections</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $sections->count() }} {{ Str::plural('section', $sections->count()) }} in this department</p>
        </div>
        @auth @if(auth()->user()->isAdmin() || (auth()->user()->hasPrivilege('department.head') && auth()->user()->department_id === $department->id))
        <a href="{{ route('departments.sections.create', [$department->levelAlias(), $department]) }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-plus text-base"></i> Add Section
        </a>
        @endif @endauth
    </div>

    @if($sections->isEmpty())
    <div class="flex flex-col items-center justify-center py-14 text-center">
        <i class="ti ti-layout-list text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No sections yet</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Sections group documents within a department.</p>
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700">
        @foreach($sections as $section)
        <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <i class="ti ti-layout-list text-slate-400 dark:text-slate-500 flex-shrink-0"></i>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $section->name }}</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 font-mono">{{ $section->slug }}</p>
                </div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-xs text-slate-400 dark:text-slate-500">
                    {{ $section->documents_count }} {{ Str::plural('doc', $section->documents_count) }}
                </span>
                <a href="{{ route('departments.sections.show', [$department->levelAlias(), $department, $section]) }}"
                   class="text-slate-400 dark:text-slate-500 hover:text-indigo-500 dark:hover:text-indigo-400 transition-colors">
                    <i class="ti ti-arrow-right text-base"></i>
                </a>
            </div>
        </div>
        @endforeach
    </div>
    @endif

</div>

{{-- Rule Sets panel --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 mt-6">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Rules &amp; Regulations</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $ruleSets->count() }} {{ Str::plural('rule set', $ruleSets->count()) }} in this department</p>
        </div>
        @auth @if(auth()->user()->isAdmin() || (auth()->user()->hasPrivilege('department.head') && auth()->user()->department_id === $department->id))
        <a href="{{ route('departments.rules.create', [$department->levelAlias(), $department]) }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-plus text-base"></i> Add Rule Set
        </a>
        @endif @endauth
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

{{-- Policies panel --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 mt-6">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Policies</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $policies->count() }} current {{ Str::plural('policy', $policies->count()) }} in this department</p>
        </div>
        @auth @if(auth()->user()->canManagePolicyForDepartment($department))
        <a href="{{ route('departments.policy.create', [$department->levelAlias(), $department]) }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-plus text-base"></i> Add Policy
        </a>
        @endif @endauth
    </div>

    @if($policies->isEmpty())
    <div class="flex flex-col items-center justify-center py-14 text-center">
        <i class="ti ti-file-certificate text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No policies yet</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Policies are the state/government's named policy documents (Excise, Cane, Sugar, ...) for a period.</p>
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700">
        @foreach($policies as $policy)
        <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <i class="ti ti-file-certificate text-slate-400 dark:text-slate-500 flex-shrink-0"></i>
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $policy->name }}</p>
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400 flex-shrink-0">{{ $policy->state }}</span>
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400 flex-shrink-0">
                            {{ \App\Models\RuleSet::POLICY_TYPES[$policy->policy_type] ?? $policy->policy_type }}
                        </span>
                    </div>
                    @if($policy->description)
                    <p class="text-xs text-slate-400 dark:text-slate-500 truncate">{{ $policy->description }}</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-4 flex-shrink-0">
                <span class="text-xs text-slate-400 dark:text-slate-500">
                    {{ $policy->documents_count }} {{ Str::plural('doc', $policy->documents_count) }}
                </span>
                <a href="{{ route('departments.policy.show', [$department->levelAlias(), $department, $policy]) }}"
                   class="text-slate-400 dark:text-slate-500 hover:text-indigo-500 dark:hover:text-indigo-400 transition-colors">
                    <i class="ti ti-arrow-right text-base"></i>
                </a>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    @if($historicalPolicies->isNotEmpty())
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

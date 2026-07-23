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

{{-- Category cards — Sections / Rules & Regulations / Policies --}}
<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

    <a href="{{ route('departments.sections.index', [$department->levelAlias(), $department]) }}"
       class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 flex flex-col gap-2 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
        <div class="flex items-center gap-3">
            <div class="stat-icon bg-indigo-50 dark:bg-indigo-900/30 flex-shrink-0">
                <i class="ti ti-layout-list text-indigo-500 dark:text-indigo-400"></i>
            </div>
            <p class="text-3xl font-bold text-slate-800 dark:text-slate-100">{{ $department->sections_count }}</p>
        </div>
        <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Sections</p>
        <p class="text-xs text-slate-400 dark:text-slate-500">{{ Str::plural('section', $department->sections_count) }} in this department</p>
    </a>

    <a href="{{ route('departments.rules.index', [$department->levelAlias(), $department]) }}"
       class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 flex flex-col gap-2 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
        <div class="flex items-center gap-3">
            <div class="stat-icon bg-blue-50 dark:bg-blue-900/30 flex-shrink-0">
                <i class="ti ti-book text-blue-500 dark:text-blue-400"></i>
            </div>
            <p class="text-3xl font-bold text-slate-800 dark:text-slate-100">{{ $rulesCount }}</p>
        </div>
        <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Rules &amp; Regulations</p>
        <p class="text-xs text-slate-400 dark:text-slate-500">{{ Str::plural('rule set', $rulesCount) }} in this department</p>
    </a>

    <a href="{{ route('departments.policy.index', [$department->levelAlias(), $department]) }}"
       class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 flex flex-col gap-2 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
        <div class="flex items-center gap-3">
            <div class="stat-icon bg-emerald-50 dark:bg-emerald-900/30 flex-shrink-0">
                <i class="ti ti-file-certificate text-emerald-500 dark:text-emerald-400"></i>
            </div>
            <p class="text-3xl font-bold text-slate-800 dark:text-slate-100">{{ $policiesCount }}</p>
        </div>
        <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Policies</p>
        <p class="text-xs text-slate-400 dark:text-slate-500">
            current {{ Str::plural('policy', $policiesCount) }}
            @if($historicalPoliciesCount > 0) · {{ $historicalPoliciesCount }} historical @endif
        </p>
    </a>

</div>

</x-layout>

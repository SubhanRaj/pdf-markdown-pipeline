<x-layout
    title="Sections"
    page-title="Sections"
    page-subtitle="{{ $department->name }} · {{ $sections->count() }} {{ Str::plural('section', $sections->count()) }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => 'Sections',                'url' => null],
]" />

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

</x-layout>

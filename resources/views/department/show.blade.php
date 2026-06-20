<x-layout
    title="{{ $department->name }}"
    page-title="{{ $department->name }}"
    page-subtitle="{{ $department->level === 'secretariat_level' ? 'Secretariat Level' : 'Department Level' }} · {{ $department->sections_count }} {{ Str::plural('section', $department->sections_count) }} · {{ $department->documents_count }} {{ Str::plural('document', $department->documents_count) }}"
>

<x-slot:breadcrumb>
    <a href="{{ route('home') }}" class="hover:text-slate-600 dark:hover:text-slate-300">Home</a>
    <i class="ti ti-chevron-right"></i>
    <a href="{{ route('vault.departments.index') }}" class="hover:text-slate-600 dark:hover:text-slate-300">Departments</a>
    <i class="ti ti-chevron-right"></i>
    <span>{{ $department->name }}</span>
</x-slot:breadcrumb>

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
    <a href="{{ route('vault.departments.edit', $department) }}"
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
        @auth @if(auth()->user()->isAdmin())
        <a href="{{ route('vault.departments.sections.create', $department) }}"
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
                <a href="{{ route('vault.departments.sections.show', [$department, $section]) }}"
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

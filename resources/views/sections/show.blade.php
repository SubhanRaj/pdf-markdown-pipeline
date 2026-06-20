<x-layout
    title="{{ $section->name }}"
    page-title="{{ $section->name }}"
    page-subtitle="{{ $department->name }} · {{ $section->documents_count }} {{ Str::plural('document', $section->documents_count) }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'Departments', 'url' => route('departments.index')],
    ['name' => $department->name, 'url' => route('departments.show', $department)],
    ['name' => $section->name, 'url' => null],
]" />

<div class="flex items-start justify-between gap-4 mb-6">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-indigo-500/10 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-layout-list text-indigo-500 dark:text-indigo-400 text-xl"></i>
        </div>
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $section->name }}</h2>
            <div class="flex items-center gap-2 mt-0.5">
                <span class="text-xs font-mono text-slate-400 dark:text-slate-500">{{ $section->slug }}</span>
                @if($section->wing)
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ str_replace('_', ' ', ucfirst($section->wing)) }}</span>
                @endif
            </div>
        </div>
    </div>
    @auth @if(auth()->user()->isAdmin())
    <a href="{{ route('departments.sections.edit', [$department, $section]) }}"
       class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
        <i class="ti ti-pencil text-base"></i> Edit
    </a>
    @endif @endauth
</div>

{{-- Documents placeholder --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Documents</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $section->documents_count }} {{ Str::plural('document', $section->documents_count) }} in this section</p>
        </div>
        @auth
        <a href="{{ route('documents.create') }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-upload text-base"></i> Upload
        </a>
        @endauth
    </div>
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-files text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No documents yet</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Upload a PDF to start converting it to Markdown.</p>
    </div>
</div>

</x-layout>

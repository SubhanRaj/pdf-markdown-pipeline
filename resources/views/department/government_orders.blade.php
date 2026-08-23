<x-layout
    title="Government Orders"
    page-title="Government Orders"
    :page-subtitle="$department->name . ' · ' . $documents->total() . ' ' . Str::plural('order', $documents->total())"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => 'Government Orders',       'url' => null],
]" />

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Government Orders</h3>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
            Every Government Order across all sections, divisions, and folders in this department
            @guest · public only @endguest
        </p>
    </div>

    @if($documents->isEmpty())
    <div class="flex flex-col items-center justify-center py-14 text-center">
        <i class="ti ti-file-certificate text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No Government Orders yet</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Documents uploaded with type "Government Order" appear here.</p>
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($documents as $doc)
        @php
            $docUrl = match(true) {
                $doc->folder && $doc->division => route('documents.divisions.folders.show', [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc->folder, $doc]),
                (bool) $doc->folder            => route('documents.folders.show',           [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->folder, $doc]),
                (bool) $doc->division          => route('documents.divisions.show',         [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc]),
                (bool) $doc->section           => route('documents.show',                   [$doc->department->levelAlias(), $doc->department, $doc->section, $doc]),
                default                        => route("documents.{$doc->ruleSet->kind}.show",             [$doc->department->levelAlias(), $doc->department, $doc->ruleSet, $doc]),
            };
        @endphp
        <x-document-row :doc="$doc" :url="$docUrl" />
        @endforeach
    </div>
    @endif

</div>

@if($documents->hasPages())
<div class="mt-4">
    {{ $documents->links() }}
</div>
@endif

</x-layout>

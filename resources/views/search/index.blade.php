<x-layout
    :title="$q ? 'Search: ' . $q : 'Search'"
    page-title="Search"
    :page-subtitle="$q ? 'Results for &quot;' . e($q) . '&quot;' : 'Find documents, sections, and rule sets'"
>

<x-breadcrumb :items="[
    ['name' => 'Home',   'url' => route('home')],
    ['name' => 'Search', 'url' => null],
]" />

{{-- ── Search bar ───────────────────────────────────────────────────────────── --}}
<form method="GET" action="{{ route('search.index') }}" role="search" class="mb-6">
    <div class="relative max-w-2xl">
        <i class="ti ti-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-base pointer-events-none"></i>
        <input
            type="search"
            name="q"
            value="{{ $q }}"
            placeholder="Search by title, section name, rule set… / शीर्षक, अनुभाग, नियम समूह द्वारा खोजें"
            autocomplete="off"
            autofocus
            class="w-full pl-11 pr-4 py-3 text-sm bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 placeholder-slate-400 dark:placeholder-slate-500 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 shadow-sm"
        >
        @if($q)
        <a href="{{ route('search.index') }}"
           class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
            <i class="ti ti-x text-sm"></i>
        </a>
        @endif
    </div>
</form>

@if(! $q)
{{-- ── Empty prompt state ───────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col items-center justify-center py-20 text-center">
    <i class="ti ti-search text-4xl text-slate-200 dark:text-slate-600 mb-3"></i>
    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Start typing to search</p>
    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Searches document titles, section names, and rule sets</p>
</div>

@elseif($documents->isEmpty() && $sections->isEmpty() && $ruleSets->isEmpty() && $divisions->isEmpty() && $folders->isEmpty())
{{-- ── No results ───────────────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col items-center justify-center py-20 text-center">
    <i class="ti ti-mood-sad text-4xl text-slate-200 dark:text-slate-600 mb-3"></i>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-300">No results for <span class="font-semibold">"{{ $q }}"</span></p>
    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Try a shorter term or check the spelling</p>
</div>

@else
{{-- ── Summary strip ────────────────────────────────────────────────────────── --}}
@php
    $total = $documents->count() + $sections->count() + $ruleSets->count() + $divisions->count() + $folders->count();
@endphp
<p class="text-xs text-slate-400 dark:text-slate-500 mb-5">
    {{ $total }} {{ Str::plural('result', $total) }} for
    <span class="font-semibold text-slate-600 dark:text-slate-300">"{{ $q }}"</span>
    @guest · verified documents only @endguest
</p>

{{-- ── Refactor / scope callout ─────────────────────────────────────────────── --}}
@if($sections->isNotEmpty() || $ruleSets->isNotEmpty())
<div class="mb-5 rounded-xl border border-indigo-200 dark:border-indigo-800 bg-indigo-50 dark:bg-indigo-900/20 px-5 py-3.5 flex gap-3 items-start">
    <i class="ti ti-info-circle text-indigo-500 dark:text-indigo-400 text-base mt-0.5 flex-shrink-0"></i>
    <div class="text-xs text-indigo-700 dark:text-indigo-300 leading-relaxed">
        <span class="font-semibold">Your query also matched related records.</span>
        Documents surfaced via their <span class="font-medium">section name</span> or <span class="font-medium">rule set name</span> are included in the Documents section below.
        Matching sections and rule sets are listed separately — click through to browse their full file lists.
    </div>
</div>
@endif

{{-- ══ Documents ════════════════════════════════════════════════════════════════ --}}
@if($documents->isNotEmpty())
<div class="mb-6">
    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-3 flex items-center gap-2">
        <i class="ti ti-file-text"></i> Documents
        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
            {{ $documents->count() }}
        </span>
    </h2>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($documents as $doc)
        @php
            $statusMeta   = \App\Models\Document::STATUSES[$doc->status] ?? ['label' => $doc->status, 'color' => 'slate'];
            $statusColors = [
                'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
                'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
                'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
                'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
                'green'  => 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
                'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
            ];
            $docUrl = match(true) {
                $doc->folder && $doc->division => route('documents.divisions.folders.show', [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc->folder, $doc]),
                (bool) $doc->folder            => route('documents.folders.show',           [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->folder, $doc]),
                (bool) $doc->division          => route('documents.divisions.show',         [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc]),
                (bool) $doc->section           => route('documents.show',                   [$doc->department->levelAlias(), $doc->department, $doc->section, $doc]),
                default                        => route('documents.rules.show',             [$doc->department->levelAlias(), $doc->department, $doc->ruleSet, $doc]),
            };
        @endphp
        <div class="flex items-start gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group">

            {{-- Status icon --}}
            <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 mt-0.5
                @if($doc->status === 'verified') bg-green-500/10 dark:bg-green-500/20
                @elseif($doc->status === 'failed') bg-red-500/10 dark:bg-red-500/20
                @elseif($doc->status === 'review') bg-indigo-500/10 dark:bg-indigo-500/20
                @else bg-slate-100 dark:bg-slate-700 @endif">
                <i class="ti ti-file-text text-base
                    @if($doc->status === 'verified') text-green-500 dark:text-green-400
                    @elseif($doc->status === 'failed') text-red-500 dark:text-red-400
                    @elseif($doc->status === 'review') text-indigo-500 dark:text-indigo-400
                    @else text-slate-400 dark:text-slate-500 @endif"></i>
            </div>

            {{-- Info --}}
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $doc->title }}</p>
                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                    <span class="text-xs text-slate-400 dark:text-slate-500">
                        {{ $doc->department->name }}
                    </span>
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500">
                        {{ $doc->folder?->name ?? $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name ?? '—' }}
                    </span>
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                        {{ \App\Models\Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type }}
                    </span>
                    @auth
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $statusColors[$statusMeta['color']] ?? $statusColors['slate'] }}">
                        {{ $statusMeta['label'] }}
                    </span>
                    @endauth
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $doc->created_at->format('d M Y') }}</span>
                </div>
            </div>

            {{-- View action --}}
            <a href="{{ $docUrl }}"
               class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all"
               title="View document">
                <i class="ti ti-eye text-base"></i>
            </a>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ══ Sections ═════════════════════════════════════════════════════════════════ --}}
@if($sections->isNotEmpty())
<div class="mb-6">
    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-3 flex items-center gap-2">
        <i class="ti ti-folders"></i> Sections
        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
            {{ $sections->count() }}
        </span>
    </h2>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($sections as $section)
        <div class="flex items-center gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group">
            <div class="w-9 h-9 rounded-lg bg-sky-500/10 dark:bg-sky-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-folder text-base text-sky-500 dark:text-sky-400"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $section->name }}</p>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                    {{ $section->department->name }}
                    @if($section->wing)
                    <span class="text-slate-300 dark:text-slate-600">·</span> {{ str_replace('_', ' ', $section->wing) }}
                    @endif
                </p>
            </div>
            <a href="{{ route('departments.sections.show', [$section->department->levelAlias(), $section->department, $section]) }}"
               class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-sky-600 dark:hover:text-sky-400 hover:bg-sky-50 dark:hover:bg-sky-900/20 transition-all"
               title="Browse section">
                <i class="ti ti-arrow-right text-base"></i>
            </a>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ══ Rule Sets ════════════════════════════════════════════════════════════════ --}}
@if($ruleSets->isNotEmpty())
<div class="mb-6">
    <h2 class="text-xs font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500 mb-3 flex items-center gap-2">
        <i class="ti ti-book"></i> Rule Sets
        <span class="inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
            {{ $ruleSets->count() }}
        </span>
    </h2>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($ruleSets as $ruleSet)
        <div class="flex items-center gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group">
            <div class="w-9 h-9 rounded-lg bg-violet-500/10 dark:bg-violet-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-book text-base text-violet-500 dark:text-violet-400"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $ruleSet->name }}</p>
                <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $ruleSet->department->name }}</span>
                    @if($ruleSet->description)
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500 truncate max-w-xs">{{ Str::limit($ruleSet->description, 60) }}</span>
                    @endif
                </div>
            </div>
            <a href="{{ route('departments.rules.show', [$ruleSet->department->levelAlias(), $ruleSet->department, $ruleSet]) }}"
               class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-violet-600 dark:hover:text-violet-400 hover:bg-violet-50 dark:hover:bg-violet-900/20 transition-all"
               title="Browse rule set">
                <i class="ti ti-arrow-right text-base"></i>
            </a>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ── Divisions ──────────────────────────────────────────────────────────── --}}
@if($divisions->isNotEmpty())
<div class="mt-8">
    <h2 class="text-xs font-semibold uppercase tracking-widest text-teal-600 dark:text-teal-400 mb-3 flex items-center gap-2">
        <i class="ti ti-layout-sidebar"></i> Internal Divisions
        <span class="text-teal-400 dark:text-teal-600 font-bold">
            {{ $divisions->count() }}
        </span>
    </h2>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($divisions as $div)
        <div class="flex items-center gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group">
            <div class="w-9 h-9 rounded-lg bg-teal-500/10 dark:bg-teal-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-layout-sidebar text-base text-teal-500 dark:text-teal-400"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $div->name }}</p>
                <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $div->section->department->name }}</span>
                    <span class="text-slate-300 dark:text-slate-600">›</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $div->section->name }}</span>
                    @if($div->description)
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500 truncate max-w-xs">{{ Str::limit($div->description, 60) }}</span>
                    @endif
                </div>
            </div>
            <a href="{{ route('departments.sections.divisions.show', [$div->section->department->levelAlias(), $div->section->department, $div->section, $div]) }}"
               class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-teal-600 dark:hover:text-teal-400 hover:bg-teal-50 dark:hover:bg-teal-900/20 transition-all"
               title="Browse division">
                <i class="ti ti-arrow-right text-base"></i>
            </a>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ── Folders ────────────────────────────────────────────────────────────── --}}
@if($folders->isNotEmpty())
<div class="mt-8">
    <h2 class="text-xs font-semibold uppercase tracking-widest text-cyan-600 dark:text-cyan-400 mb-3 flex items-center gap-2">
        <i class="ti ti-folder-star"></i> Folders
        <span class="text-cyan-400 dark:text-cyan-600 font-bold">
            {{ $folders->count() }}
        </span>
    </h2>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($folders as $folder)
        @php
            $folderUrl = $folder->division
                ? route('departments.sections.divisions.folders.show', [$folder->department->levelAlias(), $folder->department, $folder->section, $folder->division, $folder])
                : route('departments.sections.folders.show', [$folder->department->levelAlias(), $folder->department, $folder->section, $folder]);
        @endphp
        <div class="flex items-center gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group">
            <div class="w-9 h-9 rounded-lg bg-cyan-500/10 dark:bg-cyan-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-folder-star text-base text-cyan-500 dark:text-cyan-400"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $folder->name }}</p>
                    @if($folder->visibility === 'authenticated')
                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 flex-shrink-0">
                        <i class="ti ti-lock text-[10px]"></i> Authenticated
                    </span>
                    @endif
                </div>
                <div class="flex items-center gap-2 mt-0.5">
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $folder->department->name }}</span>
                    <span class="text-slate-300 dark:text-slate-600">›</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $folder->division?->name ?? $folder->section->name }}</span>
                    @if($folder->description)
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500 truncate max-w-xs">{{ Str::limit($folder->description, 60) }}</span>
                    @endif
                </div>
            </div>
            <a href="{{ $folderUrl }}"
               class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-cyan-600 dark:hover:text-cyan-400 hover:bg-cyan-50 dark:hover:bg-cyan-900/20 transition-all"
               title="Browse folder">
                <i class="ti ti-arrow-right text-base"></i>
            </a>
        </div>
        @endforeach
    </div>
</div>
@endif

@endif

</x-layout>

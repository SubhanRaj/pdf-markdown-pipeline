@php
    $statusMeta = \App\Models\Document::STATUSES[$document->status] ?? ['label' => $document->status, 'color' => 'slate'];
    $statusColors = [
        'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
        'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300',
        'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
        'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300',
        'green'  => 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300',
        'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300',
    ];
    $statusClass = $statusColors[$statusMeta['color']] ?? $statusColors['slate'];
    $wing = $section->wing;
@endphp

<x-layout
    title="{{ $document->title }}"
    page-title="{{ $document->title }}"
    page-subtitle="{{ $document->department->name }}{{ $wing ? ' · ' . Str::title(str_replace('_', ' ', $wing)) : '' }} · {{ $document->section->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                              'url' => route('home')],
    ['name' => 'Departments',                       'url' => route('departments.index')],
    ['name' => $document->department->levelLabel(), 'url' => null],
    ['name' => $document->department->name,         'url' => route('departments.show', [$document->department->levelAlias(), $document->department])],
    ['name' => $document->section->name,            'url' => route('departments.sections.show', [$document->department->levelAlias(), $document->department, $document->section])],
    ['name' => $document->title,                    'url' => null],
]" />

{{-- ── Document header ──────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-6">
    <div class="flex items-start gap-4">
        <div class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0
            @if($document->status === 'verified') bg-green-500/10 dark:bg-green-500/20
            @elseif($document->status === 'failed') bg-red-500/10 dark:bg-red-500/20
            @elseif($document->status === 'review') bg-indigo-500/10 dark:bg-indigo-500/20
            @else bg-slate-100 dark:bg-slate-800 @endif">
            <i class="ti ti-file-description text-xl
                @if($document->status === 'verified') text-green-500 dark:text-green-400
                @elseif($document->status === 'failed') text-red-500 dark:text-red-400
                @elseif($document->status === 'review') text-indigo-500 dark:text-indigo-400
                @else text-slate-400 dark:text-slate-500 @endif"></i>
        </div>
        <div class="min-w-0">
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 leading-snug">{{ $document->title }}</h2>
            <div class="flex items-center gap-2 mt-1 flex-wrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $statusClass }}">
                    {{ $statusMeta['label'] }}
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                    {{ \App\Models\Document::DOCUMENT_TYPES[$document->document_type] ?? $document->document_type }}
                </span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $document->created_at->format('d M Y') }}</span>
                @if($document->user)
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $document->user->name }}</span>
                @endif
            </div>
        </div>
    </div>

    @auth
    @if(auth()->user()->isAdmin())
    <div class="flex items-center gap-2 flex-shrink-0">
        <a href="{{ route('documents.edit', [$department->levelAlias(), $department, $section, $document]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-pencil text-base"></i>
            <span class="hidden sm:inline">Review</span>
        </a>
        <form method="POST" action="{{ route('documents.destroy', [$department->levelAlias(), $department, $section, $document]) }}"
              onsubmit="return confirm('Permanently delete this document? This cannot be undone.')">
            @csrf @method('DELETE')
            <button type="submit"
                    class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
                <i class="ti ti-trash text-base"></i>
            </button>
        </form>
    </div>
    @endif
    @endauth
</div>

{{-- ── Vault path ────────────────────────────────────────────────────────────── --}}
@auth
<div class="mb-6 flex items-center gap-1 flex-wrap">
    @php
        $crumbs = array_filter([
            'Vault',
            Str::title(str_replace('_', ' ', $document->department->level)),
            $document->department->name,
            $wing ? Str::title(str_replace('_', ' ', $wing)) : null,
            $document->section->name,
            $document->original_filename,
        ]);
    @endphp
    @foreach($crumbs as $crumb)
        @if(!$loop->first)<span class="text-xs text-slate-300 dark:text-slate-700">/</span>@endif
        <span class="text-xs font-mono {{ $loop->last ? 'text-indigo-500 dark:text-indigo-400' : 'text-slate-400 dark:text-slate-600' }}">{{ $crumb }}</span>
    @endforeach
</div>
@endauth

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- ── Main: markdown content or placeholder ───────────────────────────── --}}
    <div class="lg:col-span-2 space-y-4">

        {{-- PDF viewer — always shown; served via /documents/{id}/pdf which streams from public disk --}}
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="ti ti-file-type-pdf text-sm text-red-400"></i>
                    <span class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Original Document</span>
                </div>
                <a href="{{ route('documents.pdf', [$department->levelAlias(), $department, $section, $document]) }}" target="_blank"
                   class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1">
                    Open in new tab <i class="ti ti-external-link text-xs"></i>
                </a>
            </div>
            <iframe src="{{ route('documents.pdf', [$department->levelAlias(), $department, $section, $document]) }}"
                    class="w-full border-0"
                    style="height: 75vh;"
                    title="{{ $document->title }}">
            </iframe>
        </div>

        {{-- Extracted markdown — shown below PDF once extraction is complete --}}
        @if($document->markdown_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($document->markdown_path))
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700 flex items-center gap-2">
                <i class="ti ti-markdown text-sm text-slate-400"></i>
                <span class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Extracted Content</span>
            </div>
            <div class="px-6 py-5 prose prose-sm dark:prose-invert max-w-none text-slate-700 dark:text-slate-300">
                {!! \Parsedown::instance()->setSafeMode(true)->text(\Illuminate\Support\Facades\Storage::disk('public')->get($document->markdown_path)) !!}
            </div>
        </div>
        @endif

    </div>

    {{-- ── Sidebar: metadata + status history ──────────────────────────────── --}}
    <div class="space-y-4">

        {{-- Metadata card --}}
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700">
                <span class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Details</span>
            </div>
            <dl class="px-5 py-4 space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Department</dt>
                    <dd>
                        <a href="{{ route('departments.show', [$document->department->levelAlias(), $document->department]) }}"
                           class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm">
                            {{ $document->department->name }}
                        </a>
                    </dd>
                </div>
                @if($wing)
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Wing</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ Str::title(str_replace('_', ' ', $wing)) }}</dd>
                </div>
                @endif
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Section</dt>
                    <dd>
                        <a href="{{ route('departments.sections.show', [$document->department->levelAlias(), $document->department, $document->section]) }}"
                           class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm">
                            {{ $document->section->name }}
                        </a>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Type</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ \App\Models\Document::DOCUMENT_TYPES[$document->document_type] ?? $document->document_type }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Original file</dt>
                    <dd class="text-slate-700 dark:text-slate-200 font-mono text-xs break-all">{{ $document->original_filename }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Uploaded</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $document->created_at->format('d M Y, H:i') }}</dd>
                </div>
                @if($document->user)
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Uploaded by</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $document->user->name }}</dd>
                </div>
                @endif
                @if($document->metadata)
                @foreach($document->metadata as $key => $value)
                @if($value)
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">{{ Str::title(str_replace('_', ' ', $key)) }}</dt>
                    <dd class="text-slate-700 dark:text-slate-200 text-xs">{{ $value }}</dd>
                </div>
                @endif
                @endforeach
                @endif
            </dl>
        </div>

        {{-- Status history --}}
        @auth
        @if($document->statusHistory->isNotEmpty())
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700">
                <span class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Status History</span>
            </div>
            <ol class="px-5 py-4 space-y-4">
                @foreach($document->statusHistory->sortByDesc('created_at') as $entry)
                <li class="flex gap-3">
                    <div class="w-1.5 h-1.5 rounded-full bg-indigo-400 dark:bg-indigo-500 mt-1.5 flex-shrink-0"></div>
                    <div class="min-w-0">
                        <div class="flex items-center gap-1.5 flex-wrap">
                            @if($entry->from_status)
                            <span class="text-xs text-slate-400 dark:text-slate-500">{{ \App\Models\Document::STATUSES[$entry->from_status]['label'] ?? $entry->from_status }}</span>
                            <i class="ti ti-arrow-right text-[10px] text-slate-300 dark:text-slate-600"></i>
                            @endif
                            <span class="text-xs font-medium text-slate-700 dark:text-slate-200">{{ \App\Models\Document::STATUSES[$entry->to_status]['label'] ?? $entry->to_status }}</span>
                        </div>
                        @if($entry->note)
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $entry->note }}</p>
                        @endif
                        <p class="text-[10px] text-slate-300 dark:text-slate-600 mt-0.5">
                            {{ $entry->created_at->format('d M Y, H:i') }}
                            @if($entry->actor) · {{ $entry->actor->name }} @endif
                        </p>
                    </div>
                </li>
                @endforeach
            </ol>
        </div>
        @endif
        @endauth

    </div>
</div>

</x-layout>

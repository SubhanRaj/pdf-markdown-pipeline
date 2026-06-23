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

    // Context: rule-set, division, or direct section document
    $isRuleSetDoc  = isset($ruleSet)  && $ruleSet  !== null;
    $isDivisionDoc = isset($division) && $division !== null;
    $wing          = ($isRuleSetDoc || $isDivisionDoc) ? null : ($section->wing ?? null);

    if ($isRuleSetDoc) {
        $contextName    = $ruleSet->name;
        $contextUrl     = route('departments.rules.show',   [$department->levelAlias(), $department, $ruleSet]);
        $pdfRoute       = route('documents.rules.pdf',      [$department->levelAlias(), $department, $ruleSet, $document]);
        $editRoute      = route('documents.rules.edit',     [$department->levelAlias(), $department, $ruleSet, $document]);
        $updateRoute    = route('documents.rules.update',   [$department->levelAlias(), $department, $ruleSet, $document]);
        $destroyRoute   = route('documents.rules.destroy',  [$department->levelAlias(), $department, $ruleSet, $document]);
    } elseif ($isDivisionDoc) {
        $contextName    = $division->name;
        $contextUrl     = route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]);
        $pdfRoute       = route('documents.divisions.pdf',    [$department->levelAlias(), $department, $section, $division, $document]);
        $editRoute      = route('documents.divisions.edit',   [$department->levelAlias(), $department, $section, $division, $document]);
        $updateRoute    = route('documents.divisions.update', [$department->levelAlias(), $department, $section, $division, $document]);
        $destroyRoute   = route('documents.divisions.destroy',[$department->levelAlias(), $department, $section, $division, $document]);
    } else {
        $contextName    = $section->name;
        $contextUrl     = route('departments.sections.show', [$department->levelAlias(), $department, $section]);
        $pdfRoute       = route('documents.pdf',     [$department->levelAlias(), $department, $section, $document]);
        $editRoute      = route('documents.edit',    [$department->levelAlias(), $department, $section, $document]);
        $updateRoute    = route('documents.update',  [$department->levelAlias(), $department, $section, $document]);
        $destroyRoute   = route('documents.destroy', [$department->levelAlias(), $department, $section, $document]);
    }
@endphp

<x-layout
    title="{{ $document->title }}"
    page-title="{{ $document->title }}"
    page-subtitle="{{ $document->department->name }}{{ $wing ? ' · ' . Str::title(str_replace('_', ' ', $wing)) : '' }} · {{ $contextName }}"
>

@php
    $breadcrumbItems = [
        ['name' => 'Home',                              'url' => route('home')],
        ['name' => 'Departments',                       'url' => route('departments.index')],
        ['name' => $document->department->levelLabel(), 'url' => null],
        ['name' => $document->department->name,         'url' => route('departments.show', [$document->department->levelAlias(), $document->department])],
    ];
    if ($isDivisionDoc) {
        $breadcrumbItems[] = ['name' => $section->name, 'url' => route('departments.sections.show', [$department->levelAlias(), $department, $section])];
    }
    $breadcrumbItems[] = ['name' => $contextName, 'url' => $contextUrl];
    $breadcrumbItems[] = ['name' => $document->title, 'url' => null];
@endphp
<x-breadcrumb :items="$breadcrumbItems" />

{{-- ── Amendment context bar ─────────────────────────────────────────────────── --}}
@if($document->amendments->isNotEmpty())
<div class="mb-4 flex items-center gap-2.5 px-4 py-2.5 rounded-xl border border-amber-300 dark:border-amber-600 bg-amber-50 dark:bg-amber-900/20">
    <i class="ti ti-alert-triangle text-amber-500 dark:text-amber-400 text-base flex-shrink-0"></i>
    <p class="text-xs font-medium text-amber-800 dark:text-amber-300 flex-1">
        This document has been amended — see the <strong>Amendments</strong> section below before acting on this version.
    </p>
    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded bg-amber-200 dark:bg-amber-800/50 text-amber-700 dark:text-amber-300 flex-shrink-0">
        {{ $document->amendments->count() }} {{ Str::plural('amendment', $document->amendments->count()) }}
    </span>
</div>
@endif

@if($document->parent_id && $document->parentDocument)
<div class="mb-4 flex items-center gap-2.5 px-4 py-2.5 rounded-xl border border-blue-200 dark:border-blue-600 bg-blue-50 dark:bg-blue-900/20">
    <i class="ti ti-git-merge text-blue-500 dark:text-blue-400 text-base flex-shrink-0"></i>
    <p class="text-xs text-blue-700 dark:text-blue-300 flex-1">
        This is an amendment to
        <a href="{{ $isRuleSetDoc
            ? route('documents.rules.show', [$department->levelAlias(), $department, $ruleSet, $document->parentDocument])
            : route('documents.show',       [$department->levelAlias(), $department, $section, $document->parentDocument]) }}"
           class="font-semibold hover:underline">{{ $document->parentDocument->title }}</a>
        <span class="text-blue-400 dark:text-blue-500">({{ $document->parentDocument->created_at->format('d M Y') }})</span>
    </p>
</div>
@endif

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
                @if($document->visibility === 'authenticated')
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                    <i class="ti ti-lock text-[10px]"></i> Authenticated Only
                </span>
                @else
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">
                    <i class="ti ti-world text-[10px]"></i> Public
                </span>
                @endif
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
    @php $ruleIsLocked = $document->document_type === 'rule' && $document->amendments->isNotEmpty(); @endphp
    <div class="flex items-center gap-2 flex-shrink-0">
        @if(! $ruleIsLocked)
        <a href="{{ $editRoute }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-pencil text-base"></i>
            <span class="hidden sm:inline">Edit</span>
        </a>
        @else
        <span title="Cannot edit a rule document that has amendments"
              class="inline-flex items-center gap-1.5 bg-slate-50 dark:bg-slate-800/50 border border-slate-100 dark:border-slate-700/50 text-slate-300 dark:text-slate-600 text-sm font-medium px-3 py-2 rounded-lg cursor-not-allowed">
            <i class="ti ti-pencil text-base"></i>
            <span class="hidden sm:inline">Edit</span>
        </span>
        @endif
        <button type="button" id="delete-doc-btn"
                class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-trash text-base"></i>
        </button>
        <form id="delete-doc-form" method="POST" action="{{ $destroyRoute }}">
            @csrf @method('DELETE')
            <input type="hidden" name="reason" id="delete-doc-reason">
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
            $isRuleSetDoc ? 'rules' : null,
            $contextName,
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

    {{-- ── Main: PDF viewer + extracted markdown ───────────────────────────── --}}
    <div class="lg:col-span-2 space-y-4">

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <i class="ti ti-file-type-pdf text-sm text-red-400"></i>
                    <span class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Original Document</span>
                </div>
                <a href="{{ $pdfRoute }}" target="_blank"
                   class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1">
                    Open in new tab <i class="ti ti-external-link text-xs"></i>
                </a>
            </div>
            <iframe src="{{ $pdfRoute }}"
                    class="w-full border-0"
                    style="height: 75vh;"
                    title="{{ $document->title }}">
            </iframe>
        </div>

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
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">{{ $isRuleSetDoc ? 'Rule Set' : 'Section' }}</dt>
                    <dd>
                        <a href="{{ $contextUrl }}"
                           class="text-indigo-600 dark:text-indigo-400 hover:underline text-sm">
                            {{ $contextName }}
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
                @php
                    $mAnNo  = $document->metadata['amendment_number'] ?? null;
                    $mEY    = $document->metadata['effective_year']   ?? null;
                    $mEM    = $document->metadata['effective_month']  ?? null;
                    $mED    = $document->metadata['effective_day']    ?? null;
                    $mMon   = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
                    $mDate  = $mEY
                        ? ($mED && $mEM ? "{$mED} {$mMon[$mEM]} {$mEY}" : ($mEM ? "{$mMon[$mEM]} {$mEY}" : (string) $mEY))
                        : null;
                @endphp
                @if($mAnNo)
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Amendment No.</dt>
                    <dd class="text-slate-700 dark:text-slate-200 font-semibold">#{{ $mAnNo }}</dd>
                </div>
                @endif
                @if($mDate)
                <div>
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Effective Date</dt>
                    <dd class="text-slate-700 dark:text-slate-200">{{ $mDate }}</dd>
                </div>
                @endif
                @endif
            </dl>

            @auth
            @if(auth()->user()->isAdmin())
            {{-- Visibility control for admins --}}
            <div class="px-5 pb-4 pt-1 border-t border-slate-100 dark:border-slate-700">
                <p class="text-xs text-slate-400 dark:text-slate-500 mb-2">Visibility</p>
                <form id="visibility-form" method="POST" action="{{ $updateRoute }}">
                    @csrf @method('PATCH')
                    <div class="flex flex-col gap-2">
                        <label class="flex items-center gap-2.5 cursor-pointer select-none group">
                            <input type="radio" name="visibility" value="public" id="vis-public"
                                   @checked($document->visibility === 'public')
                                   class="text-green-500 focus:ring-green-500 focus:ring-offset-0 dark:bg-slate-700 dark:border-slate-600">
                            <span class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-200 group-hover:text-green-600 dark:group-hover:text-green-400 transition-colors">
                                <i class="ti ti-world text-base text-green-500"></i> Public
                            </span>
                        </label>
                        <label class="flex items-center gap-2.5 cursor-pointer select-none group">
                            <input type="radio" name="visibility" value="authenticated" id="vis-auth"
                                   @checked($document->visibility === 'authenticated')
                                   class="text-amber-500 focus:ring-amber-500 focus:ring-offset-0 dark:bg-slate-700 dark:border-slate-600">
                            <span class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-200 group-hover:text-amber-600 dark:group-hover:text-amber-400 transition-colors">
                                <i class="ti ti-lock text-base text-amber-500"></i> Authenticated Only
                            </span>
                        </label>
                    </div>
                </form>
            </div>
            @endif
            @endauth

        </div>

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

{{-- ── Amendments section ───────────────────────────────────────────────────── --}}
@if($document->amendments->isNotEmpty())
<div class="mt-6 bg-white dark:bg-slate-800 rounded-xl border border-amber-200 dark:border-amber-700/50">
    <div class="px-5 py-4 border-b border-amber-100 dark:border-amber-700/40 flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-git-merge text-amber-600 dark:text-amber-400 text-base"></i>
        </div>
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">
                Amendments
                <span class="ml-1.5 inline-flex items-center px-1.5 py-0.5 rounded-full text-[10px] font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400">
                    {{ $document->amendments->count() }}
                </span>
            </h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Documents that formally amend or supersede this version</p>
        </div>
    </div>
    <div class="divide-y divide-amber-50 dark:divide-amber-900/20">
        @foreach($document->amendments as $i => $amendment)
        @php
            $aSm = \App\Models\Document::STATUSES[$amendment->status] ?? ['label' => $amendment->status, 'color' => 'slate'];
            $aSc = ['slate'=>'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400','blue'=>'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400','amber'=>'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400','indigo'=>'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400','green'=>'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400','red'=>'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400'];
        @endphp
        <div class="flex items-center gap-3 px-5 py-3.5 hover:bg-amber-50/50 dark:hover:bg-amber-900/10 transition-colors group">
            {{-- Sequence number --}}
            <span class="w-6 h-6 rounded-full bg-amber-100 dark:bg-amber-900/40 text-amber-600 dark:text-amber-400 text-[10px] font-bold flex items-center justify-center flex-shrink-0">
                {{ $i + 1 }}
            </span>
            {{-- Status icon --}}
            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0
                @if($amendment->status === 'verified') bg-green-500/10 dark:bg-green-500/20
                @elseif($amendment->status === 'failed') bg-red-500/10 dark:bg-red-500/20
                @else bg-slate-100 dark:bg-slate-700 @endif">
                <i class="ti ti-file-text text-sm
                    @if($amendment->status === 'verified') text-green-500 dark:text-green-400
                    @elseif($amendment->status === 'failed') text-red-500 dark:text-red-400
                    @else text-slate-400 dark:text-slate-500 @endif"></i>
            </div>
            {{-- Info --}}
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $amendment->title }}</p>
                <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                    @auth
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $aSc[$aSm['color']] ?? $aSc['slate'] }}">
                        {{ $aSm['label'] }}
                    </span>
                    @endauth
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $amendment->created_at->format('d M Y') }}</span>
                </div>
            </div>
            {{-- View link --}}
            <a href="{{ $isRuleSetDoc
                ? route('documents.rules.show', [$department->levelAlias(), $department, $ruleSet, $amendment])
                : route('documents.show',       [$department->levelAlias(), $department, $section, $amendment]) }}"
               class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center gap-1.5 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                View <i class="ti ti-arrow-right text-xs"></i>
            </a>
        </div>
        @endforeach
    </div>
</div>
@endif

@push('scripts')
<script>
try {
    const visForm = document.getElementById('visibility-form');
    if (visForm) {
        visForm.querySelectorAll('input[type="radio"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                visForm.submit();
            });
        });
    }
} catch (e) {
    console.error('Visibility radio init failed:', e);
}

try {
    const deleteBtn = document.getElementById('delete-doc-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            const isDark = document.documentElement.classList.contains('dark');
            Swal.fire({
                title: 'Move to Trash',
                html: '<p class="text-sm text-gray-500 mb-3">Provide a reason for removing this document. It will be recorded in the audit trail and can be reviewed in the trash.</p>',
                input: 'textarea',
                inputPlaceholder: 'Reason for deletion (required)…',
                inputAttributes: { maxlength: 500, rows: 3 },
                showCancelButton: true,
                confirmButtonText: '<i class="ti ti-trash mr-1"></i> Move to Trash',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ef4444',
                background: isDark ? '#1e293b' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#0f172a',
                inputValidator: (value) => {
                    if (!value || value.trim().length < 5) {
                        return 'Please enter a reason (minimum 5 characters).';
                    }
                },
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('delete-doc-reason').value = result.value;
                    document.getElementById('delete-doc-form').submit();
                }
            });
        });
    }
} catch (e) {
    console.error('Delete modal init failed:', e);
}
</script>
@endpush

</x-layout>

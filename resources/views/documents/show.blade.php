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

    $hasMarkdown = $document->markdown_path && \Illuminate\Support\Facades\Storage::disk('public')->exists($document->markdown_path);
    $isConverting = in_array($document->status, ['processing', 'ocr_pending'], true);
    $needsOcrReview = (bool) ($document->metadata['needs_ocr_review'] ?? false);
    $isOcrResult = ($document->metadata['extraction_method'] ?? null) === 'ocr';
    $preOcrBackupPath = $document->original_pdf_path ? preg_replace('/\.pdf$/i', '.pre-ocr.md', $document->original_pdf_path) : null;
    $canRevertOcr = $isOcrResult && $preOcrBackupPath && \Illuminate\Support\Facades\Storage::disk('public')->exists($preOcrBackupPath);

    // Normalize optional context variables — not every route passes all of these,
    // and closures below capture them by value via `use (...)`, which errors on
    // truly-undefined variables (isset() alone doesn't prevent that).
    $ruleSet  = $ruleSet  ?? null;
    $division = $division ?? null;
    $folder   = $folder   ?? null;
    $section  = $section  ?? null;

    // Context: rule-set, section-folder, division-folder, division, or direct section document
    $isRuleSetDoc        = $ruleSet  !== null;
    $isFolderDoc         = $folder   !== null;
    $isDivisionFolderDoc = $isFolderDoc && $division !== null;
    $isSectionFolderDoc  = $isFolderDoc && ! $isDivisionFolderDoc;
    $isDivisionDoc       = ! $isFolderDoc && $division !== null;
    $wing                = ($isRuleSetDoc || $isDivisionDoc || $isFolderDoc) ? null : ($section->wing ?? null);

    $isPolicyDoc = $isRuleSetDoc && $ruleSet->kind === 'policy';
    // Policy documents are managed by admins or the owning department's department.head only —
    // everyone else who could normally edit/convert/verify a document is view-only for policy.
    $canManageDoc = auth()->check() && (
        auth()->user()->isAdmin()
        || ($isPolicyDoc && auth()->user()->canManagePolicy($ruleSet))
    );

    if ($isRuleSetDoc) {
        $contextName    = $ruleSet->name;
        $contextUrl     = route("departments.{$ruleSet->kind}.show",   [$department->levelAlias(), $department, $ruleSet]);
        $pdfRoute       = route("documents.{$ruleSet->kind}.pdf",      [$department->levelAlias(), $department, $ruleSet, $document]);
        $editRoute      = route("documents.{$ruleSet->kind}.edit",     [$department->levelAlias(), $department, $ruleSet, $document]);
        $updateRoute    = route("documents.{$ruleSet->kind}.update",   [$department->levelAlias(), $department, $ruleSet, $document]);
        $destroyRoute   = route("documents.{$ruleSet->kind}.destroy",  [$department->levelAlias(), $department, $ruleSet, $document]);
    } elseif ($isDivisionFolderDoc) {
        $contextName    = $folder->name;
        $contextUrl     = route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder]);
        $pdfRoute       = route('documents.divisions.folders.pdf',     [$department->levelAlias(), $department, $section, $division, $folder, $document]);
        $editRoute      = route('documents.divisions.folders.edit',    [$department->levelAlias(), $department, $section, $division, $folder, $document]);
        $updateRoute    = route('documents.divisions.folders.update',  [$department->levelAlias(), $department, $section, $division, $folder, $document]);
        $destroyRoute   = route('documents.divisions.folders.destroy', [$department->levelAlias(), $department, $section, $division, $folder, $document]);
    } elseif ($isSectionFolderDoc) {
        $contextName    = $folder->name;
        $contextUrl     = route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $folder]);
        $pdfRoute       = route('documents.folders.pdf',     [$department->levelAlias(), $department, $section, $folder, $document]);
        $editRoute      = route('documents.folders.edit',    [$department->levelAlias(), $department, $section, $folder, $document]);
        $updateRoute    = route('documents.folders.update',  [$department->levelAlias(), $department, $section, $folder, $document]);
        $destroyRoute   = route('documents.folders.destroy', [$department->levelAlias(), $department, $section, $folder, $document]);
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

    // Resolves the show URL for another document within this same context
    // (used for parent-document and amendment cross-links below).
    $linkForDoc = function ($doc) use ($isRuleSetDoc, $isDivisionFolderDoc, $isSectionFolderDoc, $isDivisionDoc, $department, $section, $division, $ruleSet, $folder) {
        if ($isRuleSetDoc)        return route("documents.{$ruleSet->kind}.show",            [$department->levelAlias(), $department, $ruleSet, $doc]);
        if ($isDivisionFolderDoc) return route('documents.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder, $doc]);
        if ($isSectionFolderDoc)  return route('documents.folders.show',          [$department->levelAlias(), $department, $section, $folder, $doc]);
        if ($isDivisionDoc)       return route('documents.divisions.show',        [$department->levelAlias(), $department, $section, $division, $doc]);
        return route('documents.show', [$department->levelAlias(), $department, $section, $doc]);
    };
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
    if ($isDivisionDoc || $isFolderDoc) {
        $breadcrumbItems[] = ['name' => $section->name, 'url' => route('departments.sections.show', [$department->levelAlias(), $department, $section])];
    }
    if ($isDivisionFolderDoc) {
        $breadcrumbItems[] = ['name' => $division->name, 'url' => route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division])];
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
        <a href="{{ $linkForDoc($document->parentDocument) }}"
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
    @if($canManageDoc)
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
        @if(! $hasMarkdown)
        <button type="button" id="convert-doc-btn" data-convert-url="{{ route('documents.convert', $document->id) }}"
                data-convert-status-url="{{ route('documents.convert-status', $document->id) }}"
                @if($isConverting) disabled @endif
                class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed">
            <i class="ti {{ $isConverting ? 'ti-loader-2 animate-spin' : 'ti-markdown' }} text-base" id="convert-doc-btn-icon"></i>
            <span class="hidden sm:inline" id="convert-doc-btn-label">
                @if($isConverting) Converting…
                @elseif($document->status === 'failed') Retry Conversion
                @else Convert to Markdown
                @endif
            </span>
        </button>
        @endif
        <button type="button" id="delete-doc-btn" @if($isConverting) disabled title="Wait for conversion to finish" @endif
                class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-3 py-2 rounded-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed">
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
            $isRuleSetDoc ? $ruleSet->kind : null,
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

    {{-- ── Main: swap-view PDF / Markdown ───────────────────────────────────── --}}
    <div class="lg:col-span-2 space-y-4">

        @php
            $extractionMethod = $document->metadata['extraction_method'] ?? null;
            $isVerified = $document->status === 'verified';
        @endphp
        @if($hasMarkdown)
        @php
            $mdRaw  = \Illuminate\Support\Facades\Storage::disk('public')->get($document->markdown_path);
            $mdHtml = \Parsedown::instance()->setSafeMode(true)->text($mdRaw);
            // Parsedown safe mode strips <script> tags but does NOT sanitize
            // javascript:/data: URIs in href/src attributes (known limitation of
            // erusev/parsedown ^1.0). Strip them here to close the stored-XSS vector
            // that could be introduced by markitdown-extracted content from crafted docs.
            $mdHtml = preg_replace(
                '/\b(href|src)\s*=\s*(["\'])(?:javascript|data|vbscript):[^"\']*\2/i',
                '$1=$2#$2',
                $mdHtml
            );
        @endphp
        @endif

        {{-- ── Conversion status strip — sits above the viewer so it's the first thing seen ──── --}}
        @if($isConverting)
        <div id="markdown-card" class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-xl px-6 py-8 text-center" data-poll-status-url="{{ route('documents.convert-status', $document->id) }}">
            <i class="ti ti-loader-2 animate-spin text-2xl text-indigo-500 dark:text-indigo-400"></i>
            <p class="mt-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">
                {{ $document->status === 'ocr_pending' ? 'Running OCR (Hindi + English)…' : 'Converting to Markdown…' }}
            </p>
            <p class="mt-1 text-xs text-indigo-500 dark:text-indigo-400">
                Elapsed <span id="convert-elapsed">0:00</span> — OCR on scanned documents can take several minutes. This page updates automatically.
            </p>
            <p id="convert-queue-note" class="mt-1 text-xs text-amber-600 dark:text-amber-400 hidden">
                Waiting in queue — another document is currently processing (single worker, see CLAUDE.md).
            </p>
        </div>
        @elseif($document->status === 'failed')
        <div id="markdown-card" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl px-6 py-8 text-center">
            <i class="ti ti-alert-triangle text-2xl text-red-500 dark:text-red-400"></i>
            <p class="mt-2 text-sm font-medium text-red-600 dark:text-red-400">Conversion failed.</p>
            <p class="mt-1 text-xs text-red-500 dark:text-red-400">Use the "Retry Conversion" button above.</p>
        </div>
        @elseif(! $hasMarkdown)
        <div id="markdown-card" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-6 py-8 text-center">
            <i class="ti ti-markdown-off text-2xl text-slate-300 dark:text-slate-600"></i>
            <p class="mt-2 text-sm text-slate-400 dark:text-slate-500">Not yet converted to Markdown.</p>
        </div>
        @elseif(! $isVerified)
        {{-- Single consolidated banner — OCR is offered inside the Compare & Verify modal
             itself rather than as a second competing button here. --}}
        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl px-5 py-3.5 flex items-center justify-between gap-3 flex-wrap">
            <p class="text-sm text-amber-700 dark:text-amber-300 flex items-center gap-2">
                <i class="ti ti-clock text-base flex-shrink-0"></i>
                Converted, awaiting verification — compare against the original before accepting.
                @if($needsOcrReview)
                <span class="inline-flex items-center gap-1 text-orange-700 dark:text-orange-300 font-medium">
                    <i class="ti ti-alert-circle text-sm"></i> Text quality looks low — OCR option available inside.
                </span>
                @endif
                @if($document->metadata['structure_analyzed'] ?? false)
                <span class="inline-flex items-center gap-1 text-sky-700 dark:text-sky-300 font-medium">
                    <i class="ti ti-layout-2 text-sm"></i> Structure detected — view inside.
                </span>
                @endif
            </p>
            @auth @if($canManageDoc)
            <button type="button" id="open-compare-modal-btn"
                    class="inline-flex items-center gap-1.5 bg-amber-600 hover:bg-amber-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition-colors flex-shrink-0">
                <i class="ti ti-columns text-sm"></i> Compare &amp; Verify
            </button>
            @endif @endauth
        </div>
        @endif

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-wrap gap-2">
                <div class="inline-flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden text-xs font-medium flex-shrink-0">
                    <button type="button" id="view-tab-pdf"
                            class="px-3 py-1.5 flex items-center gap-1.5 bg-indigo-500 text-white">
                        <i class="ti ti-file-type-pdf text-sm"></i> PDF
                    </button>
                    {{-- Markdown tab only exists once verified — an unverified extraction isn't
                         something to casually browse to, the Compare & Verify banner above is
                         the only path to it until then. --}}
                    @if($hasMarkdown && $isVerified)
                    <button type="button" id="view-tab-md"
                            class="px-3 py-1.5 flex items-center gap-1.5 bg-white dark:bg-slate-800 text-slate-500 dark:text-slate-400">
                        <i class="ti ti-markdown text-sm"></i> Markdown
                    </button>
                    @endif
                </div>

                <div class="flex items-center gap-2">
                    @if($hasMarkdown && $extractionMethod && $isVerified)
                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded {{ $extractionMethod === 'ocr' ? 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300' : 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300' }}">
                        {{ $extractionMethod === 'ocr' ? 'OCR (hin+eng)' : 'Text layer' }}
                    </span>
                    @endif
                    <div id="md-toolbar" class="flex items-center gap-1" style="display:none">
                        <button type="button" id="md-copy-btn" title="Copy raw Markdown"
                                class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                            <i class="ti ti-copy text-sm"></i>
                        </button>
                        <button type="button" id="md-download-btn" title="Download .md"
                                class="p-1.5 rounded-lg text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-slate-100 dark:hover:bg-slate-700">
                            <i class="ti ti-download text-sm"></i>
                        </button>
                    </div>
                    <a href="{{ $pdfRoute }}" target="_blank" id="pdf-newtab-link"
                       class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1">
                        Open in new tab <i class="ti ti-external-link text-xs"></i>
                    </a>
                </div>
            </div>

            <div id="viewer-pdf">
                <iframe src="{{ $pdfRoute }}#view=FitH&toolbar=1&navpanes=0"
                        class="w-full border-0"
                        style="height: 75vh;"
                        title="{{ $document->title }}">
                </iframe>
            </div>

            @if($hasMarkdown && $isVerified)
            <div id="viewer-md" class="hidden overflow-y-auto" style="height: 75vh;">
                <div class="px-6 py-5 prose prose-sm dark:prose-invert max-w-none text-slate-700 dark:text-slate-300">
                    {!! $mdHtml !!}
                </div>
                <textarea id="md-raw-source" class="hidden">{{ $mdRaw ?? '' }}</textarea>
            </div>
            @endif
        </div>

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
                    <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">{{ $isPolicyDoc ? 'Policy' : ($isRuleSetDoc ? 'Rule Set' : ($isFolderDoc ? 'Folder' : 'Section')) }}</dt>
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
            @if($canManageDoc)
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
            <a href="{{ $linkForDoc($amendment) }}"
               class="flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity inline-flex items-center gap-1.5 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                View <i class="ti ti-arrow-right text-xs"></i>
            </a>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ── Compare & Verify modal — side-by-side original vs. extracted Markdown ──── --}}
@auth
@if($canManageDoc && $hasMarkdown && ! $isVerified)
<div id="compare-modal"
     style="display:none;position:fixed;inset:0;z-index:50;background:rgba(15,23,42,0.75)"
     onclick="if(event.target===this)document.getElementById('compare-modal').style.display='none'">
    <div style="position:absolute;inset:0"
         class="bg-slate-100 dark:bg-slate-950 shadow-2xl flex flex-col overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <div class="flex items-center gap-2">
                <i class="ti ti-columns text-amber-500 text-lg"></i>
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Compare &amp; Verify</span>
                <span class="text-xs text-slate-500 dark:text-slate-400">— {{ $document->title }}</span>
            </div>
            <button type="button" onclick="document.getElementById('compare-modal').style.display='none'"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 hover:text-slate-800 dark:hover:text-slate-100 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <i class="ti ti-x"></i>
            </button>
        </div>

        {{-- Structure Analysis (Docling) — shown first: confirms the document's layout/table
             shape before the user decides between accepting the Markdown or running OCR.
             Informational only this round, not yet merged into the rendered Markdown below.
             See STRUCTURE_RESEARCH.md. --}}
        @if($document->metadata['structure_analyzed'] ?? false)
        <div class="mx-2 mt-2 flex-shrink-0">
            <button type="button" id="structure-toggle-btn"
                    data-structure-url="{{ route('documents.structure', $document->id) }}"
                    class="w-full flex items-center justify-between gap-3 px-4 py-2 bg-sky-50 dark:bg-sky-900/20 border border-sky-200 dark:border-sky-800 rounded-lg text-xs hover:bg-sky-100 dark:hover:bg-sky-900/40 transition-colors">
                <span class="text-sky-700 dark:text-sky-300 flex items-center gap-1.5">
                    <i class="ti ti-layout-2 text-sm"></i>
                    Structure detected: {{ $document->metadata['structure_headings_count'] ?? 0 }} headings,
                    {{ $document->metadata['structure_tables_count'] ?? 0 }} tables
                    (Docling · {{ config('docling.ocr_engines.' . ($document->metadata['structure_engine'] ?? '') . '.label', $document->metadata['structure_engine'] ?? '?') }})
                </span>
                <span class="text-sky-600 dark:text-sky-400 font-medium flex items-center gap-1 flex-shrink-0">
                    View structure <i class="ti ti-chevron-down text-sm" id="structure-toggle-icon"></i>
                </span>
            </button>
            <div id="structure-panel" class="hidden mt-1 max-h-[32rem] overflow-y-auto bg-white dark:bg-slate-900 border border-sky-100 dark:border-sky-900/40 rounded-lg px-4 py-3 text-xs text-slate-700 dark:text-slate-300"></div>
        </div>
        @endif

        @if($needsOcrReview)
        <div class="mx-2 mt-2 px-4 py-2 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg flex-shrink-0 flex items-center gap-2">
            <i class="ti ti-alert-circle text-orange-500 dark:text-orange-400 text-sm flex-shrink-0"></i>
            <p class="text-xs text-orange-700 dark:text-orange-300">
                The text layer looks sparse or unreadable — possibly a scanned page, or a PDF using a non-Unicode font. If the Markdown below is missing or garbled, run OCR instead of editing it by hand.
            </p>
        </div>
        @endif

        <div id="compare-ocr-progress" class="mx-2 mt-2 px-4 py-2.5 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-lg flex-shrink-0 items-center gap-2" style="display:none">
            <i class="ti ti-loader-2 animate-spin text-indigo-500 dark:text-indigo-400 text-sm"></i>
            <span class="text-xs font-medium text-indigo-700 dark:text-indigo-300">Running OCR (Hindi + English)… elapsed <span id="compare-ocr-elapsed">0:00</span> — this page will reload automatically when done.</span>
        </div>

        <div class="flex flex-1 min-h-0 gap-2 p-2">
            <div class="w-1/2 flex flex-col min-h-0 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <div class="px-4 py-2 bg-red-50 dark:bg-red-900/20 border-b border-red-100 dark:border-red-900/40 flex-shrink-0 flex items-center gap-2">
                    <i class="ti ti-file-type-pdf text-sm text-red-500 dark:text-red-400"></i>
                    <span class="text-xs font-bold uppercase tracking-widest text-red-700 dark:text-red-400">Original</span>
                </div>
                <iframe id="compare-pdf-iframe" data-src="{{ $pdfRoute }}#view=FitH&toolbar=1&navpanes=0" class="w-full flex-1 border-0" title="Original PDF"></iframe>
            </div>
            <div class="w-1/2 flex flex-col min-h-0 bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
                <div class="px-4 py-2 bg-indigo-50 dark:bg-indigo-900/20 border-b border-indigo-100 dark:border-indigo-900/40 flex-shrink-0 flex items-center gap-2 flex-wrap">
                    <i class="ti ti-markdown text-sm text-indigo-500 dark:text-indigo-400"></i>
                    <span class="text-xs font-bold uppercase tracking-widest text-indigo-700 dark:text-indigo-400">Extracted Markdown</span>
                    <span class="text-[10px] text-indigo-500 dark:text-indigo-400 mr-auto">— edit to fix missing/incorrect text, then verify</span>
                    <div class="inline-flex rounded-lg border border-indigo-200 dark:border-indigo-800 overflow-hidden text-[11px] font-medium flex-shrink-0">
                        <button type="button" id="compare-tab-edit" class="px-2.5 py-1 bg-indigo-500 text-white">Edit</button>
                        <button type="button" id="compare-tab-preview" class="px-2.5 py-1 bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400">Preview</button>
                    </div>
                </div>
                <textarea id="compare-md-textarea"
                          class="flex-1 w-full px-4 py-3 text-xs font-mono bg-white dark:bg-slate-950 text-slate-800 dark:text-slate-100 border-0 resize-none focus:outline-none focus:ring-2 focus:ring-inset focus:ring-indigo-500"
                          spellcheck="false">{{ $mdRaw ?? '' }}</textarea>
                <div id="compare-md-preview" class="hidden flex-1 overflow-y-auto px-4 py-3 prose prose-sm dark:prose-invert max-w-none text-slate-700 dark:text-slate-300"></div>
            </div>
        </div>

        <div class="flex items-center gap-3 px-6 py-4 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 flex-shrink-0">
            <button type="button" id="compare-save-draft-btn"
                    class="inline-flex items-center gap-1.5 bg-indigo-50 dark:bg-indigo-900/30 border border-indigo-200 dark:border-indigo-800 hover:bg-indigo-100 dark:hover:bg-indigo-900/50 text-indigo-700 dark:text-indigo-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                <i class="ti ti-device-floppy text-base"></i> Save Draft
            </button>
            <button type="button" id="compare-save-verify-btn"
                    class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                <i class="ti ti-circle-check text-base"></i> Save &amp; Verify
            </button>
            <select id="compare-ocr-engine-select"
                    class="text-sm font-medium border border-orange-200 dark:border-orange-800 bg-orange-50 dark:bg-orange-900/20 text-orange-700 dark:text-orange-300 rounded-lg px-2 py-2 focus:outline-none focus:ring-2 focus:ring-orange-400 dark:[color-scheme:dark]">
                @foreach(config('ocr.engines') as $engineKey => $engineConfig)
                    <option value="{{ $engineKey }}" @selected($engineKey === config('ocr.default')) class="dark:bg-slate-800 dark:text-slate-100">{{ $engineConfig['label'] }}</option>
                @endforeach
            </select>
            <button type="button" id="compare-run-ocr-btn" data-convert-ocr-url="{{ route('documents.convert-ocr', $document->id) }}"
                    data-convert-status-url="{{ route('documents.convert-status', $document->id) }}"
                    class="inline-flex items-center gap-1.5 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 hover:bg-orange-100 dark:hover:bg-orange-900/40 text-orange-700 dark:text-orange-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                <i class="ti ti-scan text-base"></i> Run OCR Extraction
            </button>
            @if($canRevertOcr)
            <button type="button" id="compare-revert-ocr-btn" data-revert-ocr-url="{{ route('documents.revert-ocr', $document->id) }}"
                    class="inline-flex items-center gap-1.5 bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                <i class="ti ti-arrow-back-up text-base"></i> Revert to Text Extraction
            </button>
            @endif
            <button type="button" id="compare-discard-btn" data-discard-url="{{ route('documents.markdown.discard', $document->id) }}"
                    class="inline-flex items-center gap-1.5 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 hover:bg-red-100 dark:hover:bg-red-900/40 text-red-700 dark:text-red-300 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                <i class="ti ti-trash text-base"></i> Discard Draft
            </button>
            <span id="compare-status" class="text-xs text-slate-500 dark:text-slate-400"></span>
            <span class="flex-1"></span>
            <button type="button" onclick="document.getElementById('compare-modal').style.display='none'"
                    class="text-sm text-slate-600 dark:text-slate-300 hover:text-slate-900 dark:hover:text-white">
                Cancel
            </button>
        </div>
    </div>
</div>
@endif
@endauth

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/marked@13/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gridjs@6/dist/gridjs.umd.js"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/gridjs@6/dist/theme/mermaid.min.css">
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

try {
    const convertBtn = document.getElementById('convert-doc-btn');
    if (convertBtn) {
        convertBtn.addEventListener('click', function () {
            convertBtn.disabled = true;
            const label = document.getElementById('convert-doc-btn-label');
            const icon  = document.getElementById('convert-doc-btn-icon');
            if (label) label.textContent = 'Starting…';

            fetch(convertBtn.dataset.convertUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            }).then(function ({ ok, data }) {
                if (!ok) {
                    throw new Error(data.message || 'Conversion could not be started.');
                }
                // Morph the button in place into a progress state instead of removing it —
                // spinning the markdown-logo icon looked broken, so swap to a plain loader icon.
                if (icon) { icon.classList.remove('ti-markdown'); icon.classList.add('ti-loader-2', 'animate-spin'); }
                if (label) label.textContent = 'Converting…';
                const deleteBtn = document.getElementById('delete-doc-btn');
                if (deleteBtn) { deleteBtn.disabled = true; deleteBtn.title = 'Wait for conversion to finish'; }

                const card = document.getElementById('markdown-card');
                if (card) {
                    card.className = 'bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-xl px-6 py-8 text-center';
                    card.dataset.pollStatusUrl = convertBtn.dataset.convertStatusUrl;
                    card.innerHTML = '<i class="ti ti-loader-2 animate-spin text-2xl text-indigo-500 dark:text-indigo-400"></i>' +
                        '<p class="mt-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">Converting to Markdown…</p>' +
                        '<p class="mt-1 text-xs text-indigo-500 dark:text-indigo-400">Elapsed <span id="convert-elapsed">0:00</span> — OCR on scanned documents can take several minutes. This page updates automatically.</p>';
                    startConversionPolling(card);
                } else {
                    window.location.reload();
                }
            }).catch(function (err) {
                convertBtn.disabled = false;
                if (label) label.textContent = 'Convert to Markdown';
                Swal.fire({ icon: 'error', text: err.message || 'Conversion could not be started.' });
            });
        });
    }
} catch (e) {
    console.error('Convert button init failed:', e);
}

try {
    const tabPdf     = document.getElementById('view-tab-pdf');
    const tabMd      = document.getElementById('view-tab-md');
    const viewerPdf  = document.getElementById('viewer-pdf');
    const viewerMd   = document.getElementById('viewer-md');
    const mdToolbar  = document.getElementById('md-toolbar');
    const newTabLink = document.getElementById('pdf-newtab-link');
    const rawSource  = document.getElementById('md-raw-source');
    const activeClasses = ['bg-indigo-500', 'text-white'];
    const idleClasses = ['bg-white', 'dark:bg-slate-800', 'text-slate-500', 'dark:text-slate-400'];

    function showPdf() {
        viewerMd?.classList.add('hidden');
        viewerPdf.classList.remove('hidden');
        if (mdToolbar) mdToolbar.style.display = 'none';
        if (newTabLink) newTabLink.style.display = '';
        tabPdf.classList.add(...activeClasses);
        tabPdf.classList.remove(...idleClasses);
        tabMd?.classList.remove(...activeClasses);
        tabMd?.classList.add(...idleClasses);
    }

    function showMarkdown() {
        viewerPdf.classList.add('hidden');
        viewerMd.classList.remove('hidden');
        if (mdToolbar) mdToolbar.style.display = 'flex';
        if (newTabLink) newTabLink.style.display = 'none';
        tabMd.classList.add(...activeClasses);
        tabMd.classList.remove(...idleClasses);
        tabPdf.classList.remove(...activeClasses);
        tabPdf.classList.add(...idleClasses);
    }

    tabPdf?.addEventListener('click', showPdf);
    // The Markdown tab only exists in the DOM once the document is verified (see Blade
    // condition above), so no unverified-state branching is needed here.
    tabMd?.addEventListener('click', showMarkdown);

    const copyBtn = document.getElementById('md-copy-btn');
    if (copyBtn && rawSource) {
        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(rawSource.value).then(function () {
                const icon = copyBtn.querySelector('i');
                icon.classList.remove('ti-copy');
                icon.classList.add('ti-check');
                setTimeout(function () {
                    icon.classList.remove('ti-check');
                    icon.classList.add('ti-copy');
                }, 1500);
            });
        });
    }

    const downloadBtn = document.getElementById('md-download-btn');
    if (downloadBtn && rawSource) {
        downloadBtn.addEventListener('click', function () {
            const blob = new Blob([rawSource.value], { type: 'text/markdown' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = '{{ Str::slug($document->title) }}.md';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
    }
} catch (e) {
    console.error('Markdown viewer init failed:', e);
}

try {
    const openBtn = document.getElementById('open-compare-modal-btn');
    const modal = document.getElementById('compare-modal');
    const compareIframe = document.getElementById('compare-pdf-iframe');
    if (openBtn && modal) {
        openBtn.addEventListener('click', function () {
            modal.style.display = 'block';
            // Loading the PDF while the modal is display:none gives the built-in PDF
            // viewer a 0x0 viewport, so #view=FitH never applies. Load it only once
            // the modal (and iframe) actually has real layout dimensions.
            if (compareIframe && !compareIframe.src) {
                compareIframe.src = compareIframe.dataset.src;
            }
        });
    }

    // Structure (Docling) panel — fetched and rendered as a real heading outline + HTML
    // tables on first expand, not a raw-JSON dump, so a reviewer can actually judge at a
    // glance whether the detected structure looks complete before deciding OCR vs. accept.
    const structureBtn   = document.getElementById('structure-toggle-btn');
    const structurePanel = document.getElementById('structure-panel');
    const structureIcon  = document.getElementById('structure-toggle-icon');

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        }[c]));
    }

    // Flattens Docling's row/col-span cell list into a plain rows×cols grid of strings — the
    // simplest shape Grid.js (and any other tabular renderer) understands. Spanned cells repeat
    // the anchor's text rather than staying blank, since Grid.js has no merged-cell concept.
    function tableToGrid(table) {
        const rows = table.num_rows ?? 0;
        const cols = table.num_cols ?? 0;
        const grid = Array.from({ length: rows }, () => Array(cols).fill(''));

        (table.cells ?? []).forEach((cell) => {
            const r = cell.row ?? 0;
            const c = cell.col ?? 0;
            if (r < 0 || c < 0 || r >= rows || c >= cols) return;
            const text = (cell.text ?? '').trim();
            for (let i = r; i < Math.min(rows, r + (cell.row_span || 1)); i++) {
                for (let j = c; j < Math.min(cols, c + (cell.col_span || 1)); j++) {
                    grid[i][j] = text;
                }
            }
        });

        return grid;
    }

    // Renders directly into the DOM (rather than building one big innerHTML string) because
    // Grid.js instantiates against a real container element per table.
    function renderStructure(panel, data) {
        panel.innerHTML = '';

        if (!data.headings?.length && !data.tables?.length) {
            panel.innerHTML = '<p class="text-slate-400 dark:text-slate-500">No structure detected.</p>';
            return;
        }

        if (data.headings?.length) {
            const headingsHtml = `<div class="font-semibold text-slate-800 dark:text-slate-100 mb-1">Headings (${data.headings.length})</div>` +
                '<ul class="mb-4 space-y-0.5">' + data.headings.map((h) =>
                    `<li><span class="text-slate-400 dark:text-slate-500">p.${h.page}</span> ${escapeHtml(h.text)}</li>`
                ).join('') + '</ul>';
            panel.insertAdjacentHTML('beforeend', headingsHtml);
        }

        if (data.tables?.length) {
            panel.insertAdjacentHTML('beforeend', `<div class="font-semibold text-slate-800 dark:text-slate-100 mb-1">Tables (${data.tables.length})</div>`);

            data.tables.forEach((t, i) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'mb-4';
                wrapper.innerHTML = `<div class="text-slate-400 dark:text-slate-500 mb-1">Table ${i + 1} — p.${t.page}, ${t.num_rows}×${t.num_cols}</div>`;
                const gridContainer = document.createElement('div');
                wrapper.appendChild(gridContainer);
                panel.appendChild(wrapper);

                const grid = tableToGrid(t);
                if (window.gridjs && grid.length) {
                    new gridjs.Grid({
                        columns: grid[0].map((_, ci) => `Col ${ci + 1}`),
                        data: grid.slice(1),
                        search: true,
                        sort: true,
                        pagination: { limit: 8 },
                        className: { table: 'text-[11px]' },
                    }).render(gridContainer);
                } else {
                    gridContainer.innerHTML = '<p class="text-red-500">Table renderer failed to load.</p>';
                }
            });
        }
    }

    if (structureBtn && structurePanel && structureIcon) {
        structureBtn.addEventListener('click', function () {
            const expanded = !structurePanel.classList.contains('hidden');
            if (expanded) {
                structurePanel.classList.add('hidden');
                structureIcon.classList.remove('ti-chevron-up');
                structureIcon.classList.add('ti-chevron-down');
                return;
            }

            structurePanel.classList.remove('hidden');
            structureIcon.classList.remove('ti-chevron-down');
            structureIcon.classList.add('ti-chevron-up');

            if (structurePanel.dataset.loaded) return;
            structurePanel.innerHTML = '<p class="text-slate-400 dark:text-slate-500">Loading…</p>';

            fetch(structureBtn.dataset.structureUrl)
                .then((r) => r.json())
                .then((data) => {
                    renderStructure(structurePanel, data);
                    structurePanel.dataset.loaded = '1';
                })
                .catch(() => {
                    structurePanel.innerHTML = '<p class="text-red-500">Could not load structure data.</p>';
                });
        });
    }

    const textarea   = document.getElementById('compare-md-textarea');
    const previewEl  = document.getElementById('compare-md-preview');
    const editTab    = document.getElementById('compare-tab-edit');
    const previewTab = document.getElementById('compare-tab-preview');
    const activeTabClasses = ['bg-indigo-500', 'text-white'];
    const idleTabClasses   = ['bg-white', 'dark:bg-slate-900', 'text-indigo-600', 'dark:text-indigo-400'];

    // ponytail: same javascript:/data:/vbscript: href/src strip used server-side for the
    // Parsedown-rendered view (show.blade.php:254) — this preview is admin-only and never
    // persisted, but stripped for consistency rather than trusting marked's own escaping.
    function sanitizeHtml(html) {
        return html.replace(/\b(href|src)\s*=\s*(["'])(?:javascript|data|vbscript):[^"']*\2/gi, '$1=$2#$2');
    }

    if (textarea && previewEl && editTab && previewTab && window.marked) {
        previewTab.addEventListener('click', function () {
            previewEl.innerHTML = sanitizeHtml(marked.parse(textarea.value));
            textarea.classList.add('hidden');
            previewEl.classList.remove('hidden');
            previewTab.classList.add(...activeTabClasses);
            previewTab.classList.remove(...idleTabClasses);
            editTab.classList.add(...idleTabClasses);
            editTab.classList.remove(...activeTabClasses);
        });
        editTab.addEventListener('click', function () {
            textarea.classList.remove('hidden');
            previewEl.classList.add('hidden');
            editTab.classList.add(...activeTabClasses);
            editTab.classList.remove(...idleTabClasses);
            previewTab.classList.add(...idleTabClasses);
            previewTab.classList.remove(...activeTabClasses);
        });
    }

    const statusEl   = document.getElementById('compare-status');
    const draftBtn   = document.getElementById('compare-save-draft-btn');
    const verifyBtn  = document.getElementById('compare-save-verify-btn');
    const markdownUpdateUrl = '{{ route("documents.markdown.update", $document->id) }}';

    function saveCompare(verify, triggerBtn) {
        if (!textarea) return;
        draftBtn.disabled = true;
        verifyBtn.disabled = true;
        statusEl.textContent = verify ? 'Verifying…' : 'Saving…';

        fetch(markdownUpdateUrl, {
            method: 'PATCH',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ content: textarea.value, verify: verify }),
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        }).then(function ({ ok, data }) {
            if (!ok) throw new Error(data.message || 'Failed to save.');
            statusEl.textContent = verify ? 'Verified.' : 'Saved.';
            window.location.reload();
        }).catch(function (err) {
            draftBtn.disabled = false;
            verifyBtn.disabled = false;
            statusEl.textContent = '';
            Swal.fire({ icon: 'error', text: err.message || 'Failed to save changes.' });
        });
    }

    draftBtn?.addEventListener('click', function () { saveCompare(false); });
    verifyBtn?.addEventListener('click', function () { saveCompare(true); });

    const discardBtn = document.getElementById('compare-discard-btn');
    const isDark = document.documentElement.classList.contains('dark');
    discardBtn?.addEventListener('click', function () {
        Swal.fire({
            icon: 'warning',
            title: 'Discard this Markdown draft?',
            text: 'This deletes the extracted Markdown and resets the document so it can be re-converted from scratch. This cannot be undone.',
            showCancelButton: true,
            confirmButtonText: 'Discard',
            confirmButtonColor: '#dc2626',
            background: isDark ? '#0f172a' : '#fff',
            color: isDark ? '#e2e8f0' : '#0f172a',
        }).then(function (result) {
            if (!result.isConfirmed) return;

            draftBtn.disabled = true;
            verifyBtn.disabled = true;
            discardBtn.disabled = true;
            statusEl.textContent = 'Discarding…';

            fetch(discardBtn.dataset.discardUrl, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then(function (res) {
                return res.json().then(function (data) { return { ok: res.ok, data: data }; });
            }).then(function ({ ok, data }) {
                if (!ok) throw new Error(data.message || 'Failed to discard draft.');
                window.location.reload();
            }).catch(function (err) {
                draftBtn.disabled = false;
                verifyBtn.disabled = false;
                discardBtn.disabled = false;
                statusEl.textContent = '';
                Swal.fire({ icon: 'error', text: err.message || 'Failed to discard draft.' });
            });
        });
    });

    const ocrBtn = document.getElementById('compare-run-ocr-btn');
    const ocrEngineSelect = document.getElementById('compare-ocr-engine-select');
    const ocrProgress = document.getElementById('compare-ocr-progress');
    ocrBtn?.addEventListener('click', function () {
        ocrBtn.disabled = true;
        draftBtn.disabled = true;
        verifyBtn.disabled = true;
        discardBtn.disabled = true;

        fetch(ocrBtn.dataset.convertOcrUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ engine: ocrEngineSelect?.value }),
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        }).then(function ({ ok, data }) {
            if (!ok) throw new Error(data.message || 'OCR extraction could not be started.');
            if (ocrProgress) {
                ocrProgress.style.display = 'flex';
                startConversionPolling({ dataset: { pollStatusUrl: ocrBtn.dataset.convertStatusUrl } }, 'compare-ocr-elapsed');
            }
        }).catch(function (err) {
            ocrBtn.disabled = false;
            draftBtn.disabled = false;
            verifyBtn.disabled = false;
            discardBtn.disabled = false;
            Swal.fire({ icon: 'error', text: err.message || 'OCR extraction could not be started.' });
        });
    });

    const revertOcrBtn = document.getElementById('compare-revert-ocr-btn');
    revertOcrBtn?.addEventListener('click', function () {
        revertOcrBtn.disabled = true;
        draftBtn.disabled = true;
        verifyBtn.disabled = true;
        discardBtn.disabled = true;

        fetch(revertOcrBtn.dataset.revertOcrUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '{{ csrf_token() }}',
                'X-Requested-With': 'XMLHttpRequest',
            },
        }).then(function (res) {
            return res.json().then(function (data) { return { ok: res.ok, data: data }; });
        }).then(function ({ ok, data }) {
            if (!ok) throw new Error(data.message || 'Could not revert to the text-layer extraction.');
            window.location.reload();
        }).catch(function (err) {
            revertOcrBtn.disabled = false;
            draftBtn.disabled = false;
            verifyBtn.disabled = false;
            discardBtn.disabled = false;
            Swal.fire({ icon: 'error', text: err.message || 'Could not revert to the text-layer extraction.' });
        });
    });
} catch (e) {
    console.error('Compare & Verify modal init failed:', e);
}

function startConversionPolling(card, elapsedElId) {
    const pollUrl = card?.dataset.pollStatusUrl;
    if (!pollUrl) return;

    const startedAt = Date.now();
    const elapsedTimer = setInterval(function () {
        const el = document.getElementById(elapsedElId || 'convert-elapsed');
        if (!el) { clearInterval(elapsedTimer); return; }
        const secs = Math.floor((Date.now() - startedAt) / 1000);
        el.textContent = Math.floor(secs / 60) + ':' + String(secs % 60).padStart(2, '0');
    }, 1000);

    const pollInterval = setInterval(function () {
        fetch(pollUrl, { headers: { Accept: 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status !== 'processing' && data.status !== 'ocr_pending') {
                    clearInterval(pollInterval);
                    clearInterval(elapsedTimer);
                    window.location.reload();
                    return;
                }
                const queueNote = document.getElementById('convert-queue-note');
                if (queueNote) queueNote.classList.toggle('hidden', !data.queued_behind_other_job);
            })
            .catch(function () { clearInterval(pollInterval); clearInterval(elapsedTimer); });
    }, 3000);
}

try {
    const pollCard = document.getElementById('markdown-card');
    if (pollCard && pollCard.dataset.pollStatusUrl) {
        startConversionPolling(pollCard);
    }
} catch (e) {
    console.error('Conversion status polling init failed:', e);
}
</script>
@endpush

</x-layout>

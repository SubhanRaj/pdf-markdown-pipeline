<x-layout
    :title="$section->name"
    :page-title="$section->name"
    :page-subtitle="$department->name . ($section->wing ? ' · ' . str_replace('_', ' ', ucfirst($section->wing)) : '')"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                       'url' => route('home')],
    ['name' => 'Departments',                'url' => route('departments.index')],
    ['name' => $department->levelLabel(),    'url' => null],
    ['name' => $department->name,            'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $section->name,               'url' => null],
]" />

{{-- Data island for JS — use @json() not {{ json_encode() }} to avoid HTML-entity corruption --}}
@php $pageData = [
    'storeUrl' => route('documents.store'),
    'storeChunkUrl' => route('documents.store-chunk'),
    'csrfToken' => csrf_token(),
    'csrfTokenUrl' => route('documents.csrf-token'),
    'parentOptions' => $parentOptions,
    'currentSectionId' => $section->id,
    'folderStoreUrl' => route('departments.sections.folders.store', [$department->levelAlias(), $department, $section]),
]; @endphp
<script id="page-data" type="application/json">@json($pageData)</script>
<script src="{{ asset('js/resilient-upload.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>

{{-- ── Section header ─────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-6 flex-wrap">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-indigo-500/10 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-folder-open text-indigo-500 dark:text-indigo-400 text-xl"></i>
        </div>
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $section->name }}</h2>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <a href="{{ route('departments.show', [$department->levelAlias(), $department]) }}" class="text-xs text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">{{ $department->name }}</a>
                @if($section->wing)
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <a href="{{ route('departments.show', [$department->levelAlias(), $department]) }}" class="text-xs text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">{{ Str::title(str_replace('_', ' ', $section->wing)) }}</a>
                @endif
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $documents->total() }} {{ Str::plural('document', $documents->total()) }}</span>
            </div>
            {{-- Vault path — formatted with readable names --}}
            @auth
            <div class="flex items-center gap-1 mt-1 flex-wrap">
                @php
                    $vaultCrumbs = array_filter([
                        'Vault',
                        Str::title(str_replace('_', ' ', $department->level)),
                        $department->name,
                        $section->wing ? Str::title(str_replace('_', ' ', $section->wing)) : null,
                        $section->name,
                    ]);
                @endphp
                @foreach($vaultCrumbs as $crumb)
                    @if(!$loop->first)<span class="text-[10px] text-slate-300 dark:text-slate-700">/</span>@endif
                    <span class="text-[10px] font-mono text-slate-300 dark:text-slate-600">{{ $crumb }}</span>
                @endforeach
            </div>
            @endauth
        </div>
    </div>
    <div class="flex items-center gap-2 flex-wrap justify-end w-full sm:w-auto">
        <a href="{{ route('departments.sections.download', [$department->levelAlias(), $department, $section]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-emerald-400 dark:hover:border-emerald-500 text-slate-600 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 text-sm font-medium px-3 py-2 rounded-lg transition-all"
           title="Download all markdown in this section as ZIP">
            <i class="ti ti-file-zip text-base"></i>
            <span class="hidden sm:inline">Download ZIP</span>
        </a>
        @auth
        @if(auth()->user()->canUploadTo($section))
        <button id="btn-toggle-upload" type="button"
                onclick="document.getElementById('upload-modal').style.display='block'"
                class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-upload text-base"></i>
            <span class="hidden sm:inline">Upload PDF</span>
        </button>
        @endif
        @endauth
        @auth @if(auth()->user()->isAdmin() || (auth()->user()->hasPrivilege('section.head') && auth()->user()->section_id === $section->id) || (auth()->user()->hasPrivilege('department.head') && auth()->user()->department_id === $section->department_id))
        <a href="{{ route('departments.sections.divisions.create', [$department->levelAlias(), $department, $section]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-teal-400 dark:hover:border-teal-500 text-slate-600 dark:text-slate-300 hover:text-teal-600 dark:hover:text-teal-400 text-sm font-medium px-3 py-2 rounded-lg transition-all"
           title="Add Internal Division">
            <i class="ti ti-layout-sidebar-right-expand text-base"></i>
            <span class="hidden sm:inline">Add Division</span>
        </a>
        @endif @endauth
        @auth
        @if(auth()->user()->canUploadTo($section))
        <a href="{{ route('departments.sections.folders.create', [$department->levelAlias(), $department, $section]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-cyan-400 dark:hover:border-cyan-500 text-slate-600 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 text-sm font-medium px-3 py-2 rounded-lg transition-all"
           title="Add Folder">
            <i class="ti ti-folder-plus text-base"></i>
            <span class="hidden sm:inline">Add Folder</span>
        </a>
        @endif
        @endauth
        @auth @if(auth()->user()->isAdmin() || (auth()->user()->hasPrivilege('section.head') && auth()->user()->section_id === $section->id) || (auth()->user()->hasPrivilege('department.head') && auth()->user()->department_id === $section->department_id))
        <a href="{{ route('departments.sections.edit', [$department->levelAlias(), $department, $section]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-pencil text-base"></i>
        </a>
        @endif @endauth
    </div>
</div>

{{-- ── Upload modal (auth only) ─────────────────────────────────────────────── --}}
@auth
<div id="upload-modal"
     style="display:none;position:fixed;inset:0;z-index:50;background:rgba(0,0,0,0.6)"
     onclick="if(event.target===this)window.__closeUploadModal()">

    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(960px,95vw);max-height:90vh;overflow-y:auto"
         class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col">

        {{-- Modal header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <div class="flex items-center gap-2">
                <i class="ti ti-file-upload text-indigo-500 text-lg"></i>
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Upload Document</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $section->name }}</span>
            </div>
            <button type="button" onclick="window.__closeUploadModal()"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <i class="ti ti-x"></i>
            </button>
        </div>

        {{-- Modal body: two-column on large, stacked on small --}}
        <div class="flex flex-col lg:flex-row flex-1 min-h-0">

            {{-- Left: file drop + queue --}}
            <div class="lg:w-1/2 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-700 flex flex-col p-4 gap-3">
                {{-- Drop zone --}}
                <div id="drop-zone"
                     onclick="document.getElementById('doc-file').click()"
                     style="cursor:pointer"
                     class="rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors flex flex-col items-center justify-center gap-1.5 py-5 px-4 text-center flex-shrink-0">
                    <i class="ti ti-cloud-upload text-2xl text-slate-300 dark:text-slate-600"></i>
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag files here</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 300 MB each · multiple files supported</p>
                    <input type="file" id="doc-file" name="file" multiple
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif,.svg"
                           style="display:none">
                </div>
                <button type="button" id="doc-folder-btn" onclick="document.getElementById('doc-folder-input').click()"
                        class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center justify-center gap-1 flex-shrink-0">
                    <i class="ti ti-folder-up"></i> Or upload a whole folder
                </button>
                <input type="file" id="doc-folder-input" webkitdirectory directory multiple style="display:none">
                <p class="text-[11px] text-slate-400 dark:text-slate-500 text-center -mt-1">The picked folder is created here (reusing one with the same name if it exists). Everything inside it, at any depth, goes into it.</p>
                {{-- File queue (shown when files are selected) --}}
                <div id="file-queue-wrap" class="flex-1 overflow-hidden flex flex-col min-h-0" style="display:none">
                    <div class="flex items-center justify-between mb-1.5 flex-shrink-0">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                            Queue &nbsp;<span id="queue-count" class="text-indigo-500 font-bold normal-case">0</span>
                        </p>
                        <button type="button" id="btn-clear-queue"
                                class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">Clear all</button>
                    </div>
                    <div id="file-queue" class="overflow-y-auto flex flex-col gap-1.5" style="max-height:240px"></div>
                </div>
                <p id="queue-empty-hint" class="text-xs text-slate-400 dark:text-slate-500 text-center py-1">Select one or more files above — each gets its own title</p>
            </div>

            {{-- Right: form fields --}}
            <div class="lg:w-1/2 p-6 flex flex-col gap-4">

                <form id="upload-form" method="POST" action="{{ route('documents.store') }}"
                      novalidate enctype="multipart/form-data" class="flex flex-col gap-4 flex-1">
                    @csrf
                    <input type="hidden" name="section_id" value="{{ $section->id }}">

                    {{-- Document type --}}
                    <div>
                        <label for="doc-type" class="field-label">Document Type <span class="text-red-500">*</span></label>
                        <select id="doc-type" name="document_type" class="field-input">
                            <option value="">— Select type —</option>
                            @foreach(\App\Models\Document::DOCUMENT_TYPES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p id="err-type" class="field-err-msg" style="display:none"></p>
                    </div>

                    {{-- Title --}}
                    <div>
                        <label for="doc-title" class="field-label">Title <span class="text-slate-400 font-normal">(optional — auto-filled from filename)</span></label>
                        <input type="text" id="doc-title" class="field-input" placeholder="Auto-filled from filename" maxlength="255">
                        <p id="doc-title-hint" class="text-xs text-slate-400 dark:text-slate-500 mt-1" style="display:none">Multiple files queued — edit each one's title in the queue on the left.</p>
                    </div>

                    {{-- Visibility --}}
                    <div>
                        <label class="field-label">Visibility</label>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="public" id="doc-visibility-public" @checked($section->visibility !== 'authenticated')
                                       class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1">
                                    <i class="ti ti-world text-sm text-green-500"></i> Public
                                </span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="authenticated" @checked($section->visibility === 'authenticated')
                                       class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1">
                                    <i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only
                                </span>
                            </label>
                        </div>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Public documents are visible to all visitors. Authenticated Only restricts access to logged-in users.</p>
                        @if($section->visibility === 'authenticated')
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1">
                            <i class="ti ti-alert-triangle text-sm"></i> This section is Authenticated Only, so documents default to the same. Marking one Public here has no real effect — guests still can't reach it while the section itself is restricted.
                        </p>
                        @endif
                    </div>

                    {{-- Language --}}
                    <div>
                        <label for="doc-language" class="field-label">Language</label>
                        <select id="doc-language" name="language" class="field-input">
                            @foreach(\App\Models\Document::LANGUAGES as $key => $label)
                            <option value="{{ $key }}" @selected($key === 'english')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Amends (optional parent link) --}}
                    <div>
                        <label for="doc-parent" class="field-label">Amends Previous Document <span class="text-slate-400 font-normal">(optional)</span></label>
                        <select id="doc-parent" name="parent_id" class="field-input">
                            <option value="">— None —</option>
                        </select>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Select if this document formally amends an earlier document in this section.</p>
                    </div>

                    {{-- Amendment number + effective date --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="sec-amendment-number" class="field-label">Amendment No. <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="sec-amendment-number" name="amendment_number" min="1" max="999"
                                   placeholder="e.g. 5" class="field-input">
                        </div>
                        <div>
                            <label for="sec-effective-year" class="field-label">Effective Year <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="sec-effective-year" name="effective_year" min="1900" max="2099"
                                   placeholder="e.g. 2019" class="field-input">
                        </div>
                        <div>
                            <label for="sec-effective-month" class="field-label">Month <span class="text-slate-400 font-normal">(optional)</span></label>
                            <select id="sec-effective-month" name="effective_month" class="field-input">
                                <option value="">—</option>
                                @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                                <option value="{{ $mi + 1 }}">{{ $mn }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="sec-effective-day" class="field-label">Day <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="sec-effective-day" name="effective_day" min="1" max="31"
                                   placeholder="1–31" class="field-input">
                        </div>
                    </div>

                    {{-- Vault destination --}}
                    <div class="bg-slate-50 dark:bg-slate-800/60 rounded-lg px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Saving to</p>
                        <p class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">
                            @php
                                $vaultCrumbs = array_filter([
                                    Str::title(str_replace('_', ' ', $department->level)),
                                    $department->name,
                                    $section->wing ? Str::title(str_replace('_', ' ', $section->wing)) : null,
                                    $section->name,
                                ]);
                            @endphp
                            {{ implode(' › ', $vaultCrumbs) }}
                        </p>
                    </div>

                    {{-- Submit --}}
                    <div class="flex flex-col gap-2 mt-auto pt-2">
                        <div class="flex items-center gap-3">
                            <button type="submit" id="btn-submit"
                                    class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                                <i class="ti ti-upload"></i>
                                <span id="btn-submit-label">Upload</span>
                            </button>
                            <span id="upload-status" class="text-xs text-slate-400 dark:text-slate-500"></span>
                        </div>
                        <div id="upload-progress-track" style="display:none" class="h-1.5 w-full rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                            <div id="upload-progress-bar" class="h-full bg-indigo-500 transition-all" style="width:0%"></div>
                        </div>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
@endauth

{{-- ── Internal Divisions ──────────────────────────────────────────────────── --}}
@if($divisions->isNotEmpty())
<div class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2">
            <i class="ti ti-layout-sidebar text-teal-500 dark:text-teal-400"></i>
            Internal Divisions
            <span class="text-xs font-normal text-slate-400 dark:text-slate-500 normal-case">({{ $divisions->count() }})</span>
        </h3>
        @auth @if(auth()->user()->isAdmin() || (auth()->user()->hasPrivilege('section.head') && auth()->user()->section_id === $section->id) || (auth()->user()->hasPrivilege('department.head') && auth()->user()->department_id === $section->department_id))
        <a href="{{ route('departments.sections.divisions.create', [$department->levelAlias(), $department, $section]) }}"
           class="inline-flex items-center gap-1 text-xs text-teal-600 dark:text-teal-400 hover:underline">
            <i class="ti ti-plus text-xs"></i> Add Division
        </a>
        @endif @endauth
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        @foreach($divisions as $div)
        <div class="group relative flex items-start gap-3 p-4 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-teal-400 dark:hover:border-teal-500 transition-all">
            <div class="w-9 h-9 rounded-lg bg-teal-500/10 dark:bg-teal-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-layout-sidebar text-teal-500 dark:text-teal-400 text-base"></i>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 group-hover:text-teal-600 dark:group-hover:text-teal-400 transition-colors truncate">{{ $div->name }}</p>
                @if($div->description)
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 line-clamp-1">{{ $div->description }}</p>
                @endif
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                    {{ $div->documents_count }} {{ Str::plural('document', $div->documents_count) }}
                </p>
            </div>
            <a href="{{ route('departments.sections.divisions.download', [$department->levelAlias(), $department, $section, $div]) }}"
               class="relative z-10 text-slate-400 dark:text-slate-500 hover:text-teal-500 dark:hover:text-teal-400 transition-colors flex-shrink-0 mt-1"
               title="Download as ZIP">
                <i class="ti ti-file-zip text-base"></i>
            </a>
            <i class="ti ti-chevron-right text-slate-300 dark:text-slate-600 group-hover:text-teal-400 transition-colors flex-shrink-0 mt-1 text-sm"></i>
            <a href="{{ route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $div]) }}"
               class="absolute inset-0" aria-label="Open {{ $div->name }}"></a>
        </div>
        @endforeach
    </div>
</div>
@else
@auth @if(auth()->user()->isAdmin() || (auth()->user()->hasPrivilege('section.head') && auth()->user()->section_id === $section->id) || (auth()->user()->hasPrivilege('department.head') && auth()->user()->department_id === $section->department_id))
<div class="mb-6 flex items-center justify-between px-4 py-3 rounded-xl border border-dashed border-slate-200 dark:border-slate-700 text-slate-400 dark:text-slate-500">
    <span class="text-xs flex items-center gap-2"><i class="ti ti-layout-sidebar"></i> No internal divisions yet</span>
    <a href="{{ route('departments.sections.divisions.create', [$department->levelAlias(), $department, $section]) }}"
       class="inline-flex items-center gap-1 text-xs text-teal-600 dark:text-teal-400 hover:underline">
        <i class="ti ti-plus text-xs"></i> Add Division
    </a>
</div>
@endif @endauth
@endif

{{-- ── Folders (Patravali) ──────────────────────────────────────────────────── --}}
@if($folders->isNotEmpty())
<div class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2">
            <i class="ti ti-folder-star text-cyan-500 dark:text-cyan-400"></i>
            Folders
            <span class="text-xs font-normal text-slate-400 dark:text-slate-500 normal-case">({{ $folders->count() }})</span>
        </h3>
        @auth @if(auth()->user()->canUploadTo($section))
        <a href="{{ route('departments.sections.folders.create', [$department->levelAlias(), $department, $section]) }}"
           class="inline-flex items-center gap-1 text-xs text-cyan-600 dark:text-cyan-400 hover:underline">
            <i class="ti ti-plus text-xs"></i> Add Folder
        </a>
        @endif @endauth
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        @foreach($folders as $fld)
        <div class="group relative flex items-start gap-3 p-4 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-cyan-400 dark:hover:border-cyan-500 transition-all">
            <div class="w-9 h-9 rounded-lg bg-cyan-500/10 dark:bg-cyan-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-folder-star text-cyan-500 dark:text-cyan-400 text-base"></i>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-1.5">
                    <p class="text-sm font-medium text-slate-800 dark:text-slate-100 group-hover:text-cyan-600 dark:group-hover:text-cyan-400 transition-colors truncate">{{ $fld->name }}</p>
                    @if($fld->visibility === 'authenticated')
                    <i class="ti ti-lock text-amber-500 text-xs flex-shrink-0" title="Authenticated Only"></i>
                    @endif
                </div>
                @if($fld->description)
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 line-clamp-1">{{ $fld->description }}</p>
                @endif
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">
                    {{ $fld->documents_count }} {{ Str::plural('document', $fld->documents_count) }}
                </p>
            </div>
            <a href="{{ route('departments.sections.folders.download', [$department->levelAlias(), $department, $section, $fld]) }}"
               class="relative z-10 text-slate-400 dark:text-slate-500 hover:text-cyan-500 dark:hover:text-cyan-400 transition-colors flex-shrink-0 mt-1"
               title="Download as ZIP">
                <i class="ti ti-file-zip text-base"></i>
            </a>
            <i class="ti ti-chevron-right text-slate-300 dark:text-slate-600 group-hover:text-cyan-400 transition-colors flex-shrink-0 mt-1 text-sm"></i>
            <a href="{{ route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $fld]) }}"
               class="absolute inset-0" aria-label="Open {{ $fld->name }}"></a>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ── Direct Documents ─────────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Direct Documents</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                {{ $documents->total() }} {{ Str::plural('document', $documents->total()) }} · not under any division
                @guest · public only @endguest
                @if($filterYear) · filtered to {{ $filterYear }} @endif
            </p>
        </div>
        @if($documents->total() > 1 || $filterYear)
        <form method="GET" action="{{ route('departments.sections.show', [$department->levelAlias(), $department, $section]) }}"
              class="flex items-center gap-2 flex-wrap">
            <select name="sort" onchange="this.form.submit()"
                    class="text-xs border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="uploaded_desc" @selected($sort === 'uploaded_desc')>Uploaded ↓ newest first</option>
                <option value="uploaded_asc"  @selected($sort === 'uploaded_asc')>Uploaded ↑ oldest first</option>
                <option value="year_desc"     @selected($sort === 'year_desc')>Effective Year ↓</option>
                <option value="year_asc"      @selected($sort === 'year_asc')>Effective Year ↑</option>
            </select>
            @if($availableYears->isNotEmpty())
            <select name="year" onchange="this.form.submit()"
                    class="text-xs border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">All years</option>
                @foreach($availableYears as $yr)
                <option value="{{ $yr }}" @selected($filterYear == $yr)>{{ $yr }}</option>
                @endforeach
            </select>
            @endif
            @if($filterYear)
            <a href="{{ route('departments.sections.show', [$department->levelAlias(), $department, $section]) }}"
               class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors" title="Clear filter">
                <i class="ti ti-x"></i>
            </a>
            @endif
        </form>
        @endif
    </div>

    @if($documents->isEmpty())
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-files text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No documents yet</p>
        @auth
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Use the Upload button above to add the first PDF.</p>
        @else
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Verified documents will appear here.</p>
        @endauth
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($documents as $doc)
        <x-document-row
            :doc="$doc"
            :url="route('documents.show', [$doc->department->levelAlias(), $doc->department, $doc->section, $doc])"
            :destroy-url="auth()->check() && auth()->user()->isAdmin() ? route('documents.destroy', [$doc->department->levelAlias(), $doc->department, $doc->section, $doc]) : null"
        />
        @endforeach
    </div>

    @if($documents->hasPages())
    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-700">
        {{ $documents->links() }}
    </div>
    @endif
    @endif
</div>

@push('scripts')
<script>
(function () {
    const modal = document.getElementById('upload-modal');
    if (!modal) return;

    let page;
    try {
        page = JSON.parse(document.getElementById('page-data').textContent);
    } catch (e) { console.error('page-data JSON parse failed', e); return; }

    const form         = document.getElementById('upload-form');
    const fileInput    = document.getElementById('doc-file');
    const dropZone     = document.getElementById('drop-zone');
    const typeSelect   = document.getElementById('doc-type');
    const parentSelect = document.getElementById('doc-parent');
    const btnSubmit    = document.getElementById('btn-submit');
    const btnLabel     = document.getElementById('btn-submit-label');
    const statusEl     = document.getElementById('upload-status');
    const queueWrap    = document.getElementById('file-queue-wrap');
    const queueList    = document.getElementById('file-queue');
    const queueCountEl = document.getElementById('queue-count');
    const queueHint    = document.getElementById('queue-empty-hint');
    const btnClear     = document.getElementById('btn-clear-queue');
    const fldTitle     = document.getElementById('doc-title');
    const fldTitleHint = document.getElementById('doc-title-hint');
    const progressTrack = document.getElementById('upload-progress-track');
    const progressBar   = document.getElementById('upload-progress-bar');

    // Persists the picked queue in IndexedDB so a reload/crash mid-batch doesn't force
    // re-picking all the files — see public/js/resilient-upload.js.
    const queue = new ResilientUpload.Queue('section-{{ $section->id }}', {
        url: {{ Js::from(route('departments.sections.show', [$department->levelAlias(), $department, $section])) }},
        label: {{ Js::from($section->name) }},
    });

    fldTitle.addEventListener('input', () => {
        if (uploadFiles.length === 1) uploadFiles[0].titleInput.value = fldTitle.value;
    });

    // Populate parent options from the server-side data island
    if (parentSelect && page.parentOptions && page.parentOptions.length > 0) {
        page.parentOptions.forEach(function (opt) {
            const el = document.createElement('option');
            el.value = opt.id;
            el.textContent = opt.title + ' (' + opt.date + ')';
            parentSelect.appendChild(el);
        });
    }

    // [{file, titleInput, statusBadge, row}]
    let uploadFiles = [];
    let isUploading = false;

    // ── Helpers ───────────────────────────────────────────────────────────────
    function fileToTitle(name) {
        return name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
    }

    function badgeClass(state) {
        const base = 'queue-status flex-shrink-0 text-[10px] px-1.5 py-0.5 rounded font-medium ';
        const map = {
            pending:   'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500',
            uploading: 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
            done:      'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
            error:     'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
        };
        return base + (map[state] || map.pending);
    }

    function setRowStatus(item, state, msg) {
        item.statusBadge.className = badgeClass(state);
        const labels = { pending: 'Pending', uploading: 'Uploading…', done: '✓ Done', error: '✗ Error' };
        item.statusBadge.textContent = labels[state] || state;
        if (item.errorLine) {
            if (state === 'error' && msg) { item.errorLine.textContent = msg; item.errorLine.classList.remove('hidden'); }
            else { item.errorLine.classList.add('hidden'); }
        }
        if (state === 'done') item.row.style.opacity = '0.6';
    }

    function showErr(id, msg) {
        const el = document.getElementById(id);
        if (el) { el.textContent = msg; el.style.display = 'block'; }
    }
    function clearErr(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    function syncUI() {
        const n = uploadFiles.length;
        queueCountEl.textContent = n;
        queueWrap.style.display = n ? 'flex' : 'none';
        queueHint.style.display = n ? 'none' : 'block';
        btnLabel.textContent = n > 1 ? `Upload ${n} files` : 'Upload';
        btnSubmit.disabled = n === 0 || isUploading;

        const single = n === 1;
        uploadFiles.forEach(it => { it.titleWrap.style.display = single ? 'none' : ''; });
        fldTitle.disabled = n > 1;
        fldTitleHint.style.display = n > 1 ? 'block' : 'none';
        fldTitle.value = single ? uploadFiles[0].titleInput.value : '';
    }

    // ── Queue management ──────────────────────────────────────────────────────
    function addFiles(files, folderId, skipPersist) {
        Array.from(files).forEach(file => {
            const row = document.createElement('div');
            row.className = 'queue-row flex items-start gap-2 p-2 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700';

            const icon = document.createElement('i');
            icon.className = 'ti ti-file-text text-slate-400 dark:text-slate-500 flex-shrink-0 text-sm mt-1.5';

            const meta = document.createElement('div');
            meta.className = 'flex-1 min-w-0 flex flex-col gap-1';

            const titleWrap = document.createElement('div');
            titleWrap.className = 'flex flex-col gap-1';

            const titleLabel = document.createElement('label');
            titleLabel.className = 'text-[10px] font-medium text-slate-400 dark:text-slate-500 flex items-center gap-1';
            titleLabel.innerHTML = '<i class="ti ti-pencil text-[10px]"></i> Title (auto-filled — change if you like)';

            const titleInput = document.createElement('input');
            titleInput.type = 'text';
            titleInput.className = 'w-full text-xs font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-md px-2 py-1 focus:border-indigo-400 focus:ring-1 focus:ring-indigo-400 outline-none';
            titleInput.value = fileToTitle(file.name);
            titleInput.placeholder = 'Document title';
            titleInput.maxLength = 255;
            titleInput.addEventListener('input', () => { if (uploadFiles.length === 1) fldTitle.value = titleInput.value; });
            titleWrap.appendChild(titleLabel);
            titleWrap.appendChild(titleInput);

            const sizeLine = document.createElement('p');
            sizeLine.className = 'text-[10px] text-slate-400 dark:text-slate-500 truncate';
            sizeLine.textContent = (file.size / 1048576).toFixed(1) + ' MB · ' + file.name;

            // Full error text (e.g. "Title must contain at least one letter…") lives here, not
            // in statusBadge — that badge is flex-shrink-0 with no width cap, so a long message
            // used to force it to its full unwrapped width and crush titleInput down to a sliver,
            // hiding the very control the user needs to fix the title. This wraps normally inside
            // meta's constrained width instead, right under the input.
            const errorLine = document.createElement('p');
            errorLine.className = 'text-[10px] text-red-500 dark:text-red-400 hidden';

            meta.appendChild(titleWrap);
            meta.appendChild(sizeLine);
            meta.appendChild(errorLine);

            const statusBadge = document.createElement('span');
            statusBadge.className = badgeClass('pending');
            statusBadge.textContent = 'Pending';

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'flex-shrink-0 text-slate-300 dark:text-slate-600 hover:text-red-400 transition-colors mt-1';
            removeBtn.innerHTML = '<i class="ti ti-x text-xs"></i>';

            row.appendChild(icon);
            row.appendChild(meta);
            row.appendChild(statusBadge);
            row.appendChild(removeBtn);
            queueList.appendChild(row);

            const item = { file, titleInput, titleWrap, errorLine, statusBadge, row, folderId: folderId || null, queueId: null };
            uploadFiles.push(item);
            if (!skipPersist) queue.add(file, item.folderId).then(id => { item.queueId = id; });
            removeBtn.addEventListener('click', () => {
                if (isUploading) return;
                row.remove();
                uploadFiles.splice(uploadFiles.indexOf(item), 1);
                queue.remove(item.queueId);
                syncUI();
            });
        });
        syncUI();
    }

    // ── Folder upload (webkitdirectory) ─────────────────────────────────────
    // The picked folder is created here as a real Folder. Everything inside it, at any depth,
    // goes into that one folder (only one level of subfolder nesting exists app-wide, so a
    // folder picked at section level always becomes a root folder here).
    const folderInput = document.getElementById('doc-folder-input');
    const folderCache = new Map();

    async function ensureFolder(name) {
        if (folderCache.has(name)) return folderCache.get(name);
        const promise = (async () => {
            try {
                const fd = new FormData();
                fd.append('_token', page.csrfToken);
                fd.append('name', name);
                fd.append('find_or_create', '1');
                const res = await fetch(page.folderStoreUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.csrfToken },
                    body: fd,
                });
                const json = await res.json();
                return res.ok ? json.id : null;
            } catch (e) {
                console.error('Folder create failed:', name, e);
                return null;
            }
        })();
        folderCache.set(name, promise);
        return promise;
    }

    if (folderInput) {
        folderInput.addEventListener('change', async () => {
            const files = Array.from(folderInput.files);
            folderInput.value = '';
            if (!files.length) return;

            for (const file of files) {
                const parts = (file.webkitRelativePath || file.name).split('/');
                if (parts.length <= 1) {
                    addFiles([file]);
                } else {
                    const id = await ensureFolder(parts[0]);
                    addFiles([file], id);
                }
            }
        });
    }

    function clearQueue() {
        if (isUploading) return;
        uploadFiles.forEach(it => queue.remove(it.queueId));
        uploadFiles = [];
        queueList.innerHTML = '';
        syncUI();
    }

    window.__closeUploadModal = function () {
        if (isUploading) return;
        modal.style.display = 'none';
    };

    // Offers to resume a queue left over from a reload/crash mid-upload.
    (async function offerResume() {
        const rows = await queue.all();
        if (!rows.length) return;
        const { isConfirmed } = await Swal.fire({
            icon: 'info',
            title: rows.length + ' file' + (rows.length > 1 ? 's' : '') + ' never finished uploading',
            text: 'Found ' + rows.length + ' file(s) queued from an earlier session on this device. Resume the upload, or discard them?',
            showCancelButton: true,
            confirmButtonText: 'Resume upload',
            cancelButtonText: 'Discard',
        });
        if (isConfirmed) {
            rows.forEach(r => addFiles([r.file], r.folderId, true));
            rows.forEach((r, i) => { uploadFiles[i].queueId = r.id; });
            modal.style.display = 'block';
        } else {
            rows.forEach(r => queue.remove(r.id));
        }
    })();

    // ── Drop zone ─────────────────────────────────────────────────────────────
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) addFiles(fileInput.files);
        fileInput.value = '';
    });

    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.style.borderColor = '#6366f1'; });
    dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.style.borderColor = '';
        if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });

    btnClear.addEventListener('click', clearQueue);

    // ── Sequential upload loop ────────────────────────────────────────────────
    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (isUploading || uploadFiles.length === 0) return;

        clearErr('err-type');
        if (!typeSelect.value) {
            showErr('err-type', 'Select a document type before uploading.');
            return;
        }

        const type            = typeSelect.value;
        const visibility      = form.querySelector('[name="visibility"]:checked')?.value || 'public';

        @if($section->visibility === 'authenticated')
        if (visibility === 'public') {
            const { isConfirmed } = await Swal.fire({
                icon: 'warning',
                title: 'This section is Authenticated Only',
                text: 'Marking these documents Public won\'t make them visible to guests — the section\'s own restriction still applies. Continue anyway?',
                showCancelButton: true,
                confirmButtonText: 'Upload as Public anyway',
                cancelButtonText: 'Cancel',
            });
            if (!isConfirmed) return;
        }
        @endif

        const language        = form.querySelector('[name="language"]')?.value || 'english';
        const parentId        = parentSelect ? (parentSelect.value || '') : '';
        const contextInput    = form.querySelector('[name="section_id"]');
        const amendmentNumber = form.querySelector('[name="amendment_number"]')?.value?.trim() || '';
        const effectiveYear   = form.querySelector('[name="effective_year"]')?.value?.trim()   || '';
        const effectiveMonth  = form.querySelector('[name="effective_month"]')?.value          || '';
        const effectiveDay    = form.querySelector('[name="effective_day"]')?.value?.trim()    || '';

        isUploading = true;
        btnSubmit.disabled = true;
        statusEl.textContent = '';
        btnSubmit.onclick = null;
        btnClear.disabled = true;
        progressTrack.style.display = 'block';
        progressBar.style.width = '0%';

        let doneCount = 0, errorCount = 0, lastRedirect = null;

        for (let i = 0; i < uploadFiles.length; i++) {
            const item = uploadFiles[i];
            const title = item.titleInput.value.trim();

            if (!title) {
                setRowStatus(item, 'error', 'Title required');
                errorCount++;
                continue;
            }

            setRowStatus(item, 'uploading');
            statusEl.textContent = `Uploading ${i + 1} of ${uploadFiles.length}…`;

            const fields = {
                title: title,
                document_type: type,
                visibility: visibility,
                language: language,
                parent_id: parentId || null,
                amendment_number: amendmentNumber,
                effective_year: effectiveYear,
                effective_month: effectiveMonth,
                effective_day: effectiveDay,
            };
            if (item.folderId) fields.folder_id = item.folderId;
            else if (contextInput) fields[contextInput.name] = contextInput.value;

            // Handles a stale CSRF token (419) and a hit on the upload throttle (429)
            // transparently, and transparently splits+reassembles a PDF too large for the
            // tunnel's own edge cap — see public/js/resilient-upload.js.
            const { ok, status, json } = await ResilientUpload.uploadFile(item.file, fields, page, (n, total) => {
                statusEl.textContent = `Uploading ${i + 1} of ${uploadFiles.length} — piece ${n} of ${total} (splitting large PDF)…`;
            });
            if (!json) {
                setRowStatus(item, 'error', status === 0 ? 'Network error' : 'HTTP ' + status);
                errorCount++;
            } else if (!ok) {
                const msg = json.errors
                    ? Object.values(json.errors).flat()[0]
                    : (json.message || 'Upload failed');
                setRowStatus(item, 'error', msg);
                errorCount++;
            } else {
                setRowStatus(item, 'done');
                doneCount++;
                lastRedirect = json.redirect;
                await queue.remove(item.queueId);
            }

            progressBar.style.width = Math.round(((i + 1) / uploadFiles.length) * 100) + '%';
            if (i < uploadFiles.length - 1) await ResilientUpload.sleep(ResilientUpload.PACE_MS);
        }

        isUploading = false;
        btnClear.disabled = false;
        progressTrack.style.display = 'none';

        // A per-row badge (setRowStatus) already shows each failure, but it's easy to miss in a
        // long queue — surface a toast too so a batch with failures never looks like it silently
        // finished. Non-blocking, auto-dismisses; the per-row text is still there for detail.
        if (errorCount > 0) {
            Swal.fire({
                toast: true, position: 'top-end', icon: 'warning', showConfirmButton: false,
                timer: 6000, timerProgressBar: true,
                title: errorCount === 1 ? '1 file failed to upload' : `${errorCount} files failed to upload`,
                text: 'See the highlighted rows below for details.',
            });
        }

        if (errorCount === 0 && lastRedirect) {
            window.location.href = lastRedirect;
            return;
        }

        if (doneCount > 0 && lastRedirect) {
            statusEl.textContent = `${doneCount} uploaded, ${errorCount} failed — fix errors or continue.`;
            btnSubmit.disabled = false;
            btnLabel.textContent = 'Go to page';
            btnSubmit.onclick = ev => { ev.preventDefault(); window.location.href = lastRedirect; };
        } else {
            statusEl.textContent = `${doneCount} uploaded, ${errorCount} failed.`;
            btnSubmit.disabled = false;
            btnLabel.textContent = errorCount > 0 ? `Retry (${errorCount} failed)` : 'Upload';
        }
        syncUI();
    });
})();
</script>

<script>
(function () {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value || '';

    function esc(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.querySelectorAll('.doc-delete-btn').forEach(btn => {
        btn.addEventListener('click', async function () {
            const action = this.dataset.action;
            const title  = this.dataset.title;
            const dark   = document.documentElement.classList.contains('dark');

            const { value: reason, isConfirmed } = await Swal.fire({
                title: 'Move to Trash',
                html: `<p class="text-sm mb-3">Moving <strong>${esc(title)}</strong> to trash.</p>
                       <textarea id="swal-reason" class="swal2-textarea" placeholder="Reason for deletion (required)" rows="3" style="resize:vertical"></textarea>`,
                showCancelButton: true,
                confirmButtonText: 'Move to Trash',
                confirmButtonColor: '#dc2626',
                cancelButtonText: 'Cancel',
                background: dark ? '#1e293b' : '#fff',
                color: dark ? '#f1f5f9' : '#1e293b',
                preConfirm: () => {
                    const r = document.getElementById('swal-reason').value.trim();
                    if (!r || r.length < 5) {
                        Swal.showValidationMessage('Please enter a reason (at least 5 characters).');
                        return false;
                    }
                    if (r.length > 500) {
                        Swal.showValidationMessage('Reason must be 500 characters or fewer.');
                        return false;
                    }
                    return r;
                },
            });

            if (!isConfirmed || !reason) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = action;
            form.style.display = 'none';
            form.innerHTML = `<input name="_token" value="${csrfToken}"><input name="_method" value="DELETE"><input name="reason" value="${reason.replace(/"/g, '&quot;')}">`;
            document.body.appendChild(form);
            form.submit();
        });
    });
})();
</script>
@endpush

</x-layout>

@php
    $isDivisionFolder = isset($division) && $division !== null;
    $isSubfolder = $folder->parent_id !== null;
    $editUrl = $isDivisionFolder
        ? route('departments.sections.divisions.folders.edit', [$department->levelAlias(), $department, $section, $division, $folder])
        : route('departments.sections.folders.edit', [$department->levelAlias(), $department, $section, $folder]);
    $destroyUrl = $isDivisionFolder
        ? route('departments.sections.divisions.folders.destroy', [$department->levelAlias(), $department, $section, $division, $folder])
        : route('departments.sections.folders.destroy', [$department->levelAlias(), $department, $section, $folder]);
    $showUrl = $isDivisionFolder
        ? route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder])
        : route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $folder]);
    $downloadUrl = $isDivisionFolder
        ? route('departments.sections.divisions.folders.download', [$department->levelAlias(), $department, $section, $division, $folder])
        : route('departments.sections.folders.download', [$department->levelAlias(), $department, $section, $folder]);
    $convertAllUrl = $isDivisionFolder
        ? route('departments.sections.divisions.folders.convert-all', [$department->levelAlias(), $department, $section, $division, $folder])
        : route('departments.sections.folders.convert-all', [$department->levelAlias(), $department, $section, $folder]);
    $createSubfolderUrl = $isDivisionFolder
        ? route('departments.sections.divisions.folders.subfolders.create', [$department->levelAlias(), $department, $section, $division, $folder])
        : route('departments.sections.folders.subfolders.create', [$department->levelAlias(), $department, $section, $folder]);
    $subfolderShowUrl = fn ($sub) => $isDivisionFolder
        ? route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $sub])
        : route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $sub]);
    $parentUrl = $isSubfolder ? $subfolderShowUrl($folder->parent) : null;
    $subfolderStoreUrl = $isSubfolder ? null : ($isDivisionFolder
        ? route('departments.sections.divisions.folders.subfolders.store', [$department->levelAlias(), $department, $section, $division, $folder])
        : route('departments.sections.folders.subfolders.store', [$department->levelAlias(), $department, $section, $folder]));
    $pageData = [
        'storeUrl' => route('documents.store'),
        'storeChunkUrl' => route('documents.store-chunk'),
        'csrfToken' => csrf_token(),
        'csrfTokenUrl' => route('documents.csrf-token'),
        'parentOptions' => $parentOptions,
        'currentFolderId' => $folder->id,
        'isSubfolder' => $isSubfolder,
        'subfolderStoreUrl' => $subfolderStoreUrl,
    ];
@endphp
<x-layout
    :title="$folder->name"
    :page-title="$folder->name"
    :page-subtitle="$department->name . ' · ' . ($isDivisionFolder ? $division->name : $section->name)"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                       'url' => route('home')],
    ['name' => 'Departments',                'url' => route('departments.index')],
    ['name' => $department->levelLabel(),    'url' => null],
    ['name' => $department->name,            'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $section->name,               'url' => route('departments.sections.show', [$department->levelAlias(), $department, $section])],
    ...($isDivisionFolder ? [['name' => $division->name, 'url' => route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division])]] : []),
    ...($isSubfolder ? [['name' => $folder->parent->name, 'url' => $parentUrl]] : []),
    ['name' => $folder->name,                'url' => null],
]" />

<script id="page-data" type="application/json">@json($pageData)</script>
<script src="{{ asset('js/resilient-upload.js') }}"></script>
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>

{{-- ── Folder header ────────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-6 flex-wrap">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-cyan-500/10 dark:bg-cyan-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-folder-star text-cyan-500 dark:text-cyan-400 text-xl"></i>
        </div>
        <div>
            <div class="flex items-center gap-2">
                <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $folder->name }}</h2>
                @if($folder->visibility === 'authenticated')
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                    <i class="ti ti-lock text-[10px]"></i> Authenticated Only
                </span>
                @else
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">
                    <i class="ti ti-world text-[10px]"></i> Public
                </span>
                @endif
                @if($folder->requires_approval)
                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300">
                    <i class="ti ti-shield-check text-[10px]"></i> Requires Approval
                </span>
                @endif
            </div>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <a href="{{ route('departments.show', [$department->levelAlias(), $department]) }}" class="text-xs text-slate-500 dark:text-slate-400 hover:text-cyan-600 dark:hover:text-cyan-400 transition-colors">{{ $department->name }}</a>
                <span class="text-slate-300 dark:text-slate-600">›</span>
                <a href="{{ route('departments.sections.show', [$department->levelAlias(), $department, $section]) }}" class="text-xs text-slate-500 dark:text-slate-400 hover:text-cyan-600 dark:hover:text-cyan-400 transition-colors">{{ $section->name }}</a>
                @if($isDivisionFolder)
                <span class="text-slate-300 dark:text-slate-600">›</span>
                <a href="{{ route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]) }}" class="text-xs text-slate-500 dark:text-slate-400 hover:text-cyan-600 dark:hover:text-cyan-400 transition-colors">{{ $division->name }}</a>
                @endif
                @if($isSubfolder)
                <span class="text-slate-300 dark:text-slate-600">›</span>
                <a href="{{ $parentUrl }}" class="text-xs text-slate-500 dark:text-slate-400 hover:text-cyan-600 dark:hover:text-cyan-400 transition-colors">{{ $folder->parent->name }}</a>
                @endif
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $totalCount }} {{ Str::plural('document', $totalCount) }}</span>
            </div>
            @if($folder->description)
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $folder->description }}</p>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2 flex-wrap justify-end w-full sm:w-auto">
        <a href="{{ $downloadUrl }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-emerald-400 dark:hover:border-emerald-500 text-slate-600 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 text-sm font-medium px-3 py-2 rounded-lg transition-all"
           title="Download all markdown as ZIP">
            <i class="ti ti-file-zip text-base"></i>
            <span class="hidden sm:inline">Download ZIP</span>
        </a>
        @auth
        @if(auth()->user()->canUploadTo($folder))
        <button type="button" id="convert-all-btn" data-convert-all-url="{{ $convertAllUrl }}"
                class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all"
                title="Queue Convert to Markdown for every document in this folder that isn't already converted">
            <i class="ti ti-markdown text-base"></i>
            <span class="hidden sm:inline">Convert All to Markdown</span>
        </button>
        <button type="button"
                onclick="document.getElementById('modal-upload').style.display='block'"
                class="inline-flex items-center gap-1.5 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-upload text-base"></i>
            <span class="hidden sm:inline">Upload Document</span>
        </button>
        @if(!$isSubfolder)
        <a href="{{ $createSubfolderUrl }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-cyan-400 dark:hover:border-cyan-500 text-slate-600 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-folder-plus text-base"></i>
            <span class="hidden sm:inline">Add Subfolder</span>
        </a>
        @endif
        @endif
        @if(auth()->user()->isAdmin() || auth()->user()->canUploadTo($folder))
        <a href="{{ $editUrl }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-cyan-400 dark:hover:border-cyan-500 text-slate-600 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-pencil text-base"></i>
        </a>
        @endif
        @if(auth()->user()->canDeleteFolder($folder))
        <button type="button" id="delete-folder-btn"
                class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-trash text-base"></i>
        </button>
        <form id="delete-folder-form" method="POST" action="{{ $destroyUrl }}" style="display:none">
            @csrf @method('DELETE')
        </form>
        @endif
        @endauth
    </div>
</div>

{{-- ── Upload modal ─────────────────────────────────────────────────────────── --}}
@auth
<div id="modal-upload"
     style="display:none;position:fixed;inset:0;z-index:50;background:rgba(0,0,0,0.6)"
     onclick="if(event.target===this)window.__closeUploadModal()">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(960px,95vw);max-height:90vh;overflow-y:auto"
         class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <div class="flex items-center gap-3">
                <i class="ti ti-file-upload text-cyan-500 text-lg"></i>
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Upload Document</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $folder->name }}</span>
            </div>
            <button type="button" onclick="window.__closeUploadModal()"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <div class="flex flex-col lg:flex-row flex-1 min-h-0">
            {{-- Left: file drop + queue --}}
            <div class="lg:w-1/2 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-700 flex flex-col p-4 gap-3">
                <div id="fld-drop-zone" onclick="document.getElementById('fld-file').click()" style="cursor:pointer"
                     class="rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-cyan-400 dark:hover:border-cyan-500 transition-colors flex flex-col items-center justify-center gap-1.5 py-5 px-4 text-center flex-shrink-0">
                    <i class="ti ti-cloud-upload text-2xl text-slate-300 dark:text-slate-600"></i>
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag files here</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 300 MB each · multiple files supported</p>
                    <input type="file" id="fld-file" name="file" multiple
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif"
                           style="display:none">
                </div>
                <button type="button" id="fld-folder-btn" onclick="document.getElementById('fld-folder-input').click()"
                        class="text-xs text-cyan-600 dark:text-cyan-400 hover:underline flex items-center justify-center gap-1 flex-shrink-0">
                    <i class="ti ti-folder-up"></i> Or upload a whole folder
                </button>
                <input type="file" id="fld-folder-input" webkitdirectory directory multiple style="display:none">
                @if(!$isSubfolder)
                <p class="text-[11px] text-slate-400 dark:text-slate-500 text-center -mt-1">The picked folder becomes a subfolder here (reusing one with the same name if it exists). Everything inside it, at any depth, goes into that one subfolder.</p>
                @else
                <p class="text-[11px] text-slate-400 dark:text-slate-500 text-center -mt-1">This is already a subfolder, so every file in the picked folder (and any of its own subfolders) is placed here directly.</p>
                @endif
                <div id="fld-queue-wrap" class="flex-1 overflow-hidden flex flex-col min-h-0" style="display:none">
                    <div class="flex items-center justify-between mb-1.5 flex-shrink-0">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                            Queue &nbsp;<span id="fld-queue-count" class="text-cyan-500 font-bold normal-case">0</span>
                        </p>
                        <button type="button" id="fld-btn-clear" class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">Clear all</button>
                    </div>
                    <div id="fld-queue" class="overflow-y-auto flex flex-col gap-1.5" style="max-height:240px"></div>
                </div>
                <p id="fld-queue-hint" class="text-xs text-slate-400 dark:text-slate-500 text-center py-1">Select one or more files — each gets its own title</p>
            </div>
            {{-- Right: form --}}
            <div class="lg:w-1/2 p-6 flex flex-col gap-4">
                <form id="fld-form" method="POST" action="{{ route('documents.store') }}" novalidate enctype="multipart/form-data" class="flex flex-col gap-4 flex-1">
                    @csrf
                    <input type="hidden" name="section_id" value="{{ $section->id }}">
                    @if($isDivisionFolder)
                    <input type="hidden" name="division_id" value="{{ $division->id }}">
                    @endif
                    <input type="hidden" name="folder_id" value="{{ $folder->id }}">

                    <div>
                        <label for="fld-type" class="field-label">Document Type <span class="text-red-500">*</span></label>
                        <select id="fld-type" name="document_type" class="field-input">
                            <option value="">— Select type —</option>
                            @foreach(\App\Models\Document::DOCUMENT_TYPES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p id="fld-err-type" class="field-err-msg" style="display:none"></p>
                    </div>

                    <div>
                        <label for="fld-title" class="field-label">Title <span class="text-slate-400 font-normal">(optional — auto-filled from filename)</span></label>
                        <input type="text" id="fld-title" class="field-input" placeholder="Auto-filled from filename" maxlength="255">
                        <p id="fld-title-hint" class="text-xs text-slate-400 dark:text-slate-500 mt-1" style="display:none">Multiple files queued — edit each one's title in the queue on the left.</p>
                    </div>

                    <div>
                        <label for="fld-parent" class="field-label">Amends Previous Document <span class="text-slate-400 font-normal">(optional)</span></label>
                        <select id="fld-parent" name="parent_id" class="field-input">
                            <option value="">— None —</option>
                        </select>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Only root documents within this folder are listed.</p>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="fld-amendment-number" class="field-label">Amendment No. <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="fld-amendment-number" name="amendment_number" min="1" max="999"
                                   placeholder="e.g. 5" class="field-input">
                        </div>
                        <div>
                            <label for="fld-effective-year" class="field-label">Effective Year <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="fld-effective-year" name="effective_year" min="1900" max="2099"
                                   placeholder="e.g. 2019" class="field-input">
                        </div>
                        <div>
                            <label for="fld-effective-month" class="field-label">Month <span class="text-slate-400 font-normal">(optional)</span></label>
                            <select id="fld-effective-month" name="effective_month" class="field-input">
                                <option value="">—</option>
                                @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                                <option value="{{ $mi + 1 }}">{{ $mn }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="fld-effective-day" class="field-label">Day <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="fld-effective-day" name="effective_day" min="1" max="31"
                                   placeholder="1–31" class="field-input">
                        </div>
                    </div>

                    <div>
                        <label class="field-label">Visibility</label>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="public" id="fld-visibility-public" @checked($folder->visibility !== 'authenticated') class="text-cyan-600 focus:ring-cyan-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-world text-sm text-green-500"></i> Public</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="authenticated" @checked($folder->visibility === 'authenticated') class="text-cyan-600 focus:ring-cyan-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only</span>
                            </label>
                        </div>
                        @if($folder->visibility === 'authenticated')
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1">
                            <i class="ti ti-alert-triangle text-sm"></i> This folder is Authenticated Only, so documents default to the same. Marking one Public here has no real effect — guests still can't reach it while the folder itself is restricted.
                        </p>
                        @endif
                    </div>

                    <div>
                        <label for="fld-language" class="field-label">Language</label>
                        <select id="fld-language" name="language" class="field-input">
                            @foreach(\App\Models\Document::LANGUAGES as $key => $label)
                            <option value="{{ $key }}" @selected($key === 'english')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-800/60 rounded-lg px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Saving to</p>
                        <p class="text-xs text-cyan-600 dark:text-cyan-400 font-medium">
                            @php
                                $vaultCrumbs = array_filter([
                                    Str::title(str_replace('_', ' ', $department->level)),
                                    $department->name,
                                    $section->wing ? Str::title(str_replace('_', ' ', $section->wing)) : null,
                                    $section->name,
                                    $isDivisionFolder ? 'Divisions' : null,
                                    $isDivisionFolder ? $division->name : null,
                                    'Folders',
                                    $folder->name,
                                ]);
                            @endphp
                            {{ implode(' › ', $vaultCrumbs) }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 mt-auto pt-2">
                        <div class="flex items-center gap-3">
                            <button type="submit" id="fld-btn-submit"
                                    class="inline-flex items-center gap-2 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                                <i class="ti ti-upload"></i>
                                <span id="fld-btn-label">Upload</span>
                            </button>
                            <span id="fld-upload-status" class="text-xs text-slate-400 dark:text-slate-500"></span>
                        </div>
                        <div id="fld-progress-track" style="display:none" class="h-1.5 w-full rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                            <div id="fld-progress-bar" class="h-full bg-cyan-500 transition-all" style="width:0%"></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endauth

{{-- ── Subfolders ────────────────────────────────────────────────────────────── --}}
@if($subfolders->isNotEmpty())
<div class="mb-6">
    <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3">Subfolders</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        @foreach($subfolders as $sub)
        <a href="{{ $subfolderShowUrl($sub) }}"
           class="flex items-center gap-3 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-cyan-400 dark:hover:border-cyan-500 rounded-xl px-4 py-3 transition-colors">
            <div class="w-9 h-9 rounded-lg bg-cyan-500/10 dark:bg-cyan-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-folder text-cyan-500 dark:text-cyan-400"></i>
            </div>
            <div class="min-w-0">
                <p class="text-sm font-medium text-slate-800 dark:text-slate-100 truncate">{{ $sub->name }}</p>
                <p class="text-xs text-slate-400 dark:text-slate-500">{{ $sub->documents_count }} {{ Str::plural('document', $sub->documents_count) }}</p>
            </div>
        </a>
        @endforeach
    </div>
</div>
@endif

{{-- ── Documents ─────────────────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Documents</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                {{ $totalCount }} {{ Str::plural('document', $totalCount) }}
                @guest · public only @endguest
                @if($filterYear) · filtered to {{ $filterYear }} @endif
            </p>
        </div>
        @if($totalCount > 1)
        <form method="GET" action="{{ $showUrl }}" class="flex items-center gap-2 flex-wrap">
            <select name="sort" onchange="this.form.submit()"
                    class="text-xs border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-cyan-500">
                <option value="amendment_number_desc" @selected($sort === 'amendment_number_desc')>Amendment # ↓ newest first</option>
                <option value="amendment_number_asc"  @selected($sort === 'amendment_number_asc')>Amendment # ↑ oldest first</option>
                <option value="year_desc"             @selected($sort === 'year_desc')>Year ↓</option>
                <option value="year_asc"              @selected($sort === 'year_asc')>Year ↑</option>
                <option value="uploaded_desc"         @selected($sort === 'uploaded_desc')>Uploaded ↓</option>
                <option value="uploaded_asc"          @selected($sort === 'uploaded_asc')>Uploaded ↑</option>
            </select>
            @if($availableYears->isNotEmpty())
            <select name="year" onchange="this.form.submit()"
                    class="text-xs border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-cyan-500">
                <option value="">All years</option>
                @foreach($availableYears as $yr)
                <option value="{{ $yr }}" @selected($filterYear == $yr)>{{ $yr }}</option>
                @endforeach
            </select>
            @endif
            @if($filterYear)
            <a href="{{ $showUrl }}" class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors" title="Clear filter">
                <i class="ti ti-x"></i>
            </a>
            @endif
        </form>
        @endif
    </div>

    @if($rootDocuments->isEmpty() && $totalCount === 0)
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-files text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No documents yet</p>
        @auth
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Use the Upload button above to add the first document.</p>
        @else
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Documents will appear here once available.</p>
        @endauth
    </div>
    @elseif($rootDocuments->isEmpty())
    <div class="flex flex-col items-center justify-center py-10 text-center">
        <i class="ti ti-folder-share text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No documents directly in this folder</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">All {{ $totalCount }} {{ Str::plural('document', $totalCount) }} are in the subfolder(s) above.</p>
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($rootDocuments as $doc)
        @include('folders._doc_row', ['doc' => $doc, 'department' => $department, 'section' => $section, 'division' => $division ?? null, 'folder' => $folder, 'isAmendment' => false])
        @foreach($doc->amendments as $amendment)
        @include('folders._doc_row', ['doc' => $amendment, 'department' => $department, 'section' => $section, 'division' => $division ?? null, 'folder' => $folder, 'isAmendment' => true])
        @endforeach
        @endforeach
    </div>
    @endif
</div>

@push('scripts')
<script>
(function () {
    let page;
    try { page = JSON.parse(document.getElementById('page-data').textContent); }
    catch (e) { console.error('page-data parse failed', e); return; }

    function fileToTitle(name) {
        return name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
    }
    function badgeClass(state) {
        const base = 'queue-status flex-shrink-0 text-[10px] px-1.5 py-0.5 rounded font-medium ';
        const map = {
            pending:   'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500',
            uploading: 'bg-cyan-100 dark:bg-cyan-900/30 text-cyan-600 dark:text-cyan-400',
            done:      'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
            error:     'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
        };
        return base + (map[state] || map.pending);
    }
    function showErr(id, msg) { const el = document.getElementById(id); if (el) { el.textContent = msg; el.style.display = 'block'; } }
    function clearErr(id)     { const el = document.getElementById(id); if (el) el.style.display = 'none'; }

    const fileInput = document.getElementById('fld-file');
    const dropZone  = document.getElementById('fld-drop-zone');
    const queueList = document.getElementById('fld-queue');
    const countEl   = document.getElementById('fld-queue-count');
    const queueWrap = document.getElementById('fld-queue-wrap');
    const queueHint = document.getElementById('fld-queue-hint');
    const clearBtn  = document.getElementById('fld-btn-clear');
    const form      = document.getElementById('fld-form');
    const btnSubmit = document.getElementById('fld-btn-submit');
    const btnLabel  = document.getElementById('fld-btn-label');
    const statusEl  = document.getElementById('fld-upload-status');
    const fldTitle     = document.getElementById('fld-title');
    const fldTitleHint = document.getElementById('fld-title-hint');
    const progressTrack = document.getElementById('fld-progress-track');
    const progressBar   = document.getElementById('fld-progress-bar');

    if (!fileInput || !form) return;

    // Persists the picked queue in IndexedDB so a reload/crash mid-batch doesn't force
    // re-picking all the files — see public/js/resilient-upload.js.
    const queue = new ResilientUpload.Queue('folder-{{ $folder->id }}', {
        url: {{ Js::from($division ? route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder]) : route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $folder])) }},
        label: {{ Js::from($folder->name) }},
    });

    fldTitle.addEventListener('input', () => {
        if (uploadFiles.length === 1) uploadFiles[0].titleInput.value = fldTitle.value;
    });

    const parentSel = document.getElementById('fld-parent');
    if (parentSel && page.parentOptions && page.parentOptions.length > 0) {
        page.parentOptions.forEach(function (opt) {
            const el = document.createElement('option');
            el.value = opt.id;
            el.textContent = opt.title + ' (' + opt.date + ')';
            parentSel.appendChild(el);
        });
    }

    let uploadFiles = [];
    let isUploading = false;

    function setRowStatus(item, state, msg) {
        item.statusBadge.className = badgeClass(state);
        const labels = { pending: 'Pending', uploading: 'Uploading…', done: '✓ Done' };
        item.statusBadge.textContent = state === 'error' ? ('✗ ' + (msg || 'Error')) : (labels[state] || state);
        if (state === 'done') item.row.style.opacity = '0.6';
    }

    function syncUI() {
        const n = uploadFiles.length;
        countEl.textContent = n;
        queueWrap.style.display = n ? 'flex' : 'none';
        queueHint.style.display = n ? 'none' : 'block';
        btnLabel.textContent = n > 1 ? ('Upload ' + n + ' files') : 'Upload';
        btnSubmit.disabled = n === 0 || isUploading;

        // Single file: the "Title" field on the right is the primary editor, and the
        // per-row title box (needed only so multiple files can each have their own title)
        // is hidden to avoid showing the same thing twice.
        const single = n === 1;
        uploadFiles.forEach(it => { it.titleWrap.style.display = single ? 'none' : ''; });
        fldTitle.disabled = n > 1;
        fldTitleHint.style.display = n > 1 ? 'block' : 'none';
        fldTitle.value = single ? uploadFiles[0].titleInput.value : '';
    }

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
            titleInput.className = 'w-full text-xs font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-md px-2 py-1 focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400 outline-none';
            titleInput.value = fileToTitle(file.name);
            titleInput.placeholder = 'Document title';
            titleInput.maxLength = 255;
            titleInput.addEventListener('input', () => { if (uploadFiles.length === 1) fldTitle.value = titleInput.value; });
            titleWrap.appendChild(titleLabel);
            titleWrap.appendChild(titleInput);
            const sizeLine = document.createElement('p');
            sizeLine.className = 'text-[10px] text-slate-400 dark:text-slate-500 truncate';
            sizeLine.textContent = (file.size / 1048576).toFixed(1) + ' MB · ' + file.name;
            meta.appendChild(titleWrap);
            meta.appendChild(sizeLine);
            const statusBadge = document.createElement('span');
            statusBadge.className = badgeClass('pending');
            statusBadge.textContent = 'Pending';
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'flex-shrink-0 text-slate-300 dark:text-slate-600 hover:text-red-400 transition-colors mt-1';
            removeBtn.innerHTML = '<i class="ti ti-x text-xs"></i>';
            row.appendChild(icon); row.appendChild(meta);
            row.appendChild(statusBadge); row.appendChild(removeBtn);
            queueList.appendChild(row);
            const item = { file, titleInput, titleWrap, statusBadge, row, folderId: folderId || null, queueId: null };
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

    fileInput.addEventListener('change', () => { if (fileInput.files.length) addFiles(fileInput.files); fileInput.value = ''; });

    // ── Folder upload (webkitdirectory) ─────────────────────────────────────
    // Every browser exposing this gives each picked file a webkitRelativePath like
    // "PickedFolder/file.pdf" or "PickedFolder/Sub/file.pdf" — parts[0] is the picked
    // folder itself (not a real subfolder here), so a file lands one level deep at parts[1].
    // Only one level of subfolder nesting exists app-wide, so anything deeper just collapses
    // into that first-level subfolder instead of being rejected.
    const folderInput = document.getElementById('fld-folder-input');
    const subfolderCache = new Map();

    async function ensureSubfolder(name) {
        if (subfolderCache.has(name)) return subfolderCache.get(name);
        const promise = (async () => {
            if (!page.subfolderStoreUrl) return null;
            try {
                const fd = new FormData();
                fd.append('_token', page.csrfToken);
                fd.append('name', name);
                fd.append('find_or_create', '1');
                const res = await fetch(page.subfolderStoreUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.csrfToken },
                    body: fd,
                });
                const json = await res.json();
                return res.ok ? json.id : null;
            } catch (e) {
                console.error('Subfolder create failed:', name, e);
                return null;
            }
        })();
        subfolderCache.set(name, promise);
        return promise;
    }

    if (folderInput) {
        folderInput.addEventListener('change', async () => {
            const files = Array.from(folderInput.files);
            folderInput.value = '';
            if (!files.length) return;

            if (page.isSubfolder || !page.subfolderStoreUrl) {
                // Already one level deep — nowhere further to nest, flatten everything here.
                addFiles(files, page.currentFolderId);
                return;
            }

            // The picked folder itself becomes one subfolder here (named after it), and
            // everything inside it — at any depth — lands inside that one subfolder, since
            // only one level of nesting exists app-wide.
            for (const file of files) {
                const parts = (file.webkitRelativePath || file.name).split('/');
                if (parts.length <= 1) {
                    addFiles([file], page.currentFolderId);
                } else {
                    const id = await ensureSubfolder(parts[0]);
                    addFiles([file], id || page.currentFolderId);
                }
            }
        });
    }
    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.style.borderColor = '#0891b2'; });
    dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
    dropZone.addEventListener('drop', e => {
        e.preventDefault(); dropZone.style.borderColor = '';
        if (e.dataTransfer.files.length) addFiles(e.dataTransfer.files);
    });
    if (clearBtn) clearBtn.addEventListener('click', () => {
        if (isUploading) return;
        uploadFiles.forEach(it => queue.remove(it.queueId));
        uploadFiles = []; queueList.innerHTML = ''; syncUI();
    });

    window.__closeUploadModal = function () {
        if (isUploading) return;
        const m = document.getElementById('modal-upload');
        if (m) m.style.display = 'none';
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
            document.getElementById('modal-upload').style.display = 'block';
        } else {
            rows.forEach(r => queue.remove(r.id));
        }
    })();

    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (isUploading || uploadFiles.length === 0) return;

        const typeEl = document.getElementById('fld-type');
        if (!typeEl || !typeEl.value) { showErr('fld-err-type', 'Select a document type.'); return; }
        clearErr('fld-err-type');

        const contextSectionId  = form.querySelector('[name="section_id"]');
        const contextDivisionId = form.querySelector('[name="division_id"]');
        const contextFolderId   = form.querySelector('[name="folder_id"]');
        const parentInput       = form.querySelector('[name="parent_id"]');
        const visibility        = form.querySelector('[name="visibility"]:checked')?.value || 'public';

        @if($folder->visibility === 'authenticated')
        if (visibility === 'public') {
            const { isConfirmed } = await Swal.fire({
                icon: 'warning',
                title: 'This folder is Authenticated Only',
                text: 'Marking these documents Public won\'t make them visible to guests — the folder\'s own restriction still applies. Continue anyway?',
                showCancelButton: true,
                confirmButtonText: 'Upload as Public anyway',
                cancelButtonText: 'Cancel',
            });
            if (!isConfirmed) return;
        }
        @endif

        const language          = form.querySelector('[name="language"]')?.value || 'english';
        const amendmentNumber   = form.querySelector('[name="amendment_number"]')?.value?.trim() || '';
        const effectiveYear     = form.querySelector('[name="effective_year"]')?.value?.trim()   || '';
        const effectiveMonth    = form.querySelector('[name="effective_month"]')?.value          || '';
        const effectiveDay      = form.querySelector('[name="effective_day"]')?.value?.trim()    || '';

        isUploading = true;
        btnSubmit.disabled = true;
        statusEl.textContent = '';
        btnSubmit.onclick = null;
        clearBtn && (clearBtn.disabled = true);
        progressTrack.style.display = 'block';
        progressBar.style.width = '0%';

        let doneCount = 0, errorCount = 0, lastRedirect = null;

        for (let i = 0; i < uploadFiles.length; i++) {
            const item = uploadFiles[i];
            const title = item.titleInput.value.trim();
            if (!title) { setRowStatus(item, 'error', 'Title required'); errorCount++; continue; }

            setRowStatus(item, 'uploading');
            statusEl.textContent = 'Uploading ' + (i + 1) + ' of ' + uploadFiles.length + '…';

            const folderId = item.folderId || (contextFolderId ? contextFolderId.value : null);
            const fields = {
                title: title,
                document_type: typeEl.value,
                visibility: visibility,
                language: language,
                parent_id: parentInput && parentInput.value ? parentInput.value : null,
                amendment_number: amendmentNumber,
                effective_year: effectiveYear,
                effective_month: effectiveMonth,
                effective_day: effectiveDay,
            };
            if (contextSectionId)  fields.section_id  = contextSectionId.value;
            if (contextDivisionId) fields.division_id = contextDivisionId.value;
            if (folderId) fields.folder_id = folderId;

            // Handles a stale CSRF token (419) and a hit on the upload throttle (429)
            // transparently, and transparently splits+reassembles a PDF too large for the
            // tunnel's own edge cap — see public/js/resilient-upload.js.
            const { ok, status, json } = await ResilientUpload.uploadFile(item.file, fields, page, (n, total) => {
                statusEl.textContent = 'Uploading ' + (i + 1) + ' of ' + uploadFiles.length + ' — piece ' + n + ' of ' + total + ' (splitting large PDF)…';
            });
            if (!json) {
                setRowStatus(item, 'error', status === 0 ? 'Network error' : 'HTTP ' + status);
                errorCount++;
            } else if (!ok) {
                setRowStatus(item, 'error', json.errors ? Object.values(json.errors).flat()[0] : (json.message || 'Upload failed'));
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
        clearBtn && (clearBtn.disabled = false);
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

        if (errorCount === 0 && lastRedirect) { window.location.href = lastRedirect; return; }
        if (doneCount > 0 && lastRedirect) {
            statusEl.textContent = doneCount + ' uploaded, ' + errorCount + ' failed.';
            btnSubmit.disabled = false; btnLabel.textContent = 'Go to page';
            btnSubmit.onclick = ev => { ev.preventDefault(); window.location.href = lastRedirect; };
        } else {
            statusEl.textContent = doneCount + ' uploaded, ' + errorCount + ' failed.';
            btnSubmit.disabled = false;
            btnLabel.textContent = errorCount > 0 ? ('Retry (' + errorCount + ' failed)') : 'Upload';
        }
        syncUI();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') window.__closeUploadModal();
    });
})();
</script>

<script>
(function () {
    const deleteBtn = document.getElementById('delete-folder-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            const isDark = document.documentElement.classList.contains('dark');
            const docCount = {{ $deleteImpactCount }};
            const subfolderCount = {{ $subfolders->count() }};
            let warning;
            if (docCount > 0 && subfolderCount > 0) {
                warning = '<p class="text-sm text-red-500">This will also delete <strong>' + subfolderCount + ' subfolder(s)</strong> and move <strong>' + docCount + ' document(s)</strong> inside them to archive.</p>';
            } else if (docCount > 0) {
                warning = '<p class="text-sm text-red-500">This will also move <strong>' + docCount + ' document(s)</strong> to archive.</p>';
            } else if (subfolderCount > 0) {
                warning = '<p class="text-sm text-red-500">This will also delete <strong>' + subfolderCount + ' subfolder(s)</strong>.</p>';
            } else {
                warning = '<p class="text-sm text-gray-400">No documents are associated with this folder.</p>';
            }
            Swal.fire({
                title: 'Delete Folder?',
                html: '<p class="text-sm mb-2">You are about to delete <strong>{{ e($folder->name) }}</strong>.</p>' + warning,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                background: isDark ? '#1e293b' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#0f172a',
            }).then(function (result) {
                if (result.isConfirmed) {
                    document.getElementById('delete-folder-form').submit();
                }
            });
        });
    }
})();
</script>

<script>
(function () {
    const btn = document.getElementById('convert-all-btn');
    if (!btn) return;
    const label = btn.querySelector('span');
    const originalLabel = label.textContent;
    // A minimal page-data stand-in so this can go through ResilientUpload.request() — same
    // stale-CSRF-token recovery the upload modal gets, for the same reason (M97): this button
    // sits on the same folder page a big upload could have just spent minutes on.
    const page = { csrfToken: document.querySelector('meta[name="csrf-token"]')?.content, csrfTokenUrl: '{{ route('documents.csrf-token') }}' };

    btn.addEventListener('click', async function () {
        const { isConfirmed } = await Swal.fire({
            icon: 'question',
            title: 'Convert all to Markdown?',
            text: 'Queues every document in this folder — and its subfolders — that isn\'t already converted or in progress. Already-converted documents are left as-is.',
            showCancelButton: true,
            confirmButtonText: 'Convert All',
            cancelButtonText: 'Cancel',
        });
        if (!isConfirmed) return;

        btn.disabled = true;
        label.textContent = 'Queuing…';

        const { ok, json } = await ResilientUpload.request(btn.dataset.convertAllUrl, new FormData(), page);

        if (!ok) {
            Swal.fire({ icon: 'error', title: 'Failed', text: json?.message || 'Could not queue conversions.' });
        } else {
            await Swal.fire({
                icon: 'success',
                title: 'Queued',
                text: json.queued + ' document(s) queued for conversion' +
                    (json.skipped ? ', ' + json.skipped + ' skipped (already converted or in progress).' : '.'),
            });
        }

        btn.disabled = false;
        label.textContent = originalLabel;
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
                    if (!r || r.length < 5) { Swal.showValidationMessage('Please enter a reason (at least 5 characters).'); return false; }
                    if (r.length > 500)     { Swal.showValidationMessage('Reason must be 500 characters or fewer.'); return false; }
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

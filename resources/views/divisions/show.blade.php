<x-layout
    :title="$division->name"
    :page-title="$division->name"
    :page-subtitle="$department->name . ' · ' . $section->name"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                       'url' => route('home')],
    ['name' => 'Departments',                'url' => route('departments.index')],
    ['name' => $department->levelLabel(),    'url' => null],
    ['name' => $department->name,            'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $section->name,               'url' => route('departments.sections.show', [$department->levelAlias(), $department, $section])],
    ['name' => $division->name,              'url' => null],
]" />

@php $pageData = [
    'storeUrl' => route('documents.store'),
    'csrfToken' => csrf_token(),
    'csrfTokenUrl' => route('documents.csrf-token'),
    'parentOptions' => $parentOptions,
    'folderStoreUrl' => route('departments.sections.divisions.folders.store', [$department->levelAlias(), $department, $section, $division]),
]; @endphp
<script id="page-data" type="application/json">@json($pageData)</script>
<script src="{{ asset('js/resilient-upload.js') }}"></script>

{{-- ── Division header ─────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-6 flex-wrap">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-teal-500/10 dark:bg-teal-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-layout-sidebar text-teal-500 dark:text-teal-400 text-xl"></i>
        </div>
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $division->name }}</h2>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <a href="{{ route('departments.show', [$department->levelAlias(), $department]) }}"
                   class="text-xs text-slate-500 dark:text-slate-400 hover:text-teal-600 dark:hover:text-teal-400 transition-colors">
                    {{ $department->name }}
                </a>
                <span class="text-slate-300 dark:text-slate-600">›</span>
                <a href="{{ route('departments.sections.show', [$department->levelAlias(), $department, $section]) }}"
                   class="text-xs text-slate-500 dark:text-slate-400 hover:text-teal-600 dark:hover:text-teal-400 transition-colors">
                    {{ $section->name }}
                </a>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $totalCount }} {{ Str::plural('document', $totalCount) }}</span>
            </div>
            @if($division->description)
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $division->description }}</p>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2 flex-wrap justify-end w-full sm:w-auto">
        <a href="{{ route('departments.sections.divisions.download', [$department->levelAlias(), $department, $section, $division]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-emerald-400 dark:hover:border-emerald-500 text-slate-600 dark:text-slate-300 hover:text-emerald-600 dark:hover:text-emerald-400 text-sm font-medium px-3 py-2 rounded-lg transition-all"
           title="Download all markdown as ZIP">
            <i class="ti ti-file-zip text-base"></i>
            <span class="hidden sm:inline">Download ZIP</span>
        </a>
        @auth
        @if(auth()->user()->canUploadTo($division))
        <button type="button"
                onclick="document.getElementById('modal-upload').style.display='block'"
                class="inline-flex items-center gap-1.5 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-upload text-base"></i>
            <span class="hidden sm:inline">Upload Document</span>
        </button>
        @endif
        @endauth
        @auth
        @if(auth()->user()->canUploadTo($division))
        <a href="{{ route('departments.sections.divisions.folders.create', [$department->levelAlias(), $department, $section, $division]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-cyan-400 dark:hover:border-cyan-500 text-slate-600 dark:text-slate-300 hover:text-cyan-600 dark:hover:text-cyan-400 text-sm font-medium px-3 py-2 rounded-lg transition-all"
           title="Add Folder">
            <i class="ti ti-folder-plus text-base"></i>
            <span class="hidden sm:inline">Add Folder</span>
        </a>
        @endif
        @endauth
        @auth @if(auth()->user()->isAdmin())
        <a href="{{ route('departments.sections.divisions.edit', [$department->levelAlias(), $department, $section, $division]) }}"
           class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-teal-400 dark:hover:border-teal-500 text-slate-600 dark:text-slate-300 hover:text-teal-600 dark:hover:text-teal-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-pencil text-base"></i>
        </a>
        <button type="button" id="delete-division-btn"
                class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-trash text-base"></i>
        </button>
        <form id="delete-division-form" method="POST"
              action="{{ route('departments.sections.divisions.destroy', [$department->levelAlias(), $department, $section, $division]) }}"
              style="display:none">
            @csrf @method('DELETE')
        </form>
        @endif @endauth
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
                <i class="ti ti-file-upload text-teal-500 text-lg"></i>
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Upload Document</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $division->name }}</span>
            </div>
            <button type="button" onclick="window.__closeUploadModal()"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <div class="flex flex-col lg:flex-row flex-1 min-h-0">
            {{-- Left: file drop + queue --}}
            <div class="lg:w-1/2 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-700 flex flex-col p-4 gap-3">
                <div id="div-drop-zone" onclick="document.getElementById('div-file').click()" style="cursor:pointer"
                     class="rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-teal-400 dark:hover:border-teal-500 transition-colors flex flex-col items-center justify-center gap-1.5 py-5 px-4 text-center flex-shrink-0">
                    <i class="ti ti-cloud-upload text-2xl text-slate-300 dark:text-slate-600"></i>
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag files here</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 300 MB each · multiple files supported</p>
                    <input type="file" id="div-file" name="file" multiple
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif,.svg"
                           style="display:none">
                </div>
                <button type="button" id="div-folder-btn" onclick="document.getElementById('div-folder-input').click()"
                        class="text-xs text-teal-600 dark:text-teal-400 hover:underline flex items-center justify-center gap-1 flex-shrink-0">
                    <i class="ti ti-folder-up"></i> Or upload a whole folder
                </button>
                <input type="file" id="div-folder-input" webkitdirectory directory multiple style="display:none">
                <p class="text-[11px] text-slate-400 dark:text-slate-500 text-center -mt-1">The picked folder is created here (reusing one with the same name if it exists). Everything inside it, at any depth, goes into it.</p>
                <div id="div-queue-wrap" class="flex-1 overflow-hidden flex flex-col min-h-0" style="display:none">
                    <div class="flex items-center justify-between mb-1.5 flex-shrink-0">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                            Queue &nbsp;<span id="div-queue-count" class="text-teal-500 font-bold normal-case">0</span>
                        </p>
                        <button type="button" id="div-btn-clear" class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">Clear all</button>
                    </div>
                    <div id="div-queue" class="overflow-y-auto flex flex-col gap-1.5" style="max-height:240px"></div>
                </div>
                <p id="div-queue-hint" class="text-xs text-slate-400 dark:text-slate-500 text-center py-1">Select one or more files — each gets its own title</p>
            </div>
            {{-- Right: form --}}
            <div class="lg:w-1/2 p-6 flex flex-col gap-4">
                <form id="div-form" method="POST" action="{{ route('documents.store') }}" novalidate enctype="multipart/form-data" class="flex flex-col gap-4 flex-1">
                    @csrf
                    <input type="hidden" name="section_id" value="{{ $section->id }}">
                    <input type="hidden" name="division_id" value="{{ $division->id }}">

                    <div>
                        <label for="div-type" class="field-label">Document Type <span class="text-red-500">*</span></label>
                        <select id="div-type" name="document_type" class="field-input">
                            <option value="">— Select type —</option>
                            @foreach(\App\Models\Document::DOCUMENT_TYPES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p id="div-err-type" class="field-err-msg" style="display:none"></p>
                    </div>

                    <div>
                        <label for="div-title" class="field-label">Title <span class="text-slate-400 font-normal">(optional — auto-filled from filename)</span></label>
                        <input type="text" id="div-title" class="field-input" placeholder="Auto-filled from filename" maxlength="255">
                        <p id="div-title-hint" class="text-xs text-slate-400 dark:text-slate-500 mt-1" style="display:none">Multiple files queued — edit each one's title in the queue on the left.</p>
                    </div>

                    {{-- Amendment parent (optional, cross-division allowed) --}}
                    <div>
                        <label for="div-parent" class="field-label">Amends Previous Document <span class="text-slate-400 font-normal">(optional)</span></label>
                        <select id="div-parent" name="parent_id" class="field-input">
                            <option value="">— None —</option>
                        </select>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Cross-division amendments are allowed — all root documents in this section are listed.</p>
                    </div>

                    {{-- Amendment number + effective date --}}
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="div-amendment-number" class="field-label">Amendment No. <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="div-amendment-number" name="amendment_number" min="1" max="999"
                                   placeholder="e.g. 5" class="field-input">
                        </div>
                        <div>
                            <label for="div-effective-year" class="field-label">Effective Year <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="div-effective-year" name="effective_year" min="1900" max="2099"
                                   placeholder="e.g. 2019" class="field-input">
                        </div>
                        <div>
                            <label for="div-effective-month" class="field-label">Month <span class="text-slate-400 font-normal">(optional)</span></label>
                            <select id="div-effective-month" name="effective_month" class="field-input">
                                <option value="">—</option>
                                @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                                <option value="{{ $mi + 1 }}">{{ $mn }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="div-effective-day" class="field-label">Day <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="div-effective-day" name="effective_day" min="1" max="31"
                                   placeholder="1–31" class="field-input">
                        </div>
                    </div>

                    <div>
                        <label class="field-label">Visibility</label>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="public" @checked($division->visibility !== 'authenticated') class="text-teal-600 focus:ring-teal-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-world text-sm text-green-500"></i> Public</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="authenticated" @checked($division->visibility === 'authenticated') class="text-teal-600 focus:ring-teal-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only</span>
                            </label>
                        </div>
                        @if($division->visibility === 'authenticated')
                        <p class="mt-1 text-xs text-amber-600 dark:text-amber-400 flex items-center gap-1">
                            <i class="ti ti-alert-triangle text-sm"></i> This division is Authenticated Only, so documents default to the same. Marking one Public here has no real effect — guests still can't reach it while the division itself is restricted.
                        </p>
                        @endif
                    </div>

                    <div>
                        <label for="div-language" class="field-label">Language</label>
                        <select id="div-language" name="language" class="field-input">
                            @foreach(\App\Models\Document::LANGUAGES as $key => $label)
                            <option value="{{ $key }}" @selected($key === 'english')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-800/60 rounded-lg px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Saving to</p>
                        <p class="text-xs text-teal-600 dark:text-teal-400 font-medium">
                            @php
                                $vaultCrumbs = array_filter([
                                    Str::title(str_replace('_', ' ', $department->level)),
                                    $department->name,
                                    $section->wing ? Str::title(str_replace('_', ' ', $section->wing)) : null,
                                    $section->name,
                                    'Divisions',
                                    $division->name,
                                ]);
                            @endphp
                            {{ implode(' › ', $vaultCrumbs) }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2 mt-auto pt-2">
                        <div class="flex items-center gap-3">
                            <button type="submit" id="div-btn-submit"
                                    class="inline-flex items-center gap-2 bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                                <i class="ti ti-upload"></i>
                                <span id="div-btn-label">Upload</span>
                            </button>
                            <span id="div-upload-status" class="text-xs text-slate-400 dark:text-slate-500"></span>
                        </div>
                        <div id="div-progress-track" style="display:none" class="h-1.5 w-full rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden">
                            <div id="div-progress-bar" class="h-full bg-teal-500 transition-all" style="width:0%"></div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endauth

{{-- ── Folders (Patravali) ──────────────────────────────────────────────────── --}}
@if($folders->isNotEmpty())
<div class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2">
            <i class="ti ti-folder-star text-cyan-500 dark:text-cyan-400"></i>
            Folders
            <span class="text-xs font-normal text-slate-400 dark:text-slate-500 normal-case">({{ $folders->count() }})</span>
        </h3>
        @auth @if(auth()->user()->canUploadTo($division))
        <a href="{{ route('departments.sections.divisions.folders.create', [$department->levelAlias(), $department, $section, $division]) }}"
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
            <a href="{{ route('departments.sections.divisions.folders.download', [$department->levelAlias(), $department, $section, $division, $fld]) }}"
               class="relative z-10 text-slate-400 dark:text-slate-500 hover:text-cyan-500 dark:hover:text-cyan-400 transition-colors flex-shrink-0 mt-1"
               title="Download as ZIP">
                <i class="ti ti-file-zip text-base"></i>
            </a>
            <i class="ti ti-chevron-right text-slate-300 dark:text-slate-600 group-hover:text-cyan-400 transition-colors flex-shrink-0 mt-1 text-sm"></i>
            <a href="{{ route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $fld]) }}"
               class="absolute inset-0" aria-label="Open {{ $fld->name }}"></a>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- ── Document hierarchy ────────────────────────────────────────────────────── --}}
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
        <form method="GET" action="{{ route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]) }}"
              class="flex items-center gap-2 flex-wrap">
            <select name="sort" onchange="this.form.submit()"
                    class="text-xs border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-teal-500">
                <option value="amendment_number_desc" @selected($sort === 'amendment_number_desc')>Amendment # ↓ newest first</option>
                <option value="amendment_number_asc"  @selected($sort === 'amendment_number_asc')>Amendment # ↑ oldest first</option>
                <option value="year_desc"             @selected($sort === 'year_desc')>Year ↓</option>
                <option value="year_asc"              @selected($sort === 'year_asc')>Year ↑</option>
                <option value="uploaded_desc"         @selected($sort === 'uploaded_desc')>Uploaded ↓</option>
                <option value="uploaded_asc"          @selected($sort === 'uploaded_asc')>Uploaded ↑</option>
            </select>
            @if($availableYears->isNotEmpty())
            <select name="year" onchange="this.form.submit()"
                    class="text-xs border border-slate-200 dark:border-slate-700 rounded-lg px-2.5 py-1.5 bg-white dark:bg-slate-800 text-slate-600 dark:text-slate-300 focus:outline-none focus:ring-2 focus:ring-teal-500">
                <option value="">All years</option>
                @foreach($availableYears as $yr)
                <option value="{{ $yr }}" @selected($filterYear == $yr)>{{ $yr }}</option>
                @endforeach
            </select>
            @endif
            @if($filterYear)
            <a href="{{ route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]) }}"
               class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors" title="Clear filter">
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
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($rootDocuments as $doc)
        @include('divisions._doc_row', ['doc' => $doc, 'department' => $department, 'section' => $section, 'division' => $division, 'isAmendment' => false])
        @foreach($doc->amendments as $amendment)
        @include('divisions._doc_row', ['doc' => $amendment, 'department' => $department, 'section' => $section, 'division' => $division, 'isAmendment' => true])
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
            uploading: 'bg-teal-100 dark:bg-teal-900/30 text-teal-600 dark:text-teal-400',
            done:      'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
            error:     'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
        };
        return base + (map[state] || map.pending);
    }
    function showErr(id, msg) { const el = document.getElementById(id); if (el) { el.textContent = msg; el.style.display = 'block'; } }
    function clearErr(id)     { const el = document.getElementById(id); if (el) el.style.display = 'none'; }

    const fileInput = document.getElementById('div-file');
    const dropZone  = document.getElementById('div-drop-zone');
    const queueList = document.getElementById('div-queue');
    const countEl   = document.getElementById('div-queue-count');
    const queueWrap = document.getElementById('div-queue-wrap');
    const queueHint = document.getElementById('div-queue-hint');
    const clearBtn  = document.getElementById('div-btn-clear');
    const form      = document.getElementById('div-form');
    const btnSubmit = document.getElementById('div-btn-submit');
    const btnLabel  = document.getElementById('div-btn-label');
    const statusEl  = document.getElementById('div-upload-status');
    const fldTitle     = document.getElementById('div-title');
    const fldTitleHint = document.getElementById('div-title-hint');
    const progressTrack = document.getElementById('div-progress-track');
    const progressBar   = document.getElementById('div-progress-bar');

    if (!fileInput || !form) return;

    // Persists the picked queue in IndexedDB so a reload/crash mid-batch doesn't force
    // re-picking all the files — see public/js/resilient-upload.js.
    const queue = new ResilientUpload.Queue('division-{{ $division->id }}');

    fldTitle.addEventListener('input', () => {
        if (uploadFiles.length === 1) uploadFiles[0].titleInput.value = fldTitle.value;
    });

    // Populate parent options from server-side data island
    const parentSel = document.getElementById('div-parent');
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
            titleInput.className = 'w-full text-xs font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded-md px-2 py-1 focus:border-teal-400 focus:ring-1 focus:ring-teal-400 outline-none';
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
    // The picked folder is created here as a real Folder. Everything inside it, at any depth,
    // goes into that one folder (only one level of subfolder nesting exists app-wide, so a
    // folder picked at division level always becomes a root folder here).
    const folderInput = document.getElementById('div-folder-input');
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

    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.style.borderColor = '#0d9488'; });
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

        const typeEl = document.getElementById('div-type');
        if (!typeEl || !typeEl.value) { showErr('div-err-type', 'Select a document type.'); return; }
        clearErr('div-err-type');

        const contextSectionId  = form.querySelector('[name="section_id"]');
        const contextDivisionId = form.querySelector('[name="division_id"]');
        const parentInput       = form.querySelector('[name="parent_id"]');
        const visibility        = form.querySelector('[name="visibility"]:checked')?.value || 'public';

        @if($division->visibility === 'authenticated')
        if (visibility === 'public') {
            const { isConfirmed } = await Swal.fire({
                icon: 'warning',
                title: 'This division is Authenticated Only',
                text: 'Marking these documents Public won\'t make them visible to guests — the division\'s own restriction still applies. Continue anyway?',
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

            const fd = new FormData();
            if (item.folderId) {
                fd.append('folder_id', item.folderId);
            } else {
                if (contextSectionId)  fd.append('section_id',  contextSectionId.value);
                if (contextDivisionId) fd.append('division_id', contextDivisionId.value);
            }
            fd.append('title', title);
            fd.append('document_type', typeEl.value);
            fd.append('visibility', visibility);
            fd.append('language', language);
            if (parentInput && parentInput.value) fd.append('parent_id',        parentInput.value);
            if (amendmentNumber)                  fd.append('amendment_number', amendmentNumber);
            if (effectiveYear)                    fd.append('effective_year',   effectiveYear);
            if (effectiveMonth)                   fd.append('effective_month',  effectiveMonth);
            if (effectiveDay)                     fd.append('effective_day',    effectiveDay);
            fd.append('file', item.file);

            // Handles a stale CSRF token (419) and a hit on the 20/min upload throttle (429)
            // transparently — see public/js/resilient-upload.js.
            const { ok, status, json } = await ResilientUpload.request(page.storeUrl, fd, page);
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
    const deleteBtn = document.getElementById('delete-division-btn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            const isDark = document.documentElement.classList.contains('dark');
            const docCount = {{ $totalCount }};
            Swal.fire({
                title: 'Delete Division?',
                html: '<p class="text-sm mb-2">You are about to delete <strong>{{ e($division->name) }}</strong>.</p>'
                    + (docCount > 0
                        ? '<p class="text-sm text-red-500">This will also move <strong>' + docCount + ' document(s)</strong> to trash.</p>'
                        : '<p class="text-sm text-gray-400">No documents are associated with this division.</p>'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                background: isDark ? '#1e293b' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#0f172a',
            }).then(function (result) {
                if (result.isConfirmed) {
                    document.getElementById('delete-division-form').submit();
                }
            });
        });
    }
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

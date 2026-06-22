<x-layout
    title="{{ $ruleSet->name }}"
    page-title="{{ $ruleSet->name }}"
    page-subtitle="{{ $department->name }} · Rules &amp; Regulations"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $ruleSet->name,            'url' => null],
]" />

@php $pageData = ['storeUrl' => route('documents.store'), 'csrfToken' => csrf_token(), 'parentOptions' => $parentOptions]; @endphp
<script id="page-data" type="application/json">@json($pageData)</script>

{{-- ── Rule set header ─────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-6">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-indigo-500/10 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-book text-indigo-500 dark:text-indigo-400 text-xl"></i>
        </div>
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $ruleSet->name }}</h2>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <a href="{{ route('departments.show', [$department->levelAlias(), $department]) }}"
                   class="text-xs text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                    {{ $department->name }}
                </a>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">{{ $totalCount }} {{ Str::plural('document', $totalCount) }}</span>
            </div>
            @if($ruleSet->description)
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $ruleSet->description }}</p>
            @endif
        </div>
    </div>
    <div class="flex items-center gap-2">
        @auth
        <button type="button" onclick="openModal('document')"
                class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
            <i class="ti ti-upload text-base"></i>
            <span class="hidden sm:inline">Upload Document</span>
        </button>
        <button type="button" onclick="openModal('amendment')"
                class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-git-merge text-base"></i>
            <span class="hidden sm:inline">Upload Amendment</span>
        </button>
        @endauth
        @auth @if(auth()->user()->isAdmin())
        <a href="{{ route('departments.rules.edit', [$department->levelAlias(), $department, $ruleSet]) }}"
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
     onclick="if(event.target===this)document.getElementById('upload-modal').style.display='none'">

    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(960px,95vw);max-height:90vh;overflow-y:auto"
         class="bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col">

        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <div class="flex items-center gap-3">
                <i id="modal-icon" class="ti ti-file-upload text-indigo-500 text-lg"></i>
                <span id="modal-heading" class="text-sm font-semibold text-slate-800 dark:text-slate-100">Upload Document</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $ruleSet->name }}</span>
            </div>
            <button type="button" onclick="document.getElementById('upload-modal').style.display='none'"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <i class="ti ti-x"></i>
            </button>
        </div>

        {{-- Mode toggle --}}
        <div class="px-6 pt-4 pb-0 flex-shrink-0">
            <div class="inline-flex rounded-lg border border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 p-0.5 gap-0.5">
                <button type="button" id="mode-document"
                        onclick="setMode('document')"
                        class="mode-btn px-4 py-1.5 rounded-md text-xs font-medium transition-colors">
                    <i class="ti ti-file-text mr-1"></i>Rule Document
                </button>
                <button type="button" id="mode-amendment"
                        onclick="setMode('amendment')"
                        class="mode-btn px-4 py-1.5 rounded-md text-xs font-medium transition-colors">
                    <i class="ti ti-git-merge mr-1"></i>Amendment
                </button>
            </div>
        </div>

        <div class="flex flex-col lg:flex-row flex-1 min-h-0">

            {{-- Left: file drop + queue --}}
            <div class="lg:w-1/2 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-700 flex flex-col p-4 gap-3">
                <div id="drop-zone"
                     onclick="document.getElementById('doc-file').click()"
                     style="cursor:pointer"
                     class="rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors flex flex-col items-center justify-center gap-1.5 py-5 px-4 text-center flex-shrink-0">
                    <i class="ti ti-cloud-upload text-2xl text-slate-300 dark:text-slate-600"></i>
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag files here</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 50 MB each · multiple files supported</p>
                    <input type="file" id="doc-file" name="file" multiple
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif,.svg"
                           style="display:none">
                </div>
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
                    <input type="hidden" name="rule_set_id" value="{{ $ruleSet->id }}">

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

                    {{-- Visibility --}}
                    <div>
                        <label class="field-label">Visibility</label>
                        <div class="flex gap-3 mt-1">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="public" checked
                                       class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1">
                                    <i class="ti ti-world text-sm text-green-500"></i> Public
                                </span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" name="visibility" value="authenticated"
                                       class="text-indigo-600 focus:ring-indigo-500">
                                <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1">
                                    <i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only
                                </span>
                            </label>
                        </div>
                    </div>

                    {{-- Amends (shown only in amendment mode) --}}
                    <div id="parent-wrap" style="display:none">
                        <label for="doc-parent" class="field-label">
                            Amends <span class="text-red-500">*</span>
                        </label>
                        <select id="doc-parent" name="parent_id" class="field-input">
                            <option value="">— Select document being amended —</option>
                        </select>
                        <p id="err-parent" class="field-err-msg" style="display:none"></p>
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Select the base rule or earlier document this amendment formally modifies.</p>
                    </div>

                    <div class="bg-slate-50 dark:bg-slate-800/60 rounded-lg px-4 py-3">
                        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Saving to</p>
                        <p class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">
                            {{ Str::title(str_replace('_', ' ', $department->level)) }} › {{ $department->name }} › Rules › {{ $ruleSet->name }}
                        </p>
                    </div>

                    <div class="flex items-center gap-3 mt-auto pt-2">
                        <button type="submit" id="btn-submit"
                                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                            <i class="ti ti-upload"></i>
                            <span id="btn-submit-label">Upload</span>
                        </button>
                        <span id="upload-status" class="text-xs text-slate-400 dark:text-slate-500"></span>
                    </div>
                </form>

            </div>
        </div>
    </div>
</div>
@endauth

{{-- ── Document hierarchy ────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Amendments &amp; Documents</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                {{ $totalCount }} {{ Str::plural('document', $totalCount) }}
                @guest · public only @endguest
            </p>
        </div>
    </div>

    @if($rootDocuments->isEmpty() && $totalCount === 0)
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-files text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm text-slate-500 dark:text-slate-400">No documents yet</p>
        @auth
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Use the buttons above to add documents or amendments.</p>
        @else
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Documents will appear here once available.</p>
        @endauth
    </div>
    @else
    <div class="divide-y divide-slate-100 dark:divide-slate-700/60">
        @foreach($rootDocuments as $doc)

        {{-- Root document row --}}
        @include('rule_sets._doc_row', ['doc' => $doc, 'department' => $department, 'ruleSet' => $ruleSet, 'isAmendment' => false])

        {{-- Amendments indented beneath --}}
        @foreach($doc->amendments as $amendment)
        @include('rule_sets._doc_row', ['doc' => $amendment, 'department' => $department, 'ruleSet' => $ruleSet, 'isAmendment' => true])
        @endforeach

        @endforeach
    </div>
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
    } catch (e) { console.error('page-data parse failed', e); return; }

    const form         = document.getElementById('upload-form');
    const fileInput    = document.getElementById('doc-file');
    const dropZone     = document.getElementById('drop-zone');
    const typeSelect   = document.getElementById('doc-type');
    const parentWrap   = document.getElementById('parent-wrap');
    const parentSelect = document.getElementById('doc-parent');
    const btnSubmit    = document.getElementById('btn-submit');
    const btnLabel     = document.getElementById('btn-submit-label');
    const statusEl     = document.getElementById('upload-status');
    const queueWrap    = document.getElementById('file-queue-wrap');
    const queueList    = document.getElementById('file-queue');
    const queueCountEl = document.getElementById('queue-count');
    const queueHint    = document.getElementById('queue-empty-hint');
    const btnClear     = document.getElementById('btn-clear-queue');
    const modalHeading = document.getElementById('modal-heading');
    const modalIcon    = document.getElementById('modal-icon');

    // Populate parent dropdown
    if (parentSelect && page.parentOptions && page.parentOptions.length > 0) {
        page.parentOptions.forEach(function (opt) {
            const el = document.createElement('option');
            el.value = opt.id;
            el.textContent = opt.title + ' (' + opt.date + ')';
            parentSelect.appendChild(el);
        });
    }

    // Mode management
    let currentMode = 'document';

    // Separate options so we can show/hide by mode
    const typeOptions = Array.from(typeSelect.options); // includes the blank "— Select type —"

    function applyTypeOptions(mode) {
        // Remove all current options
        typeSelect.innerHTML = '';
        typeOptions.forEach(function (opt) {
            if (!opt.value) {
                // Always keep the placeholder
                typeSelect.appendChild(opt.cloneNode(true));
                return;
            }
            if (mode === 'amendment') {
                // Amendment mode: only rule_amendment is valid
                if (opt.value === 'rule_amendment') typeSelect.appendChild(opt.cloneNode(true));
            } else {
                // Document mode: everything except rule_amendment
                if (opt.value !== 'rule_amendment') typeSelect.appendChild(opt.cloneNode(true));
            }
        });
        // Auto-select the only option when there is just one real choice
        if (typeSelect.options.length === 2) {
            typeSelect.selectedIndex = 1;
        } else {
            typeSelect.value = '';
        }
    }

    window.setMode = function (mode) {
        currentMode = mode;

        const btnDoc   = document.getElementById('mode-document');
        const btnAmend = document.getElementById('mode-amendment');
        const activeClass = 'bg-white dark:bg-slate-900 text-indigo-600 dark:text-indigo-400 shadow-sm';
        const idleClass   = 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200';

        if (mode === 'amendment') {
            btnAmend.className = 'mode-btn px-4 py-1.5 rounded-md text-xs font-medium transition-colors ' + activeClass;
            btnDoc.className   = 'mode-btn px-4 py-1.5 rounded-md text-xs font-medium transition-colors ' + idleClass;
            parentWrap.style.display = '';
            modalHeading.textContent = 'Upload Amendment';
            modalIcon.className = 'ti ti-git-merge text-amber-500 text-lg';
        } else {
            btnDoc.className   = 'mode-btn px-4 py-1.5 rounded-md text-xs font-medium transition-colors ' + activeClass;
            btnAmend.className = 'mode-btn px-4 py-1.5 rounded-md text-xs font-medium transition-colors ' + idleClass;
            parentWrap.style.display = 'none';
            parentSelect.value = '';
            modalHeading.textContent = 'Upload Rule Document';
            modalIcon.className = 'ti ti-file-upload text-indigo-500 text-lg';
        }

        applyTypeOptions(mode);
    };

    window.openModal = function (mode) {
        modal.style.display = 'block';
        setMode(mode || 'document');
    };

    // Init default state
    setMode('document');

    let uploadFiles = [];
    let isUploading = false;

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
        const labels = { pending: 'Pending', uploading: 'Uploading…', done: '✓ Done' };
        item.statusBadge.textContent = state === 'error' ? ('✗ ' + (msg || 'Error')) : (labels[state] || state);
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
        btnLabel.textContent = n > 1 ? ('Upload ' + n + ' files') : 'Upload';
        btnSubmit.disabled = n === 0 || isUploading;
    }

    function addFiles(files) {
        Array.from(files).forEach(file => {
            const row = document.createElement('div');
            row.className = 'queue-row flex items-start gap-2 p-2 rounded-lg bg-slate-50 dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700';

            const icon = document.createElement('i');
            icon.className = 'ti ti-file-text text-slate-400 dark:text-slate-500 flex-shrink-0 text-sm mt-1.5';

            const meta = document.createElement('div');
            meta.className = 'flex-1 min-w-0 flex flex-col gap-0.5';

            const titleInput = document.createElement('input');
            titleInput.type = 'text';
            titleInput.className = 'w-full text-xs font-medium text-slate-700 dark:text-slate-200 bg-transparent border-b border-slate-200 dark:border-slate-600 focus:border-indigo-400 outline-none pb-0.5';
            titleInput.value = fileToTitle(file.name);
            titleInput.placeholder = 'Document title';
            titleInput.maxLength = 255;

            const sizeLine = document.createElement('p');
            sizeLine.className = 'text-[10px] text-slate-400 dark:text-slate-500 truncate';
            sizeLine.textContent = (file.size / 1048576).toFixed(1) + ' MB · ' + file.name;

            meta.appendChild(titleInput);
            meta.appendChild(sizeLine);

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

            const item = { file, titleInput, statusBadge, row };
            uploadFiles.push(item);
            removeBtn.addEventListener('click', () => {
                if (isUploading) return;
                row.remove();
                uploadFiles.splice(uploadFiles.indexOf(item), 1);
                syncUI();
            });
        });
        syncUI();
    }

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

    btnClear.addEventListener('click', () => {
        if (isUploading) return;
        uploadFiles = [];
        queueList.innerHTML = '';
        syncUI();
    });

    form.addEventListener('submit', async e => {
        e.preventDefault();
        if (isUploading || uploadFiles.length === 0) return;

        clearErr('err-type');
        clearErr('err-parent');

        if (!typeSelect.value) {
            showErr('err-type', 'Select a document type before uploading.');
            return;
        }
        if (currentMode === 'amendment' && !parentSelect.value) {
            showErr('err-parent', 'Select the document this amendment modifies.');
            return;
        }

        const type         = typeSelect.value;
        const visibility   = form.querySelector('[name="visibility"]:checked')?.value || 'public';
        const parentId     = (currentMode === 'amendment' && parentSelect) ? (parentSelect.value || '') : '';
        const contextInput = form.querySelector('[name="rule_set_id"]');

        isUploading = true;
        btnSubmit.disabled = true;
        statusEl.textContent = '';
        btnSubmit.onclick = null;

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
            statusEl.textContent = 'Uploading ' + (i + 1) + ' of ' + uploadFiles.length + '…';

            try {
                const fd = new FormData();
                fd.append('_token', page.csrfToken);
                if (contextInput) fd.append(contextInput.name, contextInput.value);
                fd.append('title', title);
                fd.append('document_type', type);
                fd.append('visibility', visibility);
                if (parentId) fd.append('parent_id', parentId);
                fd.append('file', item.file);

                const res = await fetch(page.storeUrl, {
                    method: 'POST',
                    headers: {
                        'Accept':           'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN':     page.csrfToken,
                    },
                    body: fd,
                });

                const ct = res.headers.get('Content-Type') || '';
                if (!ct.includes('application/json')) {
                    if (res.status === 419) throw new Error('Session expired — refresh and try again');
                    throw new Error('HTTP ' + res.status);
                }

                const json = await res.json();
                if (!res.ok) {
                    const msg = json.errors
                        ? Object.values(json.errors).flat()[0]
                        : (json.message || 'Upload failed');
                    setRowStatus(item, 'error', msg);
                    errorCount++;
                    continue;
                }

                setRowStatus(item, 'done');
                doneCount++;
                lastRedirect = json.redirect;

            } catch (err) {
                setRowStatus(item, 'error', err.message);
                errorCount++;
                console.error('Upload error:', item.file.name, err);
            }
        }

        isUploading = false;

        if (errorCount === 0 && lastRedirect) {
            window.location.href = lastRedirect;
            return;
        }

        if (doneCount > 0 && lastRedirect) {
            statusEl.textContent = doneCount + ' uploaded, ' + errorCount + ' failed — fix errors or continue.';
            btnSubmit.disabled = false;
            btnLabel.textContent = 'Go to page';
            btnSubmit.onclick = ev => { ev.preventDefault(); window.location.href = lastRedirect; };
        } else {
            statusEl.textContent = doneCount + ' uploaded, ' + errorCount + ' failed.';
            btnSubmit.disabled = false;
            btnLabel.textContent = errorCount > 0 ? ('Retry (' + errorCount + ' failed)') : 'Upload';
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

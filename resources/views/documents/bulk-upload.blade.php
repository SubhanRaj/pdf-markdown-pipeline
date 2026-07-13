<x-layout
    title="Bulk Upload & Convert"
    page-title="Bulk Upload & Convert"
    page-subtitle="Upload multiple documents to any department/section/division/folder/rule set, then auto-convert to Markdown"
>

<x-breadcrumb :items="[
    ['name' => 'Home',              'url' => route('home')],
    ['name' => 'Bulk Upload & Convert', 'url' => null],
]" />

{{-- Data island — the whole scoped tree is computed once server-side (User::uploadScope()),
     so the picker below never offers a context the user isn't allowed to upload to. --}}
@php $pageData = ['tree' => $tree, 'storeUrl' => $storeUrl, 'csrfToken' => csrf_token(), 'convertUrlBase' => url('/documents')]; @endphp
<script id="page-data" type="application/json">@json($pageData)</script>

@if(empty($tree))
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-6 py-14 text-center">
    <i class="ti ti-upload-off text-3xl text-slate-300 dark:text-slate-600"></i>
    <p class="mt-3 text-sm font-semibold text-slate-500 dark:text-slate-400">You don't have upload access to any department, section, or division.</p>
    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Contact an administrator to be assigned an upload scope.</p>
</div>
@else

<div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

    {{-- ── Left: destination picker + shared fields ─────────────────────────── --}}
    <div class="lg:col-span-2 space-y-4">

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
            <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-3">1. Destination</p>

            <div class="flex gap-2 mb-4" id="context-mode-tabs">
                <button type="button" data-mode="section" class="ctx-tab flex-1 text-xs font-medium px-3 py-2 rounded-lg border transition-colors">
                    <i class="ti ti-folder-open text-sm mr-1"></i> Section / Division / Folder
                </button>
                <button type="button" data-mode="ruleset" class="ctx-tab flex-1 text-xs font-medium px-3 py-2 rounded-lg border transition-colors">
                    <i class="ti ti-gavel text-sm mr-1"></i> Rule Set (Acts &amp; Rules)
                </button>
            </div>

            <div class="space-y-3">
                <div>
                    <label for="pick-department" class="field-label">Department</label>
                    <select id="pick-department" class="field-input"></select>
                </div>

                <div id="section-path" class="space-y-3">
                    <div>
                        <label for="pick-section" class="field-label">Section</label>
                        <select id="pick-section" class="field-input"></select>
                    </div>
                    <div>
                        <label for="pick-division" class="field-label">Division <span class="text-slate-400 font-normal">(optional — direct in section if unset)</span></label>
                        <select id="pick-division" class="field-input">
                            <option value="">— Direct in section —</option>
                        </select>
                    </div>
                    <div>
                        <label for="pick-folder" class="field-label">Folder / Patravali <span class="text-slate-400 font-normal">(optional)</span></label>
                        <select id="pick-folder" class="field-input">
                            <option value="">— No folder (direct) —</option>
                        </select>
                    </div>
                </div>

                <div id="ruleset-path" class="space-y-3" style="display:none">
                    <div>
                        <label for="pick-ruleset" class="field-label">Rule Set</label>
                        <select id="pick-ruleset" class="field-input"></select>
                    </div>
                    <p id="ruleset-hint" class="text-xs text-slate-400 dark:text-slate-500"></p>
                </div>

                <div class="bg-slate-50 dark:bg-slate-800/60 rounded-lg px-4 py-3">
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Saving to</p>
                    <p id="vault-preview" class="text-xs text-indigo-600 dark:text-indigo-400 font-medium">— select a destination —</p>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 space-y-4">
            <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">2. Shared Fields <span class="normal-case font-normal text-slate-400">(applied to every file in the queue)</span></p>

            <div>
                <label for="doc-type" class="field-label">Document Type <span class="text-red-500">*</span></label>
                <select id="doc-type" class="field-input">
                    <option value="">— Select type —</option>
                    @foreach(\App\Models\Document::DOCUMENT_TYPES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p id="err-type" class="field-err-msg" style="display:none"></p>
            </div>

            <div>
                <label class="field-label">Visibility</label>
                <div class="flex gap-3 mt-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="visibility" value="public" checked class="text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1">
                            <i class="ti ti-world text-sm text-green-500"></i> Public
                        </span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="visibility" value="authenticated" class="text-indigo-600 focus:ring-indigo-500">
                        <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1">
                            <i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only
                        </span>
                    </label>
                </div>
            </div>

            <div>
                <label for="doc-parent" class="field-label">Amends Previous Document <span class="text-slate-400 font-normal">(optional — makes this an amendment)</span></label>
                <select id="doc-parent" class="field-input">
                    <option value="">— None (original document) —</option>
                </select>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Populated from root documents already in the selected destination.</p>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="doc-amendment-number" class="field-label">Amendment No. <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="number" id="doc-amendment-number" min="1" max="999" placeholder="e.g. 5" class="field-input">
                </div>
                <div>
                    <label for="doc-effective-year" class="field-label">Effective Year <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="number" id="doc-effective-year" min="1900" max="2099" placeholder="e.g. 2019" class="field-input">
                </div>
                <div>
                    <label for="doc-effective-month" class="field-label">Month <span class="text-slate-400 font-normal">(optional)</span></label>
                    <select id="doc-effective-month" class="field-input">
                        <option value="">—</option>
                        @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                        <option value="{{ $mi + 1 }}">{{ $mn }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="doc-effective-day" class="field-label">Day <span class="text-slate-400 font-normal">(optional)</span></label>
                    <input type="number" id="doc-effective-day" min="1" max="31" placeholder="1–31" class="field-input">
                </div>
            </div>

            <label class="flex items-center gap-2 cursor-pointer pt-1 border-t border-slate-100 dark:border-slate-700">
                <input type="checkbox" id="auto-convert" checked class="text-indigo-600 focus:ring-indigo-500 rounded">
                <span class="text-sm text-slate-700 dark:text-slate-200">Automatically convert each file to Markdown after upload</span>
            </label>
        </div>
    </div>

    {{-- ── Right: file queue ──────────────────────────────────────────────────── --}}
    <div class="lg:col-span-3">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 flex flex-col gap-3 h-full">
            <p class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">3. Files</p>

            <div id="drop-zone" onclick="document.getElementById('doc-file').click()" style="cursor:pointer"
                 class="rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors flex flex-col items-center justify-center gap-1.5 py-6 px-4 text-center flex-shrink-0">
                <i class="ti ti-cloud-upload text-2xl text-slate-300 dark:text-slate-600"></i>
                <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag files here</p>
                <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 50 MB each · multiple files supported</p>
                <input type="file" id="doc-file" multiple
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif"
                       style="display:none">
            </div>

            <div id="file-queue-wrap" class="flex-1 overflow-hidden flex flex-col min-h-0" style="display:none">
                <div class="flex items-center justify-between mb-1.5 flex-shrink-0">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">
                        Queue &nbsp;<span id="queue-count" class="text-indigo-500 font-bold normal-case">0</span>
                    </p>
                    <button type="button" id="btn-clear-queue" class="text-xs text-slate-400 hover:text-red-500 dark:hover:text-red-400 transition-colors">Clear all</button>
                </div>
                <div id="file-queue" class="overflow-y-auto flex flex-col gap-1.5" style="max-height:480px"></div>
            </div>
            <p id="queue-empty-hint" class="text-xs text-slate-400 dark:text-slate-500 text-center py-1">Select one or more files above — each gets its own editable title</p>

            <div class="flex items-center gap-3 mt-auto pt-3 border-t border-slate-100 dark:border-slate-700">
                <button type="button" id="btn-submit" disabled
                        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                    <i class="ti ti-upload"></i>
                    <span id="btn-submit-label">Upload</span>
                </button>
                <span id="upload-status" class="text-xs text-slate-400 dark:text-slate-500"></span>
            </div>
        </div>
    </div>

</div>
@endif

@push('scripts')
<script>
(function () {
    let page;
    try {
        page = JSON.parse(document.getElementById('page-data').textContent);
    } catch (e) { console.error('page-data JSON parse failed', e); return; }

    if (!page.tree || page.tree.length === 0) return;

    const deptSelect  = document.getElementById('pick-department');
    const modeTabs    = document.querySelectorAll('.ctx-tab');
    const sectionPath = document.getElementById('section-path');
    const rulesetPath = document.getElementById('ruleset-path');
    const sectionSel  = document.getElementById('pick-section');
    const divisionSel = document.getElementById('pick-division');
    const folderSel   = document.getElementById('pick-folder');
    const rulesetSel  = document.getElementById('pick-ruleset');
    const rulesetHint = document.getElementById('ruleset-hint');
    const vaultPreview = document.getElementById('vault-preview');
    const typeSelect   = document.getElementById('doc-type');
    const parentSelect = document.getElementById('doc-parent');
    const autoConvert  = document.getElementById('auto-convert');

    let mode = 'section'; // 'section' | 'ruleset'

    function setMode(next) {
        mode = next;
        modeTabs.forEach(btn => {
            const active = btn.dataset.mode === mode;
            btn.classList.toggle('bg-indigo-600', active);
            btn.classList.toggle('text-white', active);
            btn.classList.toggle('border-indigo-600', active);
            btn.classList.toggle('bg-white', !active);
            btn.classList.toggle('dark:bg-slate-800', !active);
            btn.classList.toggle('text-slate-500', !active);
            btn.classList.toggle('border-slate-200', !active);
            btn.classList.toggle('dark:border-slate-700', !active);
        });
        sectionPath.style.display = mode === 'section' ? 'block' : 'none';
        rulesetPath.style.display = mode === 'ruleset' ? 'block' : 'none';
        refreshDestination();
    }

    modeTabs.forEach(btn => btn.addEventListener('click', () => setMode(btn.dataset.mode)));

    function currentDept() {
        return page.tree.find(d => String(d.id) === deptSelect.value) || null;
    }
    function currentSection(dept) {
        if (!dept) return null;
        return (dept.sections || []).find(s => String(s.id) === sectionSel.value) || null;
    }
    function currentDivision(section) {
        if (!section) return null;
        return (section.divisions || []).find(d => String(d.id) === divisionSel.value) || null;
    }
    function currentFolder(section, division) {
        const folders = division ? (division.folders || []) : (section ? section.folders || [] : []);
        return folders.find(f => String(f.id) === folderSel.value) || null;
    }
    function currentRuleSet(dept) {
        if (!dept) return null;
        return (dept.ruleSets || []).find(r => String(r.id) === rulesetSel.value) || null;
    }

    function fillSelect(select, items, valueKey, labelFn, placeholder) {
        select.innerHTML = '';
        if (placeholder) {
            const opt = document.createElement('option');
            opt.value = '';
            opt.textContent = placeholder;
            select.appendChild(opt);
        }
        items.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item[valueKey];
            opt.textContent = labelFn(item);
            select.appendChild(opt);
        });
    }

    function populateDepartments() {
        fillSelect(deptSelect, page.tree, 'id', d => `${d.name} (${d.levelLabel})`, null);
        onDepartmentChange();
    }

    function onDepartmentChange() {
        const dept = currentDept();

        const hasRuleSets = dept && dept.ruleSets && dept.ruleSets.length > 0;
        document.querySelector('.ctx-tab[data-mode="ruleset"]').style.display = hasRuleSets ? '' : 'none';
        if (!hasRuleSets && mode === 'ruleset') setMode('section');

        fillSelect(sectionSel, dept ? dept.sections : [], 'id',
            s => s.name + (s.wing ? ' · ' + s.wing.replace(/_/g, ' ') : ''), null);
        onSectionChange();

        fillSelect(rulesetSel, dept ? dept.ruleSets : [], 'id', r => r.name, null);
        onRuleSetChange();
    }

    function onSectionChange() {
        const dept = currentDept();
        const section = currentSection(dept);
        fillSelect(divisionSel, section ? section.divisions : [], 'id', d => d.name, '— Direct in section —');
        onDivisionChange();
    }

    function onDivisionChange() {
        const dept = currentDept();
        const section = currentSection(dept);
        const division = currentDivision(section);
        const folders = division ? division.folders : (section ? section.folders : []);
        fillSelect(folderSel, folders || [], 'id', f => f.name, '— No folder (direct) —');
        onFolderChange();
    }

    function onFolderChange() {
        refreshParentOptions();
        refreshDestination();
    }

    function onRuleSetChange() {
        const rs = currentRuleSet(currentDept());
        rulesetHint.textContent = rs
            ? (rs.hasRuleDoc
                ? 'A root Rule document already exists — new uploads here should typically use "Amendment to Rule" and reference it below.'
                : 'No root Rule document yet — the first upload here should typically use document type "Rule".')
            : '';
        refreshParentOptions();
        refreshDestination();
    }

    function refreshParentOptions() {
        let options = [];
        if (mode === 'ruleset') {
            const rs = currentRuleSet(currentDept());
            options = rs ? rs.parentOptions : [];
        } else {
            const dept = currentDept();
            const section = currentSection(dept);
            const division = currentDivision(section);
            const folder = currentFolder(section, division);
            options = folder ? folder.parentOptions : (section ? section.parentOptions : []);
        }
        fillSelect(parentSelect, options || [], 'id', o => `${o.title} (${o.date})`, '— None (original document) —');
    }

    function refreshDestination() {
        let crumbs = [];
        if (mode === 'ruleset') {
            const dept = currentDept();
            const rs = currentRuleSet(dept);
            crumbs = dept && rs ? [dept.levelLabel, dept.name, 'Rules', rs.name] : [];
        } else {
            const dept = currentDept();
            const section = currentSection(dept);
            const division = currentDivision(section);
            const folder = currentFolder(section, division);
            if (dept && section) {
                crumbs = [dept.levelLabel, dept.name];
                if (section.wing) crumbs.push(section.wing.replace(/_/g, ' '));
                crumbs.push(section.name);
                if (division) crumbs.push(division.name);
                if (folder) { crumbs.push('folders'); crumbs.push(folder.name); }
            }
        }
        vaultPreview.textContent = crumbs.length ? crumbs.join(' › ') : '— select a destination —';
    }

    deptSelect.addEventListener('change', onDepartmentChange);
    sectionSel.addEventListener('change', onSectionChange);
    divisionSel.addEventListener('change', onDivisionChange);
    folderSel.addEventListener('change', onFolderChange);
    rulesetSel.addEventListener('change', onRuleSetChange);

    populateDepartments();
    setMode('section');

    // ── File queue (same pattern as section/division/folder/rule-set upload modals) ──
    const fileInput    = document.getElementById('doc-file');
    const dropZone     = document.getElementById('drop-zone');
    const btnSubmit    = document.getElementById('btn-submit');
    const btnLabel     = document.getElementById('btn-submit-label');
    const statusEl     = document.getElementById('upload-status');
    const queueWrap    = document.getElementById('file-queue-wrap');
    const queueList    = document.getElementById('file-queue');
    const queueCountEl = document.getElementById('queue-count');
    const queueHint    = document.getElementById('queue-empty-hint');
    const btnClear     = document.getElementById('btn-clear-queue');

    let uploadFiles = []; // [{file, titleInput, statusBadge, row}]
    let isUploading = false;

    function fileToTitle(name) {
        return name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
    }

    function badgeClass(state) {
        const base = 'queue-status flex-shrink-0 text-[10px] px-1.5 py-0.5 rounded font-medium ';
        const map = {
            pending:    'bg-slate-100 dark:bg-slate-700 text-slate-400 dark:text-slate-500',
            uploading:  'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
            converting: 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
            done:       'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
            error:      'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
        };
        return base + (map[state] || map.pending);
    }

    function setRowStatus(item, state, msg) {
        item.statusBadge.className = badgeClass(state);
        const labels = { pending: 'Pending', uploading: 'Uploading…', converting: 'Queued for OCR/conversion', done: '✓ Uploaded' };
        item.statusBadge.textContent = state === 'error' ? ('✗ ' + (msg || 'Error')) : (labels[state] || state);
        if (state === 'done' || state === 'converting') item.row.style.opacity = '0.75';
    }

    function syncUI() {
        const n = uploadFiles.length;
        queueCountEl.textContent = n;
        queueWrap.style.display = n ? 'flex' : 'none';
        queueHint.style.display = n ? 'none' : 'block';
        btnLabel.textContent = n > 1 ? `Upload ${n} files` : 'Upload';
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

    function showErr(id, msg) {
        const el = document.getElementById(id);
        if (el) { el.textContent = msg; el.style.display = 'block'; }
    }
    function clearErr(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    // ── Sequential upload loop — builds the context field combination from the picker state ──
    btnSubmit.addEventListener('click', async () => {
        if (isUploading || uploadFiles.length === 0) return;

        clearErr('err-type');
        if (!typeSelect.value) {
            showErr('err-type', 'Select a document type before uploading.');
            return;
        }

        let contextFields = {};
        if (mode === 'ruleset') {
            const rs = currentRuleSet(currentDept());
            if (!rs) { showErr('err-type', 'Select a rule set before uploading.'); return; }
            contextFields = { rule_set_id: rs.id };
        } else {
            const dept = currentDept();
            const section = currentSection(dept);
            if (!section) { showErr('err-type', 'Select a section before uploading.'); return; }
            const division = currentDivision(section);
            const folder = currentFolder(section, division);
            contextFields = { section_id: section.id };
            if (division) contextFields.division_id = division.id;
            if (folder) contextFields.folder_id = folder.id;
        }

        const type            = typeSelect.value;
        const visibility      = document.querySelector('[name="visibility"]:checked')?.value || 'public';
        const parentId        = parentSelect.value || '';
        const amendmentNumber = document.getElementById('doc-amendment-number').value.trim();
        const effectiveYear   = document.getElementById('doc-effective-year').value.trim();
        const effectiveMonth  = document.getElementById('doc-effective-month').value;
        const effectiveDay    = document.getElementById('doc-effective-day').value.trim();
        const shouldConvert   = autoConvert.checked;

        isUploading = true;
        btnSubmit.disabled = true;
        statusEl.textContent = '';

        let doneCount = 0, errorCount = 0;

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

            try {
                const fd = new FormData();
                fd.append('_token', page.csrfToken);
                Object.entries(contextFields).forEach(([k, v]) => fd.append(k, v));
                fd.append('title', title);
                fd.append('document_type', type);
                fd.append('visibility', visibility);
                if (parentId)        fd.append('parent_id',        parentId);
                if (amendmentNumber) fd.append('amendment_number', amendmentNumber);
                if (effectiveYear)   fd.append('effective_year',   effectiveYear);
                if (effectiveMonth)  fd.append('effective_month',  effectiveMonth);
                if (effectiveDay)    fd.append('effective_day',    effectiveDay);
                fd.append('file', item.file);

                const res = await fetch(page.storeUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.csrfToken },
                    body: fd,
                });

                const ct = res.headers.get('Content-Type') || '';
                if (!ct.includes('application/json')) {
                    if (res.status === 419) throw new Error('Session expired — refresh and try again');
                    throw new Error('HTTP ' + res.status);
                }

                const json = await res.json();
                if (!res.ok) {
                    const msg = json.errors ? Object.values(json.errors).flat()[0] : (json.message || 'Upload failed');
                    setRowStatus(item, 'error', msg);
                    errorCount++;
                    continue;
                }

                doneCount++;

                if (shouldConvert && json.document_id) {
                    setRowStatus(item, 'converting');
                    fetch(`${page.convertUrlBase}/${json.document_id}/convert`, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.csrfToken },
                    }).catch(err => console.error('Convert dispatch failed for document', json.document_id, err));
                } else {
                    setRowStatus(item, 'done');
                }

            } catch (err) {
                setRowStatus(item, 'error', err.message);
                errorCount++;
                console.error('Upload error:', item.file.name, err);
            }
        }

        isUploading = false;
        statusEl.textContent = shouldConvert
            ? `${doneCount} uploaded and queued for conversion, ${errorCount} failed.`
            : `${doneCount} uploaded, ${errorCount} failed.`;
        btnLabel.textContent = 'Upload';
        syncUI();
    });
})();
</script>
@endpush

</x-layout>

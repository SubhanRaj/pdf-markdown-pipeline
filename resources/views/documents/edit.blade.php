@php
    $isRuleSetDoc        = isset($ruleSet) && $ruleSet !== null;
    $isFolderDoc         = isset($folder)  && $folder  !== null;
    $isDivisionFolderDoc = $isFolderDoc && isset($division) && $division !== null;
    $isSectionFolderDoc  = $isFolderDoc && ! $isDivisionFolderDoc;
    $isDivisionDoc       = ! $isFolderDoc && isset($division) && $division !== null;
    $wing                = ($isRuleSetDoc || $isDivisionDoc || $isFolderDoc) ? null : ($section->wing ?? null);

    if ($isRuleSetDoc) {
        $contextName  = $ruleSet->name;
        $contextUrl   = route("departments.{$ruleSet->kind}.show", [$department->levelAlias(), $department, $ruleSet]);
        $updateRoute  = route("documents.{$ruleSet->kind}.update", [$department->levelAlias(), $department, $ruleSet, $document]);
        $showRoute    = route("documents.{$ruleSet->kind}.show",   [$department->levelAlias(), $department, $ruleSet, $document]);
    } elseif ($isDivisionFolderDoc) {
        $contextName  = $folder->name;
        $contextUrl   = route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder]);
        $updateRoute  = route('documents.divisions.folders.update', [$department->levelAlias(), $department, $section, $division, $folder, $document]);
        $showRoute    = route('documents.divisions.folders.show',   [$department->levelAlias(), $department, $section, $division, $folder, $document]);
    } elseif ($isSectionFolderDoc) {
        $contextName  = $folder->name;
        $contextUrl   = route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $folder]);
        $updateRoute  = route('documents.folders.update', [$department->levelAlias(), $department, $section, $folder, $document]);
        $showRoute    = route('documents.folders.show',   [$department->levelAlias(), $department, $section, $folder, $document]);
    } elseif ($isDivisionDoc) {
        $contextName  = $division->name;
        $contextUrl   = route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]);
        $updateRoute  = route('documents.divisions.update', [$department->levelAlias(), $department, $section, $division, $document]);
        $showRoute    = route('documents.divisions.show',   [$department->levelAlias(), $department, $section, $division, $document]);
    } else {
        $contextName  = $section->name;
        $contextUrl   = route('departments.sections.show', [$department->levelAlias(), $department, $section]);
        $updateRoute  = route('documents.update', [$department->levelAlias(), $department, $section, $document]);
        $showRoute    = route('documents.show',   [$department->levelAlias(), $department, $section, $document]);
    }
@endphp

<x-layout
    :title="'Edit: ' . $document->title"
    page-title="Edit Document"
    :page-subtitle="$department->name . ($wing ? ' · ' . Str::title(str_replace('_', ' ', $wing)) : '') . ' · ' . $contextName"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                              'url' => route('home')],
    ['name' => 'Departments',                       'url' => route('departments.index')],
    ['name' => $department->levelLabel(),           'url' => null],
    ['name' => $department->name,                   'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $contextName,                        'url' => $contextUrl],
    ['name' => $document->title,                    'url' => $showRoute],
    ['name' => 'Edit',                              'url' => null],
]" />

<div class="max-w-2xl">
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center gap-3">
            <div class="w-8 h-8 rounded-lg bg-indigo-500/10 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
                <i class="ti ti-pencil text-sm text-indigo-500 dark:text-indigo-400"></i>
            </div>
            <div class="min-w-0">
                <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100 leading-tight truncate">{{ $document->title }}</h3>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 font-mono">{{ $document->slug }}</p>
            </div>
        </div>

        <form id="edit-doc-form" method="POST" action="{{ $updateRoute }}" novalidate>
            @csrf
            @method('PATCH')

            <div class="px-6 py-5 space-y-5">

                {{-- Title --}}
                <div>
                    <label for="title" class="field-label">Title</label>
                    <input
                        type="text"
                        id="title"
                        name="title"
                        value="{{ old('title', $document->title) }}"
                        maxlength="255"
                        class="field-input mt-1 @error('title') border-red-400 dark:border-red-500 @enderror"
                        required
                    >
                    @error('title')
                    <p class="field-err-msg">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Document Type --}}
                <div>
                    <label for="document_type" class="field-label">Document Type</label>
                    <select id="document_type" name="document_type"
                            class="field-input mt-1 @error('document_type') border-red-400 dark:border-red-500 @enderror">
                        @foreach(\App\Models\Document::DOCUMENT_TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(old('document_type', $document->document_type) === $key)>
                            {{ $label }}
                        </option>
                        @endforeach
                    </select>
                    @error('document_type')
                    <p class="field-err-msg">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Status --}}
                <div>
                    <label for="status" class="field-label">Pipeline Status</label>
                    <select id="status" name="status"
                            class="field-input mt-1 @error('status') border-red-400 dark:border-red-500 @enderror">
                        @foreach(\App\Models\Document::STATUSES as $key => $meta)
                        <option value="{{ $key }}" @selected(old('status', $document->status) === $key)>
                            {{ $meta['label'] }}
                        </option>
                        @endforeach
                    </select>
                    <p class="field-hint mt-1">Changing status logs an entry in the audit trail.</p>
                    @error('status')
                    <p class="field-err-msg">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Visibility --}}
                <div>
                    <label class="field-label">Visibility</label>
                    <div class="flex gap-4 mt-2">
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="radio" name="visibility" value="public"
                                   @checked(old('visibility', $document->visibility) === 'public')
                                   class="text-indigo-600 focus:ring-indigo-500">
                            <span class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-200">
                                <i class="ti ti-world text-base text-green-500"></i> Public
                            </span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer select-none">
                            <input type="radio" name="visibility" value="authenticated"
                                   @checked(old('visibility', $document->visibility) === 'authenticated')
                                   class="text-indigo-600 focus:ring-indigo-500">
                            <span class="flex items-center gap-1.5 text-sm text-slate-700 dark:text-slate-200">
                                <i class="ti ti-lock text-base text-amber-500"></i> Authenticated Only
                            </span>
                        </label>
                    </div>
                    <p class="field-hint mt-1">Public documents are visible to all visitors without login.</p>
                    @error('visibility')
                    <p class="field-err-msg">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Language --}}
                <div>
                    <label for="language" class="field-label">Language</label>
                    <select id="language" name="language"
                            class="field-input mt-1 @error('language') border-red-400 dark:border-red-500 @enderror">
                        @foreach(\App\Models\Document::LANGUAGES as $key => $label)
                        <option value="{{ $key }}" @selected(old('language', $document->language) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('language')
                    <p class="field-err-msg">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Amendment number + effective date (rule/amendment docs only) --}}
                @if(in_array($document->document_type, ['rule', 'rule_amendment']))
                <div class="pt-3 border-t border-slate-100 dark:border-slate-700">
                    <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-3">Amendment Details</p>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="amendment_number" class="field-label">Amendment No.</label>
                            <input type="number" id="amendment_number" name="amendment_number" min="1" max="999"
                                   placeholder="e.g. 5"
                                   value="{{ old('amendment_number', $document->metadata['amendment_number'] ?? '') }}"
                                   class="field-input mt-1">
                        </div>
                        <div>
                            <label for="effective_year" class="field-label">Effective Year</label>
                            <input type="number" id="effective_year" name="effective_year" min="1900" max="2099"
                                   placeholder="e.g. 2019"
                                   value="{{ old('effective_year', $document->metadata['effective_year'] ?? '') }}"
                                   class="field-input mt-1">
                        </div>
                        <div>
                            <label for="effective_month" class="field-label">Month <span class="text-slate-400 font-normal">(optional)</span></label>
                            <select id="effective_month" name="effective_month" class="field-input mt-1">
                                <option value="">—</option>
                                @foreach(['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn)
                                <option value="{{ $mi + 1 }}"
                                        @selected(old('effective_month', $document->metadata['effective_month'] ?? '') == $mi + 1)>
                                    {{ $mn }}
                                </option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="effective_day" class="field-label">Day <span class="text-slate-400 font-normal">(optional)</span></label>
                            <input type="number" id="effective_day" name="effective_day" min="1" max="31"
                                   placeholder="1–31"
                                   value="{{ old('effective_day', $document->metadata['effective_day'] ?? '') }}"
                                   class="field-input mt-1">
                        </div>
                    </div>
                </div>
                @endif

                {{-- Read-only info --}}
                <div class="pt-1 border-t border-slate-100 dark:border-slate-700 grid grid-cols-2 gap-4">
                    <div>
                        <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Original File</dt>
                        <dd class="text-xs font-mono text-slate-600 dark:text-slate-300 break-all">{{ $document->original_filename }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-slate-400 dark:text-slate-500 mb-0.5">Uploaded</dt>
                        <dd class="text-xs text-slate-600 dark:text-slate-300">{{ $document->created_at->format('d M Y, H:i') }}</dd>
                    </div>
                </div>

            </div>

            {{-- Actions --}}
            <div class="px-6 py-4 border-t border-slate-100 dark:border-slate-700 flex items-center justify-between gap-3">
                <a href="{{ $showRoute }}"
                   class="inline-flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
                    <i class="ti ti-arrow-left text-base"></i> Back to Document
                </a>
                <button type="submit" id="save-btn"
                        class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                    <i class="ti ti-device-floppy text-base"></i>
                    Save Changes
                </button>
            </div>

        </form>
    </div>

    @php
        $canMove = $moveTree && $document->parent_id === null;
        $amendmentCount = $canMove ? $document->amendments()->count() : 0;
    @endphp
    @if($canMove)
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 mt-6">
        <div class="px-6 py-4 border-b border-slate-100 dark:border-slate-700">
            <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                <i class="ti ti-arrows-right-left text-indigo-500"></i> Move / Copy
            </h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                Relocate or duplicate this document to another section, division, or folder within {{ $department->name }}. Cross-department moves aren't supported.
                @if($amendmentCount)
                Its {{ $amendmentCount }} {{ Str::plural('amendment', $amendmentCount) }} {{ $amendmentCount === 1 ? 'goes' : 'go' }} with it.
                @endif
            </p>
        </div>
        <div class="px-6 py-5 space-y-4">
            <div>
                <label for="move-section" class="field-label">Section</label>
                <select id="move-section" class="field-input mt-1"></select>
            </div>
            <div id="move-division-wrap" style="display:none">
                <label for="move-division" class="field-label">Division <span class="text-slate-400 font-normal">(optional)</span></label>
                <select id="move-division" class="field-input mt-1"></select>
            </div>
            <div id="move-folder-wrap" style="display:none">
                <label for="move-folder" class="field-label">Folder <span class="text-slate-400 font-normal">(optional)</span></label>
                <select id="move-folder" class="field-input mt-1"></select>
            </div>
            <div id="move-subfolder-wrap" style="display:none">
                <label for="move-subfolder" class="field-label">Subfolder <span class="text-slate-400 font-normal">(optional)</span></label>
                <select id="move-subfolder" class="field-input mt-1"></select>
            </div>
            <div id="move-err" class="field-err-msg" style="display:none"></div>
            <div class="flex items-center gap-3 pt-1">
                <button type="button" id="move-btn"
                        class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                    <i class="ti ti-folder-symlink text-base"></i> Move here
                </button>
                <button type="button" id="copy-btn"
                        class="inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
                    <i class="ti ti-copy text-base"></i> Copy here
                </button>
            </div>
        </div>
    </div>
    <script id="move-tree-data" type="application/json">@json($moveTree)</script>
    <form id="move-form" method="POST" action="{{ route('documents.move', $document->id) }}" style="display:none">@csrf</form>
    <form id="copy-form" method="POST" action="{{ route('documents.copy', $document->id) }}" style="display:none">@csrf</form>
    @endif
</div>

@push('scripts')
<script>
try {
    const form  = document.getElementById('edit-doc-form');
    const title = document.getElementById('title');

    function showErr(el, msg) {
        let err = el.parentElement.querySelector('.field-err-msg');
        if (!err) { err = document.createElement('p'); err.className = 'field-err-msg'; el.parentElement.appendChild(err); }
        err.textContent = msg;
        el.classList.add('border-red-400', 'dark:border-red-500');
    }

    function clearErr(el) {
        const err = el.parentElement.querySelector('.field-err-msg');
        if (err) err.textContent = '';
        el.classList.remove('border-red-400', 'dark:border-red-500');
    }

    title.addEventListener('blur', () => {
        const v = title.value.trim();
        if (!v || v.length < 3) showErr(title, 'Title must be at least 3 characters.');
        else if (v.length > 255) showErr(title, 'Title may not exceed 255 characters.');
        else clearErr(title);
    });

    form.addEventListener('submit', (e) => {
        let ok = true;
        const v = title.value.trim();
        if (!v || v.length < 3) { showErr(title, 'Title must be at least 3 characters.'); ok = false; }
        if (!ok) { e.preventDefault(); title.focus(); }
    });
} catch (err) {
    console.error('Edit doc form init failed:', err);
}
</script>

@if($canMove)
<script>
(function () {
    let tree;
    try { tree = JSON.parse(document.getElementById('move-tree-data').textContent); }
    catch (e) { console.error('move-tree-data parse failed', e); return; }

    const sectionSel    = document.getElementById('move-section');
    const divisionSel   = document.getElementById('move-division');
    const folderSel     = document.getElementById('move-folder');
    const subfolderSel  = document.getElementById('move-subfolder');
    const divisionWrap  = document.getElementById('move-division-wrap');
    const folderWrap    = document.getElementById('move-folder-wrap');
    const subfolderWrap = document.getElementById('move-subfolder-wrap');
    const errBox        = document.getElementById('move-err');

    function fillOptions(select, items, placeholder) {
        select.innerHTML = '';
        if (placeholder) {
            const opt = document.createElement('option');
            opt.value = ''; opt.textContent = placeholder;
            select.appendChild(opt);
        }
        items.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id; opt.textContent = item.name;
            select.appendChild(opt);
        });
    }

    function currentSection() {
        return (tree.sections || []).find(s => String(s.id) === sectionSel.value);
    }
    function currentDivision() {
        const sec = currentSection();
        return sec && divisionSel.value ? (sec.divisions || []).find(d => String(d.id) === divisionSel.value) : null;
    }
    function currentFolder() {
        const div = currentDivision();
        const sec = currentSection();
        const folders = div ? (div.folders || []) : (sec ? (sec.folders || []) : []);
        return folderSel.value ? folders.find(f => String(f.id) === folderSel.value) : null;
    }

    function onSectionChange() {
        const sec = currentSection();
        const divisions = sec ? (sec.divisions || []) : [];
        divisionWrap.style.display = divisions.length ? '' : 'none';
        fillOptions(divisionSel, divisions, '— Direct in section —');
        onDivisionChange();
    }

    function onDivisionChange() {
        const sec = currentSection();
        const div = currentDivision();
        const folders = div ? (div.folders || []) : (sec ? (sec.folders || []) : []);
        folderWrap.style.display = folders.length ? '' : 'none';
        fillOptions(folderSel, folders, '— None —');
        onFolderChange();
    }

    function onFolderChange() {
        const folder = currentFolder();
        const subfolders = folder ? (folder.subfolders || []) : [];
        subfolderWrap.style.display = subfolders.length ? '' : 'none';
        fillOptions(subfolderSel, subfolders, '— None —');
    }

    fillOptions(sectionSel, tree.sections || [], null);
    onSectionChange();

    sectionSel.addEventListener('change', onSectionChange);
    divisionSel.addEventListener('change', onDivisionChange);
    folderSel.addEventListener('change', onFolderChange);

    function resolveTarget() {
        if (subfolderSel.value) return { type: 'folder', id: subfolderSel.value };
        if (folderSel.value)    return { type: 'folder', id: folderSel.value };
        if (divisionSel.value)  return { type: 'division', id: divisionSel.value };
        if (sectionSel.value)   return { type: 'section', id: sectionSel.value };
        return null;
    }

    function submitTo(formId) {
        errBox.style.display = 'none';
        const target = resolveTarget();
        if (!target) { errBox.textContent = 'Pick a destination first.'; errBox.style.display = 'block'; return; }

        const form = document.getElementById(formId);
        ['target_type', 'target_id'].forEach(name => {
            let input = form.querySelector(`[name="${name}"]`);
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden'; input.name = name;
                form.appendChild(input);
            }
        });
        form.querySelector('[name="target_type"]').value = target.type;
        form.querySelector('[name="target_id"]').value = target.id;
        form.submit();
    }

    document.getElementById('move-btn').addEventListener('click', () => submitTo('move-form'));
    document.getElementById('copy-btn').addEventListener('click', () => submitTo('copy-form'));
})();
</script>
@endif
@endpush

</x-layout>

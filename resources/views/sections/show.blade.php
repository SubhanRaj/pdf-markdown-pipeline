<x-layout
    title="{{ $section->name }}"
    page-title="{{ $section->name }}"
    page-subtitle="{{ $department->name }}{{ $section->wing ? ' · ' . str_replace('_', ' ', ucfirst($section->wing)) : '' }}"
>

<x-slot:breadcrumb>
    <a href="{{ route('home') }}">Home</a>
    <i class="ti ti-chevron-right text-xs"></i>
    <a href="{{ route('departments.index') }}">Departments</a>
    <i class="ti ti-chevron-right text-xs"></i>
    <a href="{{ route('departments.show', $department) }}">{{ $department->name }}</a>
    <i class="ti ti-chevron-right text-xs"></i>
    <span>{{ $section->name }}</span>
</x-slot:breadcrumb>

{{-- Data island for JS — use @json() not {{ json_encode() }} to avoid HTML-entity corruption --}}
<script id="page-data" type="application/json">@json([
    'storeUrl'  => route('documents.store'),
    'csrfToken' => csrf_token(),
])</script>

{{-- ── Section header ─────────────────────────────────────────────────────── --}}
<div class="flex items-start justify-between gap-4 mb-6">
    <div class="flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-indigo-500/10 dark:bg-indigo-500/20 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-folder-open text-indigo-500 dark:text-indigo-400 text-xl"></i>
        </div>
        <div>
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $section->name }}</h2>
            <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                <a href="{{ route('departments.show', $department) }}" class="text-xs text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">{{ $department->name }}</a>
                @if($section->wing)
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <a href="{{ route('departments.show', $department) }}" class="text-xs text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">{{ Str::title(str_replace('_', ' ', $section->wing)) }}</a>
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
    <div class="flex items-center gap-2">
        @auth
        <button id="btn-toggle-upload" type="button"
                onclick="document.getElementById('upload-modal').style.display='block'"
                class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 py-2 rounded-lg transition-colors">
            <i class="ti ti-upload text-base"></i>
            <span class="hidden sm:inline">Upload PDF</span>
        </button>
        @endauth
        @auth @if(auth()->user()->isAdmin())
        <a href="{{ route('departments.sections.edit', [$department, $section]) }}"
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

        {{-- Modal header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <div class="flex items-center gap-2">
                <i class="ti ti-file-upload text-indigo-500 text-lg"></i>
                <span class="text-sm font-semibold text-slate-800 dark:text-slate-100">Upload Document</span>
                <span class="text-xs text-slate-400 dark:text-slate-500">— {{ $section->name }}</span>
            </div>
            <button type="button" onclick="document.getElementById('upload-modal').style.display='none'"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                <i class="ti ti-x"></i>
            </button>
        </div>

        {{-- Modal body: two-column on large, stacked on small --}}
        <div class="flex flex-col lg:flex-row flex-1 min-h-0">

            {{-- Left: file drop + preview --}}
            <div class="lg:w-1/2 border-b lg:border-b-0 lg:border-r border-slate-200 dark:border-slate-700 flex flex-col">
                {{-- Drop zone --}}
                <div id="drop-zone"
                     onclick="document.getElementById('doc-file').click()"
                     style="cursor:pointer"
                     class="m-4 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors flex flex-col items-center justify-center gap-2 py-8 px-4 text-center">
                    <i class="ti ti-cloud-upload text-3xl text-slate-300 dark:text-slate-600"></i>
                    <p class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag a file here</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 50 MB</p>
                    <input type="file" id="doc-file" name="file"
                           accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif,.svg"
                           style="display:none">
                </div>
                {{-- Selected file info (shown after file picked) --}}
                <div id="preview-wrap" style="display:none" class="px-4 pb-4">
                    <div class="flex items-center gap-3 p-3 bg-slate-50 dark:bg-slate-800/60 rounded-lg border border-slate-200 dark:border-slate-700">
                        <i id="preview-icon" class="ti ti-file-description text-2xl text-indigo-500 dark:text-indigo-400 flex-shrink-0"></i>
                        <div class="min-w-0">
                            <p id="preview-filename" class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate"></p>
                            <p id="preview-filesize" class="text-xs text-slate-400 dark:text-slate-500 mt-0.5"></p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right: form fields --}}
            <div class="lg:w-1/2 p-6 flex flex-col gap-4">

                <form id="upload-form" method="POST" action="{{ route('documents.store') }}"
                      novalidate enctype="multipart/form-data" class="flex flex-col gap-4 flex-1">
                    @csrf
                    <input type="hidden" name="section_id" value="{{ $section->id }}">

                    {{-- Title --}}
                    <div>
                        <label for="doc-title" class="field-label">Title / Reference <span class="text-red-500">*</span></label>
                        <input type="text" id="doc-title" name="title"
                               class="field-input"
                               placeholder="e.g. GO-2024/123 – Grant of Leave Rules"
                               maxlength="255" autocomplete="off">
                        <p id="err-title" class="field-err-msg" style="display:none"></p>
                    </div>

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

                    {{-- File error --}}
                    <p id="err-file" class="field-err-msg" style="display:none"></p>

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

{{-- ── Document list ───────────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Documents</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">
                {{ $documents->total() }} {{ Str::plural('document', $documents->total()) }}
                @guest · public (verified only) @endguest
            </p>
        </div>
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
        <div class="flex items-start gap-4 px-5 py-4 hover:bg-slate-50 dark:hover:bg-slate-700/30 transition-colors group">

            {{-- Icon --}}
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
                    {{-- Document type badge --}}
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                        {{ \App\Models\Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type }}
                    </span>

                    @auth
                    {{-- Status badge (auth only for non-verified) --}}
                    @php
                        $statusMeta = \App\Models\Document::STATUSES[$doc->status] ?? ['label' => $doc->status, 'color' => 'slate'];
                        $statusColors = [
                            'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
                            'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
                            'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
                            'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
                            'green'  => 'bg-green-100 dark:bg-green-900/30 text-green-600 dark:text-green-400',
                            'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
                        ];
                    @endphp
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium {{ $statusColors[$statusMeta['color']] ?? $statusColors['slate'] }}">
                        {{ $statusMeta['label'] }}
                    </span>
                    @endauth

                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $doc->created_at->format('d M Y') }}</span>

                    @auth
                    @if($doc->user)
                    <span class="text-slate-300 dark:text-slate-600">·</span>
                    <span class="text-xs text-slate-400 dark:text-slate-500">{{ $doc->user->name }}</span>
                    @endif
                    @endauth
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-1 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity">
                <a href="{{ route('documents.show', $doc) }}"
                   class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:bg-indigo-50 dark:hover:bg-indigo-900/20 transition-all"
                   title="View">
                    <i class="ti ti-eye text-base"></i>
                </a>
                @auth
                @if(auth()->user()->isAdmin())
                <form method="POST" action="{{ route('documents.destroy', $doc) }}"
                      onsubmit="return confirm('Delete this document? This cannot be undone.')">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-red-600 dark:hover:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-all"
                            title="Delete">
                        <i class="ti ti-trash text-base"></i>
                    </button>
                </form>
                @endif
                @endauth
            </div>
        </div>
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
    // Guard: modal only exists for authenticated users
    const modal = document.getElementById('upload-modal');
    if (!modal) return;

    let page;
    try {
        page = JSON.parse(document.getElementById('page-data').textContent);
    } catch (e) {
        console.error('page-data JSON parse failed', e);
        return;
    }

    const form       = document.getElementById('upload-form');
    const fileInput  = document.getElementById('doc-file');
    const dropZone   = document.getElementById('drop-zone');
    const typeSelect = document.getElementById('doc-type');
    const titleInput = document.getElementById('doc-title');
    const btnSubmit  = document.getElementById('btn-submit');
    const btnLabel   = document.getElementById('btn-submit-label');
    const status     = document.getElementById('upload-status');

    // ── File pick & preview ────────────────────────────────────────────────
    function handleFile(file) {
        if (!file) return;
        clearErr('err-file');
        document.getElementById('preview-filename').textContent = file.name;
        document.getElementById('preview-filesize').textContent = (file.size / 1048576).toFixed(1) + ' MB';
        document.getElementById('preview-wrap').style.display = 'block';
        if (!titleInput.value.trim()) {
            titleInput.value = file.name.replace(/\.[^.]+$/, '').replace(/[-_]/g, ' ');
        }
    }

    fileInput.addEventListener('change', () => handleFile(fileInput.files[0]));

    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.style.borderColor = '#6366f1'; });
    dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.style.borderColor = '';
        const file = e.dataTransfer.files[0];
        if (file) {
            const dt = new DataTransfer();
            dt.items.add(file);
            fileInput.files = dt.files;
            handleFile(file);
        }
    });

    // ── Inline error helpers ───────────────────────────────────────────────
    function showErr(id, msg) {
        const el = document.getElementById(id);
        if (!el) return;
        el.textContent = msg;
        el.style.display = 'block';
    }
    function clearErr(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    // ── Submit via AJAX — never let the form do a native GET/POST ─────────
    form.addEventListener('submit', async e => {
        e.preventDefault();

        let ok = true;
        if (!titleInput.value.trim()) { showErr('err-title', 'Title is required.');      ok = false; } else clearErr('err-title');
        if (!typeSelect.value)        { showErr('err-type',  'Select a document type.'); ok = false; } else clearErr('err-type');
        if (!fileInput.files[0])      { showErr('err-file',  'Please select a file.');   ok = false; } else clearErr('err-file');
        if (!ok) return;

        btnSubmit.disabled = true;
        btnLabel.textContent = 'Uploading…';
        status.textContent = '';

        try {
            // File input is outside the <form> element (left column vs right column layout),
            // so FormData(form) won't capture it — append explicitly.
            const formData = new FormData(form);
            if (fileInput.files[0]) formData.append('file', fileInput.files[0]);

            const res = await fetch(page.storeUrl, {
                method:  'POST',
                headers: {
                    'Accept':           'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN':     page.csrfToken,
                },
                body: formData,
            });

            // Guard: ensure we got JSON before parsing (catches 419 session expiry etc.)
            const contentType = res.headers.get('Content-Type') || '';
            if (!contentType.includes('application/json')) {
                if (res.status === 419) throw new Error('Session expired — please refresh and try again.');
                if (res.redirected) { window.location.href = res.url; return; }
                throw new Error('Unexpected server response (HTTP ' + res.status + '). Please refresh and retry.');
            }

            const json = await res.json();

            if (res.status === 422) {
                const map = { title: 'err-title', document_type: 'err-type', file: 'err-file' };
                Object.entries(json.errors || {}).forEach(([field, msgs]) => {
                    if (map[field]) showErr(map[field], msgs[0]);
                });
                return;
            }

            if (!res.ok) throw new Error(json.message || 'Upload failed (HTTP ' + res.status + ')');

            window.location.href = json.redirect || window.location.href;

        } catch (err) {
            status.textContent = err.message;
            console.error('Upload error:', err);
        } finally {
            btnSubmit.disabled = false;
            btnLabel.textContent = 'Upload';
        }
    });
})();
</script>
@endpush

</x-layout>

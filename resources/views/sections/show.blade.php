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

{{-- Data island for JS --}}
<script id="page-data" type="application/json">{{ json_encode([
    'documentTypes' => \App\Models\Document::DOCUMENT_TYPES,
    'storeUrl'      => route('documents.store'),
    'csrfToken'     => csrf_token(),
]) }}</script>

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
        <button id="btn-toggle-upload"
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

{{-- ── Upload panel (auth only) ────────────────────────────────────────────── --}}
@auth
<div id="upload-panel" class="hidden mb-6">
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="ti ti-cloud-upload text-indigo-500 dark:text-indigo-400"></i>
                <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Upload PDF</h3>
            </div>
            <button id="btn-close-upload" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">
                <i class="ti ti-x text-lg"></i>
            </button>
        </div>

        <form id="upload-form" novalidate enctype="multipart/form-data"
              class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
            @csrf
            <input type="hidden" name="section_id" value="{{ $section->id }}">

            {{-- Title --}}
            <div class="sm:col-span-2">
                <label for="doc-title" class="field-label">Title / Reference <span class="text-red-500">*</span></label>
                <input type="text" id="doc-title" name="title"
                       class="field-input"
                       placeholder="e.g. GO-2024/123 – Grant of Leave Rules"
                       maxlength="255" autocomplete="off">
                <p id="err-title" class="field-err-msg hidden"></p>
            </div>

            {{-- Document type --}}
            <div>
                <label for="doc-type" class="field-label">Document Type <span class="text-red-500">*</span></label>
                <select id="doc-type" name="document_type" class="field-input">
                    <option value="">— Select type —</option>
                </select>
                <p id="err-type" class="field-err-msg hidden"></p>
            </div>

            {{-- File --}}
            <div>
                <label for="doc-file" class="field-label">File <span class="text-red-500">*</span></label>
                <input type="file" id="doc-file" name="file"
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif,.svg"
                       class="field-input file:mr-3 file:py-1 file:px-3 file:rounded file:border-0 file:text-xs file:font-medium file:bg-indigo-50 file:text-indigo-700 dark:file:bg-indigo-900/30 dark:file:text-indigo-300 cursor-pointer">
                <p id="err-file" class="field-err-msg hidden"></p>
                <p id="hint-file" class="field-hint">PDF · Word · Excel · PowerPoint · Images · ODT · RTF · TXT · max 50 MB</p>
            </div>

            {{-- Vault destination preview --}}
            <div class="sm:col-span-2 bg-slate-50 dark:bg-slate-900/50 rounded-lg px-4 py-3 border border-slate-100 dark:border-slate-700/50">
                <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1.5">Will be saved to</p>
                <div class="flex items-center gap-1 flex-wrap">
                    @foreach($vaultCrumbs as $crumb)
                        @if(!$loop->first)<i class="ti ti-chevron-right text-[10px] text-slate-300 dark:text-slate-600"></i>@endif
                        <span class="text-xs text-slate-500 dark:text-slate-400 {{ $loop->last ? 'font-medium text-indigo-600 dark:text-indigo-400' : '' }}">{{ $crumb }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Submit --}}
            <div class="sm:col-span-2 flex items-center gap-3 pt-1">
                <button type="submit" id="btn-submit"
                        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 disabled:cursor-not-allowed text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                    <i class="ti ti-upload"></i>
                    <span id="btn-submit-label">Upload</span>
                </button>
                <p id="upload-progress" class="text-xs text-slate-400 dark:text-slate-500 hidden">Uploading…</p>
            </div>
        </form>
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
    const page   = JSON.parse(document.getElementById('page-data').textContent);
    const panel  = document.getElementById('upload-panel');
    const form   = document.getElementById('upload-form');
    const btnOpen  = document.getElementById('btn-toggle-upload');
    const btnClose = document.getElementById('btn-close-upload');
    const btnSubmit = document.getElementById('btn-submit');
    const btnLabel  = document.getElementById('btn-submit-label');
    const progress  = document.getElementById('upload-progress');

    // ── Populate document type select ──────────────────────────────────────
    const typeSelect = document.getElementById('doc-type');
    Object.entries(page.documentTypes).forEach(([key, label]) => {
        const opt = document.createElement('option');
        opt.value = key;
        opt.textContent = label;
        typeSelect.appendChild(opt);
    });

    // ── Panel toggle ───────────────────────────────────────────────────────
    if (btnOpen) btnOpen.addEventListener('click', () => {
        panel.classList.toggle('hidden');
        if (!panel.classList.contains('hidden')) {
            document.getElementById('doc-title').focus();
        }
    });
    if (btnClose) btnClose.addEventListener('click', () => panel.classList.add('hidden'));

    // ── Validation helpers ─────────────────────────────────────────────────
    function showErr(id, msg) {
        const el = document.getElementById(id);
        el.textContent = msg;
        el.classList.remove('hidden');
        const input = el.previousElementSibling;
        if (input) input.classList.add('field-error');
    }
    function clearErr(id) {
        const el = document.getElementById(id);
        el.classList.add('hidden');
        const input = el.previousElementSibling;
        if (input) input.classList.remove('field-error');
    }

    const titleInput = document.getElementById('doc-title');
    const fileInput  = document.getElementById('doc-file');
    const hintFile   = document.getElementById('hint-file');

    const TITLE_RE = /^[\p{L}\p{N}\s\-_.,()\/\#\&]+$/u;
    const MAX_BYTES = 50 * 1024 * 1024;
    // Extension allowlist for client-side UX check only — server validates magic bytes via mimetypes: rule
    const ACCEPTED_EXTS = new Set([
        'pdf','doc','docx','xls','xlsx','ppt','pptx',
        'odt','ods','odp','rtf','txt','csv',
        'jpg','jpeg','png','webp','gif','tiff','tif','bmp','heic','heif','svg',
    ]);

    function validateTitle() {
        const v = titleInput.value.trim();
        if (!v)                       { showErr('err-title', 'Title is required.'); return false; }
        if (v.length > 255)           { showErr('err-title', 'Max 255 characters.'); return false; }
        if (!TITLE_RE.test(v))        { showErr('err-title', 'Title contains invalid characters.'); return false; }
        clearErr('err-title'); return true;
    }
    function validateType() {
        if (!typeSelect.value) { showErr('err-type', 'Please select a document type.'); return false; }
        clearErr('err-type'); return true;
    }
    function validateFile() {
        const f = fileInput.files[0];
        if (!f)                                    { showErr('err-file', 'Please select a PDF file.'); return false; }
        const ext = f.name.split('.').pop()?.toLowerCase() ?? '';
        if (!ACCEPTED_EXTS.has(ext)) {
            showErr('err-file', 'Unsupported file type. Accepted: PDF, Word, Excel, PowerPoint, images, ODT, RTF, TXT, CSV.');
            return false;
        }
        if (f.size > MAX_BYTES)                    { showErr('err-file', 'File must not exceed 50 MB.'); return false; }
        clearErr('err-file'); return true;
    }

    // Real-time validation
    titleInput.addEventListener('blur',  validateTitle);
    titleInput.addEventListener('input', () => { if (!document.getElementById('err-title').classList.contains('hidden')) validateTitle(); });
    typeSelect.addEventListener('change', validateType);
    fileInput.addEventListener('change', () => {
        if (validateFile() && fileInput.files[0]) {
            const mb = (fileInput.files[0].size / (1024 * 1024)).toFixed(1);
            hintFile.textContent = `${fileInput.files[0].name} · ${mb} MB`;
        }
    });

    // ── Submit via fetch ───────────────────────────────────────────────────
    form.addEventListener('submit', async (e) => {
        e.preventDefault();

        const okTitle = validateTitle();
        const okType  = validateType();
        const okFile  = validateFile();

        if (!okTitle || !okType || !okFile) {
            // Scroll to first error
            const first = form.querySelector('.field-error');
            if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        btnSubmit.disabled = true;
        btnLabel.textContent = 'Uploading…';
        progress.classList.remove('hidden');

        const data = new FormData(form);
        data.set('_token', page.csrfToken);

        try {
            const res = await fetch(page.storeUrl, { method: 'POST', body: data });

            // Laravel redirects on success — follow the redirect
            if (res.redirected) {
                window.location.href = res.url;
                return;
            }

            // JSON error response (validation failed server-side)
            if (res.status === 422) {
                const json = await res.json();
                Object.entries(json.errors || {}).forEach(([field, msgs]) => {
                    const map = { title: 'err-title', document_type: 'err-type', file: 'err-file' };
                    if (map[field]) showErr(map[field], msgs[0]);
                });
                const first = form.querySelector('.field-error');
                if (first) first.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            // Unexpected error
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            window.location.reload();
        } catch (err) {
            console.error('Upload error:', err);
            progress.textContent = 'Upload failed. Please try again.';
        } finally {
            btnSubmit.disabled = false;
            btnLabel.textContent = 'Upload';
        }
    });
})();
</script>
@endpush

</x-layout>

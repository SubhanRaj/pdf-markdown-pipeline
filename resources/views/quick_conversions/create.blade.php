<x-layout
    title="New Conversion"
    page-title="New Conversion"
    page-subtitle="Drop a file, get Markdown — decide where (or whether) to save it afterwards"
>

<x-breadcrumb :items="[
    ['name' => 'Home',           'url' => route('home')],
    ['name' => 'New Conversion', 'url' => null],
]" />

@php $pageData = ['storeUrl' => route('conversions.store'), 'csrfToken' => csrf_token()]; @endphp
<script id="page-data" type="application/json">@json($pageData)</script>

<div class="max-w-2xl mx-auto mb-3 text-right">
    <a href="{{ route('conversions.index') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline inline-flex items-center gap-1">
        <i class="ti ti-history text-sm"></i> My Conversions
    </a>
</div>

<div class="max-w-2xl mx-auto">
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6 space-y-5">
        <div>
            <p class="text-sm font-semibold text-slate-700 dark:text-slate-200">1. Drop a file</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">It converts to Markdown automatically — you decide afterwards whether to save it into the vault, just download the Markdown, or discard it.</p>
        </div>

        <div id="drop-zone" onclick="document.getElementById('qc-file').click()" style="cursor:pointer"
             class="rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 transition-colors flex flex-col items-center justify-center gap-1.5 py-10 px-4 text-center">
            <i class="ti ti-cloud-upload text-3xl text-slate-300 dark:text-slate-600"></i>
            <p id="drop-zone-label" class="text-sm font-medium text-slate-500 dark:text-slate-400">Click or drag a file here</p>
            <p class="text-xs text-slate-400 dark:text-slate-500">PDF · Word · Excel · Images · max 300 MB</p>
            <input type="file" id="qc-file"
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif"
                   style="display:none">
        </div>

        <div>
            <label for="qc-title" class="field-label">Title <span class="text-slate-400 font-normal">(optional — you can set this later when you save it)</span></label>
            <input type="text" id="qc-title" class="field-input" placeholder="Auto-filled from filename" maxlength="255">
        </div>

        <div class="flex items-center gap-3 pt-2 border-t border-slate-100 dark:border-slate-700">
            <button type="button" id="btn-submit" disabled
                    class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-wand"></i>
                <span id="btn-submit-label">Convert</span>
            </button>
            <span id="upload-status" class="text-xs text-red-500"></span>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    let page;
    try {
        page = JSON.parse(document.getElementById('page-data').textContent);
    } catch (e) { console.error('page-data JSON parse failed', e); return; }

    const fileInput  = document.getElementById('qc-file');
    const dropZone   = document.getElementById('drop-zone');
    const dropLabel  = document.getElementById('drop-zone-label');
    const titleInput = document.getElementById('qc-title');
    const btnSubmit  = document.getElementById('btn-submit');
    const btnLabel   = document.getElementById('btn-submit-label');
    const statusEl   = document.getElementById('upload-status');

    let selectedFile = null;
    let isUploading  = false;

    function fileToTitle(name) {
        return name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
    }

    function selectFile(file) {
        selectedFile = file;
        dropLabel.textContent = file.name + ' — ' + (file.size / 1048576).toFixed(1) + ' MB';
        if (!titleInput.value.trim()) titleInput.value = fileToTitle(file.name);
        btnSubmit.disabled = false;
        statusEl.textContent = '';
    }

    fileInput.addEventListener('change', () => {
        if (fileInput.files.length) selectFile(fileInput.files[0]);
    });
    dropZone.addEventListener('dragover',  e => { e.preventDefault(); dropZone.style.borderColor = '#6366f1'; });
    dropZone.addEventListener('dragleave', () => { dropZone.style.borderColor = ''; });
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.style.borderColor = '';
        if (e.dataTransfer.files.length) selectFile(e.dataTransfer.files[0]);
    });

    btnSubmit.addEventListener('click', async () => {
        if (isUploading || !selectedFile) return;

        isUploading = true;
        btnSubmit.disabled = true;
        btnLabel.textContent = 'Uploading…';
        statusEl.textContent = '';

        try {
            const fd = new FormData();
            fd.append('_token', page.csrfToken);
            if (titleInput.value.trim()) fd.append('title', titleInput.value.trim());
            fd.append('file', selectedFile);

            const res = await fetch(page.storeUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': page.csrfToken },
                body: fd,
            });

            const ct = res.headers.get('Content-Type') || '';
            if (!ct.includes('application/json')) {
                throw new Error(res.status === 419 ? 'Session expired — refresh and try again' : ('HTTP ' + res.status));
            }

            const json = await res.json();
            if (!res.ok) {
                throw new Error(json.errors ? Object.values(json.errors).flat()[0] : (json.message || 'Upload failed'));
            }

            window.location.href = json.redirect;
        } catch (err) {
            statusEl.textContent = err.message;
            isUploading = false;
            btnSubmit.disabled = false;
            btnLabel.textContent = 'Convert';
        }
    });
})();
</script>
@endpush

</x-layout>

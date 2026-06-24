<x-layout
    title="Archive"
    page-title="Document Archive"
    page-subtitle="Archived documents — view, restore, or permanently remove with formal authorisation"
>

<x-breadcrumb :items="[
    ['name' => 'Home',      'url' => route('home')],
    ['name' => 'Documents', 'url' => route('documents.index')],
    ['name' => 'Archive',   'url' => null],
]" />

{{-- Data island for drawer --}}
<script id="trash-docs" type="application/json">@json($trashData)</script>

{{-- Permanent Delete Modal (letter upload — not SweetAlert2, needs file input) --}}
<div id="modal-force-delete"
     class="fixed inset-0 z-60 flex items-center justify-center"
     style="display:none!important"
     role="dialog" aria-modal="true" aria-labelledby="modal-force-title">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" id="modal-force-backdrop"></div>
    <div class="relative z-10 w-full max-w-lg mx-4 bg-white dark:bg-slate-900 rounded-2xl shadow-2xl flex flex-col">
        <div class="px-6 py-5 border-b border-slate-200 dark:border-slate-700 flex items-start justify-between gap-3">
            <div>
                <h2 id="modal-force-title" class="text-base font-semibold text-red-600 dark:text-red-400 flex items-center gap-2">
                    <i class="ti ti-alert-triangle"></i> Permanently Delete Document
                </h2>
                <p id="modal-force-doc-title" class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate"></p>
            </div>
            <button type="button" id="modal-force-close"
                    class="w-7 h-7 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors flex-shrink-0">
                <i class="ti ti-x text-sm"></i>
            </button>
        </div>
        <form id="force-delete-form" method="POST" enctype="multipart/form-data">
            @csrf @method('DELETE')
            <div class="px-6 py-5 space-y-4">
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg px-4 py-3 text-xs text-red-600 dark:text-red-400">
                    <strong>This action is irreversible.</strong> All files will be permanently removed from disk. A formal authorisation letter is required.
                </div>
                <div>
                    <label class="field-label" for="force-reason">Reason for Permanent Deletion <span class="text-red-500">*</span></label>
                    <textarea id="force-reason" name="reason" rows="3"
                              class="field-input resize-none"
                              placeholder="Briefly explain why this document is being permanently removed…"
                              minlength="5" maxlength="500"></textarea>
                    <p class="field-hint">5–500 characters. This reason is logged permanently.</p>
                    <p id="force-reason-err" class="field-err-msg hidden"></p>
                </div>
                <div>
                    <label class="field-label" for="force-letter">Upload Authorisation Letter (PDF) <span class="text-red-500">*</span></label>
                    <input type="file" id="force-letter" name="letter" accept="application/pdf,.pdf"
                           class="block w-full text-sm text-slate-500 dark:text-slate-400
                                  file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0
                                  file:text-sm file:font-medium file:bg-slate-100 dark:file:bg-slate-800
                                  file:text-slate-700 dark:file:text-slate-300 hover:file:bg-slate-200
                                  dark:hover:file:bg-slate-700 cursor-pointer">
                    <p class="field-hint mt-1">Official letter from the authorising officer confirming deletion. Stored for audit purposes.</p>
                    <p id="force-letter-err" class="field-err-msg hidden"></p>
                </div>
                <div class="flex items-start gap-2">
                    <input type="checkbox" id="force-confirm" class="mt-0.5 rounded border-slate-300 dark:border-slate-600 text-red-600 focus:ring-red-500">
                    <label for="force-confirm" class="text-xs text-slate-600 dark:text-slate-400 cursor-pointer">
                        I confirm that I have the authority to permanently delete this document and that the uploaded letter is authentic.
                    </label>
                </div>
                <p id="force-confirm-err" class="field-err-msg hidden"></p>
            </div>
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700 flex justify-end gap-3">
                <button type="button" id="modal-force-cancel"
                        class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 font-medium px-4 py-2 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors">
                    Cancel
                </button>
                <button type="submit" id="force-submit-btn"
                        class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold px-5 py-2 rounded-lg transition-colors">
                    <i class="ti ti-trash text-base"></i> Permanently Delete
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Slide-over drawer --}}
<div id="doc-drawer"
     class="fixed inset-0 z-50 flex justify-end"
     style="display:none!important"
     aria-modal="true" role="dialog">
    <div id="drawer-backdrop"
         class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity"
         onclick="closeDrawer()"></div>
    <div id="drawer-panel"
         class="relative z-10 w-full max-w-2xl bg-white dark:bg-slate-900 shadow-2xl flex flex-col h-full translate-x-full transition-transform duration-300 ease-in-out">
        <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-slate-200 dark:border-slate-700 flex-shrink-0">
            <div class="min-w-0">
                <p id="drawer-title" class="text-sm font-semibold text-slate-800 dark:text-slate-100 truncate"></p>
                <p id="drawer-meta" class="text-xs text-slate-400 dark:text-slate-500 mt-0.5"></p>
            </div>
            <button onclick="closeDrawer()"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors flex-shrink-0">
                <i class="ti ti-x"></i>
            </button>
        </div>
        <div id="drawer-info" class="px-5 py-3 border-b border-slate-100 dark:border-slate-700/60 flex flex-wrap gap-x-6 gap-y-2 flex-shrink-0 text-xs text-slate-500 dark:text-slate-400"></div>
        <div id="drawer-reason-wrap" class="px-5 py-3 border-b border-slate-100 dark:border-slate-700/60 flex-shrink-0" style="display:none">
            <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Archive Reason</p>
            <p id="drawer-reason" class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed"></p>
            <p id="drawer-deleted-by" class="text-[10px] text-slate-400 dark:text-slate-500 mt-1"></p>
        </div>
        <div id="drawer-pdf-wrap" class="flex-1 min-h-0 bg-slate-100 dark:bg-slate-800" style="display:none">
            <iframe id="drawer-pdf" src="" class="w-full h-full border-0"></iframe>
        </div>
        <div id="drawer-no-pdf" class="flex-1 min-h-0 flex items-center justify-center text-slate-400 dark:text-slate-500 text-sm" style="display:none">
            <div class="text-center">
                <i class="ti ti-file-off text-3xl mb-2 block"></i>
                No PDF file attached
            </div>
        </div>
        <div id="drawer-actions" class="flex items-center gap-3 px-5 py-4 border-t border-slate-200 dark:border-slate-700 flex-shrink-0"></div>
    </div>
</div>

{{-- Bulk action bar --}}
@php $canRestore = auth()->user()->hasPrivilege('documents.restore'); @endphp
@php $canForce   = auth()->user()->hasPrivilege('documents.force-delete'); @endphp

@if($documents->isNotEmpty())
<div id="bulk-bar"
     class="hidden fixed bottom-0 left-0 right-0 z-40 bg-white dark:bg-slate-900 border-t border-slate-200 dark:border-slate-700 shadow-2xl px-6 py-3 flex items-center gap-3 flex-wrap">
    <span class="text-sm font-semibold text-slate-700 dark:text-slate-200">
        <span id="bulk-count">0</span> selected
    </span>
    <div class="flex-1"></div>
    <button type="button" id="bulk-deselect"
            class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 transition-colors">
        Deselect all
    </button>
    @if($canRestore)
    <button type="button" id="bulk-restore-btn"
            class="inline-flex items-center gap-2 bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
        <i class="ti ti-restore text-base"></i> Restore Selected
    </button>
    @endif
    @if($canForce)
    <button type="button" id="bulk-force-btn"
            class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
        <i class="ti ti-trash text-base"></i> Delete Forever
    </button>
    @endif
</div>

<form id="bulk-restore-form" method="POST" action="{{ route('documents.trash.bulk-restore') }}" style="display:none">
    @csrf
    <div id="bulk-restore-ids"></div>
</form>
@if($canForce)
<form id="bulk-force-form" method="POST" action="{{ route('documents.trash.bulk-force-destroy') }}" style="display:none">
    @csrf @method('DELETE')
    <div id="bulk-force-ids"></div>
</form>
@endif
@endif

<div class="mb-6 flex items-center justify-between gap-4">
    <p class="text-sm text-slate-500 dark:text-slate-400">
        Archived documents are hidden from all public and guest views. They remain accessible here for review.
        @if($canRestore) Restore to make them active again. @endif
        @if($canForce) Permanent deletion requires a formal authorisation letter. @endif
    </p>
    @if($documents->isNotEmpty())
    <div class="flex items-center gap-3 flex-shrink-0">
        <label class="flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400 cursor-pointer select-none">
            <input type="checkbox" id="select-all-trash"
                   class="rounded border-slate-300 dark:border-slate-600 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-0 cursor-pointer">
            Select all
        </label>
        <span class="text-xs text-slate-400 dark:text-slate-500 whitespace-nowrap">{{ $documents->count() }} {{ Str::plural('document', $documents->count()) }}</span>
    </div>
    @endif
</div>

@if($documents->isEmpty())
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-8 py-16 text-center">
    <div class="w-14 h-14 rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center mx-auto mb-4">
        <i class="ti ti-archive-off text-2xl text-slate-400 dark:text-slate-500"></i>
    </div>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Archive is empty</p>
    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">No documents have been archived.</p>
    <a href="{{ route('documents.index') }}" class="mt-4 inline-flex items-center gap-1.5 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
        <i class="ti ti-arrow-left text-sm"></i> Back to Documents
    </a>
</div>
@else
<div class="space-y-3">
    @foreach($documents as $doc)
    @php
        $deletionEntry = $doc->statusHistory->first();
        $contextName   = $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name ?? '—';
    @endphp
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-5 py-4">
        <div class="flex items-start gap-4">
            {{-- Checkbox --}}
            <div class="flex items-center pt-0.5 flex-shrink-0">
                <input type="checkbox"
                       class="trash-checkbox rounded border-slate-300 dark:border-slate-600 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-0 cursor-pointer"
                       value="{{ $doc->id }}">
            </div>
            {{-- Icon --}}
            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 bg-amber-50 dark:bg-amber-900/20">
                <i class="ti ti-archive text-base text-amber-500 dark:text-amber-400"></i>
            </div>

            {{-- Details --}}
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $doc->title }}</p>
                <div class="flex items-center gap-2 mt-0.5 flex-wrap text-xs text-slate-400 dark:text-slate-500">
                    <span>{{ $doc->department->name }}</span>
                    <span class="text-slate-300 dark:text-slate-700">·</span>
                    <span>{{ $contextName }}</span>
                    <span class="text-slate-300 dark:text-slate-700">·</span>
                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400">
                        {{ \App\Models\Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type }}
                    </span>
                </div>

                @if($deletionEntry)
                <div class="mt-2 flex items-start gap-1.5">
                    <i class="ti ti-message-2 text-xs text-slate-300 dark:text-slate-600 mt-0.5 flex-shrink-0"></i>
                    <p class="text-xs text-slate-500 dark:text-slate-400 leading-snug">{{ $deletionEntry->note }}</p>
                </div>
                <p class="mt-1 text-[10px] text-slate-300 dark:text-slate-600">
                    Archived {{ $doc->deleted_at->format('d M Y, H:i') }}
                    @if($deletionEntry->actor) · by {{ $deletionEntry->actor->name }} @endif
                </p>
                @else
                <p class="mt-1 text-[10px] text-slate-300 dark:text-slate-600">
                    Archived {{ $doc->deleted_at->format('d M Y, H:i') }}
                </p>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex items-center gap-2 flex-shrink-0">
                <button type="button"
                        class="view-doc-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-1.5 rounded-lg transition-all"
                        data-id="{{ $doc->id }}">
                    <i class="ti ti-eye text-sm"></i>
                    <span class="hidden sm:inline text-xs">View</span>
                </button>

                @if($canRestore)
                <form method="POST" action="{{ route('documents.restore', $doc->id) }}" class="restore-form" data-title="{{ e($doc->title) }}">
                    @csrf
                    <button type="button"
                            class="restore-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-green-400 dark:hover:border-green-500 text-slate-600 dark:text-slate-300 hover:text-green-600 dark:hover:text-green-400 text-sm font-medium px-3 py-1.5 rounded-lg transition-all">
                        <i class="ti ti-restore text-sm"></i>
                        <span class="hidden sm:inline text-xs">Restore</span>
                    </button>
                </form>
                @endif

                @if($canForce)
                <button type="button"
                        class="open-force-modal-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-3 py-1.5 rounded-lg transition-all"
                        data-id="{{ $doc->id }}"
                        data-title="{{ e($doc->title) }}"
                        data-action="{{ route('documents.force-destroy', $doc->id) }}">
                    <i class="ti ti-trash text-sm"></i>
                    <span class="hidden sm:inline text-xs">Delete Forever</span>
                </button>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif

@push('scripts')
@if($documents->isNotEmpty())
<script>
(function () {
    try {
        const bulkBar        = document.getElementById('bulk-bar');
        const bulkCount      = document.getElementById('bulk-count');
        const bulkDeselect   = document.getElementById('bulk-deselect');
        const bulkRestoreBtn = document.getElementById('bulk-restore-btn');
        const bulkForceBtn   = document.getElementById('bulk-force-btn');
        const restoreForm    = document.getElementById('bulk-restore-form');
        const restoreIds     = document.getElementById('bulk-restore-ids');
        const forceForm      = document.getElementById('bulk-force-form');
        const forceIds       = document.getElementById('bulk-force-ids');
        const selectAll      = document.getElementById('select-all-trash');
        const isDark         = () => document.documentElement.classList.contains('dark');

        if (!bulkBar) return;

        function getChecked() {
            return Array.from(document.querySelectorAll('.trash-checkbox:checked'));
        }

        function buildIds(container, checked) {
            container.innerHTML = '';
            checked.forEach(function (cb) {
                const inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = 'ids[]';
                inp.value = cb.value;
                container.appendChild(inp);
            });
        }

        function syncBar() {
            const checked = getChecked();
            const n = checked.length;
            bulkCount.textContent = n;
            if (n > 0) {
                bulkBar.classList.remove('hidden');
                bulkBar.classList.add('flex');
                document.body.style.paddingBottom = '64px';
            } else {
                bulkBar.classList.add('hidden');
                bulkBar.classList.remove('flex');
                document.body.style.paddingBottom = '';
            }
            if (selectAll) {
                const all = document.querySelectorAll('.trash-checkbox');
                selectAll.indeterminate = n > 0 && n < all.length;
                selectAll.checked = all.length > 0 && n === all.length;
            }
        }

        document.querySelectorAll('.trash-checkbox').forEach(cb => cb.addEventListener('change', syncBar));

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.trash-checkbox').forEach(cb => { cb.checked = selectAll.checked; });
                syncBar();
            });
        }

        bulkDeselect.addEventListener('click', function () {
            document.querySelectorAll('.trash-checkbox').forEach(cb => { cb.checked = false; });
            if (selectAll) { selectAll.checked = false; selectAll.indeterminate = false; }
            syncBar();
        });

        if (bulkRestoreBtn && restoreForm) {
            bulkRestoreBtn.addEventListener('click', async function () {
                const checked = getChecked();
                if (!checked.length) return;
                const { isConfirmed } = await Swal.fire({
                    title: 'Restore ' + checked.length + ' ' + (checked.length === 1 ? 'Document' : 'Documents') + '?',
                    html: '<p class="text-sm">Selected documents will be restored from the archive and made active again.</p>',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Restore',
                    confirmButtonColor: '#22c55e',
                    cancelButtonText: 'Cancel',
                    background: isDark() ? '#1e293b' : '#fff',
                    color: isDark() ? '#f1f5f9' : '#1e293b',
                });
                if (!isConfirmed) return;
                buildIds(restoreIds, checked);
                restoreForm.submit();
            });
        }

        if (bulkForceBtn && forceForm) {
            bulkForceBtn.addEventListener('click', async function () {
                const checked = getChecked();
                if (!checked.length) return;
                const { isConfirmed } = await Swal.fire({
                    title: 'Permanently Delete ' + checked.length + ' ' + (checked.length === 1 ? 'Document' : 'Documents') + '?',
                    html: '<p class="text-sm mb-2">All files for the selected documents will be <strong>permanently removed from disk</strong>.</p><p class="text-xs text-red-500 font-medium">This cannot be undone.</p>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Delete Forever',
                    confirmButtonColor: '#ef4444',
                    cancelButtonText: 'Cancel',
                    background: isDark() ? '#1e293b' : '#fff',
                    color: isDark() ? '#f1f5f9' : '#1e293b',
                });
                if (!isConfirmed) return;
                buildIds(forceIds, checked);
                forceForm.submit();
            });
        }

    } catch (e) {
        console.error('Archive bulk init failed:', e);
    }
})();
</script>
@endif

<script>
try {
    const isDark  = () => document.documentElement.classList.contains('dark');
    const docs    = JSON.parse(document.getElementById('trash-docs').textContent);
    const docMap  = Object.fromEntries(docs.map(d => [String(d.id), d]));

    // ── Force-delete modal ────────────────────────────────────────────────────
    const forceModal    = document.getElementById('modal-force-delete');
    const forceForm     = document.getElementById('force-delete-form');
    const forceDocTitle = document.getElementById('modal-force-doc-title');
    const forceReason   = document.getElementById('force-reason');
    const forceLetter   = document.getElementById('force-letter');
    const forceConfirm  = document.getElementById('force-confirm');
    const forceReasonErr  = document.getElementById('force-reason-err');
    const forceLetterErr  = document.getElementById('force-letter-err');
    const forceConfirmErr = document.getElementById('force-confirm-err');

    function openForceModal(id, title, action) {
        forceForm.action = action;
        forceDocTitle.textContent = title;
        forceReason.value = '';
        if (forceLetter) forceLetter.value = '';
        if (forceConfirm) forceConfirm.checked = false;
        [forceReasonErr, forceLetterErr, forceConfirmErr].forEach(el => { if(el) el.classList.add('hidden'); });
        forceModal.style.setProperty('display', 'flex', 'important');
    }

    function closeForceModal() {
        forceModal.style.setProperty('display', 'none', 'important');
    }

    if (forceModal) {
        document.getElementById('modal-force-close')?.addEventListener('click', closeForceModal);
        document.getElementById('modal-force-cancel')?.addEventListener('click', closeForceModal);
        document.getElementById('modal-force-backdrop')?.addEventListener('click', closeForceModal);

        forceForm?.addEventListener('submit', function (e) {
            let valid = true;
            const reason = forceReason?.value?.trim() ?? '';
            if (reason.length < 5) {
                forceReasonErr.textContent = 'Reason must be at least 5 characters.';
                forceReasonErr.classList.remove('hidden');
                valid = false;
            } else {
                forceReasonErr?.classList.add('hidden');
            }

            if (!forceLetter?.files?.length) {
                forceLetterErr.textContent = 'An authorisation letter PDF is required.';
                forceLetterErr.classList.remove('hidden');
                valid = false;
            } else {
                forceLetterErr?.classList.add('hidden');
            }

            if (!forceConfirm?.checked) {
                forceConfirmErr.textContent = 'You must confirm you have authority to perform this action.';
                forceConfirmErr.classList.remove('hidden');
                valid = false;
            } else {
                forceConfirmErr?.classList.add('hidden');
            }

            if (!valid) e.preventDefault();
        });
    }

    document.querySelectorAll('.open-force-modal-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            openForceModal(this.dataset.id, this.dataset.title, this.dataset.action);
        });
    });

    // ── Drawer helpers ────────────────────────────────────────────────────────
    const drawer  = document.getElementById('doc-drawer');
    const panel   = document.getElementById('drawer-panel');

    window.closeDrawer = function () {
        panel.style.transform = 'translateX(100%)';
        setTimeout(() => { drawer.style.setProperty('display', 'none', 'important'); }, 300);
        document.getElementById('drawer-pdf').src = '';
    };

    function openDrawer(doc) {
        document.getElementById('drawer-title').textContent = doc.title;
        document.getElementById('drawer-meta').textContent  = `${doc.department} · ${doc.context_type}: ${doc.context_name}`;

        const statusColors = {
            verified: 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400',
            uploaded: 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
            review:   'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-400',
            processing: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
            ocr_pending: 'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400',
            failed:   'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-400',
        };
        const visColor = doc.visibility === 'public'
            ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400'
            : 'bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400';
        const visLabel = doc.visibility === 'public' ? 'Public' : 'Authenticated Only';
        document.getElementById('drawer-info').innerHTML = `
            <span><span class="font-medium text-slate-600 dark:text-slate-300">Type:</span> ${doc.document_type}</span>
            <span><span class="font-medium text-slate-600 dark:text-slate-300">Status:</span>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ml-0.5 ${statusColors[doc.status] ?? statusColors.uploaded}">${doc.status}</span>
            </span>
            <span><span class="font-medium text-slate-600 dark:text-slate-300">Visibility:</span>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium ml-0.5 ${visColor}">${visLabel}</span>
            </span>
            <span><span class="font-medium text-slate-600 dark:text-slate-300">Uploaded by:</span> ${doc.uploaded_by} on ${doc.uploaded_at}</span>
        `;

        const reasonWrap = document.getElementById('drawer-reason-wrap');
        if (doc.deletion_reason && doc.deletion_reason !== '—') {
            document.getElementById('drawer-reason').textContent = doc.deletion_reason;
            document.getElementById('drawer-deleted-by').textContent =
                `Archived ${doc.deleted_at}${doc.deleted_by !== '—' ? ' · by ' + doc.deleted_by : ''}`;
            reasonWrap.style.display = '';
        } else {
            reasonWrap.style.display = 'none';
        }

        const pdfWrap  = document.getElementById('drawer-pdf-wrap');
        const noPdf    = document.getElementById('drawer-no-pdf');
        const pdfFrame = document.getElementById('drawer-pdf');
        if (doc.pdf_url) {
            pdfFrame.src           = doc.pdf_url;
            pdfWrap.style.display  = '';
            noPdf.style.display    = 'none';
        } else {
            pdfFrame.src           = '';
            pdfWrap.style.display  = 'none';
            noPdf.style.display    = '';
        }

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let actionsHtml = '';

        if (doc.can_restore) {
            actionsHtml += `
            <form method="POST" action="${doc.restore_url}" class="drawer-restore-form" data-title="${doc.title.replace(/"/g,'&quot;')}">
                <input type="hidden" name="_token" value="${csrf}">
                <button type="button" class="drawer-restore-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-green-400 dark:hover:border-green-500 text-slate-600 dark:text-slate-300 hover:text-green-600 dark:hover:text-green-400 text-sm font-medium px-4 py-2 rounded-lg transition-all">
                    <i class="ti ti-restore text-sm"></i> Restore
                </button>
            </form>`;
        }

        if (doc.can_force_delete) {
            actionsHtml += `
            <button type="button" class="drawer-force-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-4 py-2 rounded-lg transition-all"
                    data-id="${doc.id}" data-title="${doc.title.replace(/"/g,'&quot;')}" data-action="${doc.destroy_url}">
                <i class="ti ti-trash text-sm"></i> Delete Forever
            </button>`;
        }

        actionsHtml += `
            <button onclick="closeDrawer()" class="ml-auto text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">Close</button>`;
        document.getElementById('drawer-actions').innerHTML = actionsHtml;

        const actionsEl = document.getElementById('drawer-actions');
        actionsEl.querySelector('.drawer-restore-btn')?.addEventListener('click', function () {
            const form  = this.closest('.drawer-restore-form');
            const title = form.dataset.title;
            Swal.fire({
                title: 'Restore Document?',
                html: `<p class="text-sm">Restoring <strong>${title}</strong> will make it visible again with its previous status.</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Restore',
                confirmButtonColor: '#22c55e',
                cancelButtonText: 'Cancel',
                background: isDark() ? '#1e293b' : '#ffffff',
                color: isDark() ? '#f1f5f9' : '#0f172a',
            }).then(r => { if (r.isConfirmed) form.submit(); });
        });

        actionsEl.querySelector('.drawer-force-btn')?.addEventListener('click', function () {
            closeDrawer();
            setTimeout(() => openForceModal(this.dataset.id, this.dataset.title, this.dataset.action), 350);
        });

        drawer.style.setProperty('display', 'flex', 'important');
        requestAnimationFrame(() => { panel.style.transform = 'translateX(0)'; });
    }

    document.querySelectorAll('.view-doc-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const doc = docMap[this.dataset.id];
            if (doc) openDrawer(doc);
        });
    });

    document.querySelectorAll('.restore-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const form  = this.closest('.restore-form');
            const title = form.dataset.title;
            Swal.fire({
                title: 'Restore Document?',
                html: `<p class="text-sm">Restoring <strong>${title}</strong> will make it visible again with its previous status.</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Restore',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#22c55e',
                background: isDark() ? '#1e293b' : '#ffffff',
                color: isDark() ? '#f1f5f9' : '#0f172a',
            }).then(r => { if (r.isConfirmed) form.submit(); });
        });
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeDrawer();
            closeForceModal();
        }
    });

} catch (e) {
    console.error('Archive page init failed:', e);
}
</script>
@endpush

</x-layout>

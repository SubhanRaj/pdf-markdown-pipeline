<x-layout
    title="Trash"
    page-title="Document Trash"
    page-subtitle="Soft-deleted documents — view, restore or permanently remove"
>

<x-slot:breadcrumb>
    <a href="{{ route('home') }}">Home</a>
    <i class="ti ti-chevron-right text-xs text-slate-400"></i>
    <a href="{{ route('documents.index') }}">Documents</a>
    <i class="ti ti-chevron-right text-xs text-slate-400"></i>
    <span>Trash</span>
</x-slot:breadcrumb>

{{-- Data island for drawer --}}
<script id="trash-docs" type="application/json">@json($trashData)</script>

{{-- Slide-over drawer --}}
<div id="doc-drawer"
     class="fixed inset-0 z-50 flex justify-end"
     style="display:none!important"
     aria-modal="true" role="dialog">
    {{-- Backdrop --}}
    <div id="drawer-backdrop"
         class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity"
         onclick="closeDrawer()"></div>

    {{-- Panel --}}
    <div id="drawer-panel"
         class="relative z-10 w-full max-w-2xl bg-white dark:bg-slate-900 shadow-2xl flex flex-col h-full translate-x-full transition-transform duration-300 ease-in-out">

        {{-- Header --}}
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

        {{-- Metadata strip --}}
        <div id="drawer-info" class="px-5 py-3 border-b border-slate-100 dark:border-slate-700/60 flex flex-wrap gap-x-6 gap-y-2 flex-shrink-0 text-xs text-slate-500 dark:text-slate-400"></div>

        {{-- Deletion reason --}}
        <div id="drawer-reason-wrap" class="px-5 py-3 border-b border-slate-100 dark:border-slate-700/60 flex-shrink-0" style="display:none">
            <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1">Deletion Reason</p>
            <p id="drawer-reason" class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed"></p>
            <p id="drawer-deleted-by" class="text-[10px] text-slate-400 dark:text-slate-500 mt-1"></p>
        </div>

        {{-- PDF viewer --}}
        <div id="drawer-pdf-wrap" class="flex-1 min-h-0 bg-slate-100 dark:bg-slate-800" style="display:none">
            <iframe id="drawer-pdf" src="" class="w-full h-full border-0"></iframe>
        </div>
        <div id="drawer-no-pdf" class="flex-1 min-h-0 flex items-center justify-center text-slate-400 dark:text-slate-500 text-sm" style="display:none">
            <div class="text-center">
                <i class="ti ti-file-off text-3xl mb-2 block"></i>
                No PDF file attached
            </div>
        </div>

        {{-- Footer actions --}}
        <div id="drawer-actions" class="flex items-center gap-3 px-5 py-4 border-t border-slate-200 dark:border-slate-700 flex-shrink-0"></div>
    </div>
</div>

<div class="mb-6 flex items-center justify-between gap-4">
    <p class="text-sm text-slate-500 dark:text-slate-400">
        Soft-deleted documents are hidden from public views. Restore to make them accessible again, or permanently delete to remove all files from disk.
    </p>
    @if(auth()->user()->isAdmin() && $documents->isNotEmpty())
    <span class="text-xs text-slate-400 dark:text-slate-500 whitespace-nowrap">{{ $documents->count() }} {{ Str::plural('document', $documents->count()) }}</span>
    @endif
</div>

@if($documents->isEmpty())
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-8 py-16 text-center">
    <div class="w-14 h-14 rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center mx-auto mb-4">
        <i class="ti ti-trash-off text-2xl text-slate-400 dark:text-slate-500"></i>
    </div>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-300">Trash is empty</p>
    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">No documents have been moved to trash.</p>
    <a href="{{ route('documents.index') }}" class="mt-4 inline-flex items-center gap-1.5 text-sm text-indigo-600 dark:text-indigo-400 hover:underline">
        <i class="ti ti-arrow-left text-sm"></i> Back to Documents
    </a>
</div>
@else
<div class="space-y-3">
    @foreach($documents as $doc)
    @php
        $deletionEntry = $doc->statusHistory->first();
        $contextName   = $doc->section?->name ?? $doc->ruleSet?->name ?? '—';
        $isRuleSetDoc  = $doc->section_id === null && $doc->rule_set_id !== null;
    @endphp
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-5 py-4">
        <div class="flex items-start gap-4">
            {{-- Icon --}}
            <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 bg-red-50 dark:bg-red-900/20">
                <i class="ti ti-file-x text-base text-red-400 dark:text-red-500"></i>
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
                    Deleted {{ $doc->deleted_at->format('d M Y, H:i') }}
                    @if($deletionEntry->actor) · by {{ $deletionEntry->actor->name }} @endif
                </p>
                @else
                <p class="mt-1 text-[10px] text-slate-300 dark:text-slate-600">
                    Deleted {{ $doc->deleted_at->format('d M Y, H:i') }}
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

                <form method="POST" action="{{ route('documents.restore', $doc->id) }}" class="restore-form" data-title="{{ e($doc->title) }}">
                    @csrf
                    <button type="button"
                            class="restore-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-green-400 dark:hover:border-green-500 text-slate-600 dark:text-slate-300 hover:text-green-600 dark:hover:text-green-400 text-sm font-medium px-3 py-1.5 rounded-lg transition-all">
                        <i class="ti ti-restore text-sm"></i>
                        <span class="hidden sm:inline text-xs">Restore</span>
                    </button>
                </form>

                @if(auth()->user()->isAdmin())
                <form method="POST" action="{{ route('documents.force-destroy', $doc->id) }}" class="force-delete-form" data-title="{{ e($doc->title) }}">
                    @csrf @method('DELETE')
                    <button type="button"
                            class="force-delete-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-3 py-1.5 rounded-lg transition-all">
                        <i class="ti ti-trash text-sm"></i>
                        <span class="hidden sm:inline text-xs">Delete Forever</span>
                    </button>
                </form>
                @endif
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif

@push('scripts')
<script>
try {
    const isDark  = () => document.documentElement.classList.contains('dark');
    const docs    = JSON.parse(document.getElementById('trash-docs').textContent);
    const docMap  = Object.fromEntries(docs.map(d => [String(d.id), d]));

    // ── Drawer helpers ────────────────────────────────────────────────────────
    const drawer  = document.getElementById('doc-drawer');
    const panel   = document.getElementById('drawer-panel');

    window.closeDrawer = function () {
        panel.style.transform = 'translateX(100%)';
        setTimeout(() => { drawer.style.setProperty('display', 'none', 'important'); }, 300);
        document.getElementById('drawer-pdf').src = '';
    };

    function openDrawer(doc) {
        // Title + meta
        document.getElementById('drawer-title').textContent = doc.title;
        document.getElementById('drawer-meta').textContent  = `${doc.department} · ${doc.context_type}: ${doc.context_name}`;

        // Info strip
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

        // Deletion reason
        const reasonWrap = document.getElementById('drawer-reason-wrap');
        if (doc.deletion_reason && doc.deletion_reason !== '—') {
            document.getElementById('drawer-reason').textContent = doc.deletion_reason;
            document.getElementById('drawer-deleted-by').textContent =
                `Deleted ${doc.deleted_at}${doc.deleted_by !== '—' ? ' · by ' + doc.deleted_by : ''}`;
            reasonWrap.style.display = '';
        } else {
            reasonWrap.style.display = 'none';
        }

        // PDF
        const pdfWrap  = document.getElementById('drawer-pdf-wrap');
        const noPdf    = document.getElementById('drawer-no-pdf');
        const pdfFrame = document.getElementById('drawer-pdf');
        if (doc.pdf_url) {
            pdfFrame.src      = doc.pdf_url;
            pdfWrap.style.display  = '';
            noPdf.style.display    = 'none';
        } else {
            pdfFrame.src      = '';
            pdfWrap.style.display  = 'none';
            noPdf.style.display    = '';
        }

        // Footer actions
        const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let actionsHtml = `
            <form method="POST" action="${doc.restore_url}" class="drawer-restore-form" data-title="${doc.title.replace(/"/g,'&quot;')}">
                <input type="hidden" name="_token" value="${csrf}">
                <button type="button" class="drawer-restore-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-green-400 dark:hover:border-green-500 text-slate-600 dark:text-slate-300 hover:text-green-600 dark:hover:text-green-400 text-sm font-medium px-4 py-2 rounded-lg transition-all">
                    <i class="ti ti-restore text-sm"></i> Restore
                </button>
            </form>`;
        if (doc.is_admin) {
            actionsHtml += `
            <form method="POST" action="${doc.destroy_url}" class="drawer-force-form" data-title="${doc.title.replace(/"/g,'&quot;')}">
                <input type="hidden" name="_token" value="${csrf}">
                <input type="hidden" name="_method" value="DELETE">
                <button type="button" class="drawer-force-btn inline-flex items-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-red-400 dark:hover:border-red-500 text-slate-600 dark:text-slate-300 hover:text-red-600 dark:hover:text-red-400 text-sm font-medium px-4 py-2 rounded-lg transition-all">
                    <i class="ti ti-trash text-sm"></i> Delete Forever
                </button>
            </form>`;
        }
        actionsHtml += `
            <button onclick="closeDrawer()" class="ml-auto text-xs text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 transition-colors">Close</button>`;
        document.getElementById('drawer-actions').innerHTML = actionsHtml;

        // Wire up drawer action buttons
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
            const form  = this.closest('.drawer-force-form');
            const title = form.dataset.title;
            Swal.fire({
                title: 'Permanently Delete?',
                html: `<p class="text-sm mb-2">This will <strong>permanently remove</strong> all files for <strong>${title}</strong> from disk.</p><p class="text-xs text-red-500 font-medium">This cannot be undone.</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Delete Forever',
                confirmButtonColor: '#ef4444',
                cancelButtonText: 'Cancel',
                background: isDark() ? '#1e293b' : '#ffffff',
                color: isDark() ? '#f1f5f9' : '#0f172a',
            }).then(r => { if (r.isConfirmed) form.submit(); });
        });

        // Show drawer
        drawer.style.setProperty('display', 'flex', 'important');
        requestAnimationFrame(() => { panel.style.transform = 'translateX(0)'; });
    }

    // ── View buttons ──────────────────────────────────────────────────────────
    document.querySelectorAll('.view-doc-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const doc = docMap[this.dataset.id];
            if (doc) openDrawer(doc);
        });
    });

    // ── Row-level restore buttons ─────────────────────────────────────────────
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

    // ── Row-level force delete buttons ────────────────────────────────────────
    document.querySelectorAll('.force-delete-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const form  = this.closest('.force-delete-form');
            const title = form.dataset.title;
            Swal.fire({
                title: 'Permanently Delete?',
                html: `<p class="text-sm mb-2">This will <strong>permanently remove</strong> all files for <strong>${title}</strong> from disk.</p><p class="text-xs text-red-500 font-medium">This cannot be undone.</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Delete Forever',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ef4444',
                background: isDark() ? '#1e293b' : '#ffffff',
                color: isDark() ? '#f1f5f9' : '#0f172a',
            }).then(r => { if (r.isConfirmed) form.submit(); });
        });
    });

    // Close drawer on Escape
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDrawer(); });

} catch (e) {
    console.error('Trash page init failed:', e);
}
</script>
@endpush

</x-layout>

<x-layout
    title="Trash"
    page-title="Document Trash"
    page-subtitle="Soft-deleted documents — restore or permanently remove"
>

<x-slot:breadcrumb>
    <a href="{{ route('home') }}">Home</a>
    <i class="ti ti-chevron-right text-xs text-slate-400"></i>
    <a href="{{ route('documents.index') }}">Documents</a>
    <i class="ti ti-chevron-right text-xs text-slate-400"></i>
    <span>Trash</span>
</x-slot:breadcrumb>

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
    const isDark = () => document.documentElement.classList.contains('dark');

    // Restore buttons
    document.querySelectorAll('.restore-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const form  = this.closest('.restore-form');
            const title = form.dataset.title;
            Swal.fire({
                title: 'Restore Document?',
                html: `<p class="text-sm text-gray-500">Restoring <strong>${title}</strong> will make it visible again with its previous status.</p>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '<i class="ti ti-restore mr-1"></i> Restore',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#22c55e',
                background: isDark() ? '#1e293b' : '#ffffff',
                color: isDark() ? '#f1f5f9' : '#0f172a',
            }).then(result => {
                if (result.isConfirmed) form.submit();
            });
        });
    });

    // Force delete buttons
    document.querySelectorAll('.force-delete-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const form  = this.closest('.force-delete-form');
            const title = form.dataset.title;
            Swal.fire({
                title: 'Permanently Delete?',
                html: `<p class="text-sm text-gray-500 mb-2">This will <strong>permanently remove</strong> all files for <strong>${title}</strong> from disk and delete the record from the database.</p><p class="text-xs text-red-500 font-medium">This action cannot be undone.</p>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: '<i class="ti ti-trash mr-1"></i> Delete Forever',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ef4444',
                background: isDark() ? '#1e293b' : '#ffffff',
                color: isDark() ? '#f1f5f9' : '#0f172a',
            }).then(result => {
                if (result.isConfirmed) form.submit();
            });
        });
    });
} catch (e) {
    console.error('Trash page init failed:', e);
}
</script>
@endpush

</x-layout>

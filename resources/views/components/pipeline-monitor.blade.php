<?php

use App\Http\Controllers\DocumentController;
use App\Models\Document;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public ?string $activeStatus = null;

    /** @var string[] */
    public array $pipelineStatuses = ['uploaded', 'processing', 'ocr_pending', 'review', 'failed'];

    public function mount(?string $activeStatus = null): void
    {
        $this->activeStatus = in_array($activeStatus, $this->pipelineStatuses, true) ? $activeStatus : null;
    }

    #[Computed]
    public function counts()
    {
        return Document::whereIn('status', $this->pipelineStatuses)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');
    }

    #[Computed]
    public function documents()
    {
        return Document::with(['department', 'section', 'division', 'ruleSet', 'folder', 'user:id,name'])
            ->whereIn('status', $this->activeStatus ? [$this->activeStatus] : $this->pipelineStatuses)
            ->orderByDesc('updated_at')
            ->paginate(30);
    }

    #[Computed]
    public function hasInFlight(): bool
    {
        return $this->documents->contains(fn (Document $doc) => in_array($doc->status, ['processing', 'ocr_pending'], true));
    }

    // Reuses DocumentController::convert()'s authorization check and status transition
    // verbatim (same route the old fetch()-based button hit) rather than re-deriving it here.
    public function convert(int $documentId): void
    {
        $response = app(DocumentController::class)->convert($documentId);

        if ($response->getStatusCode() >= 400) {
            $message = json_decode($response->getContent(), true)['message'] ?? 'Could not start conversion.';
            $this->addError('convert', $message);
        }
    }
}; ?>

@php
    $statusColors = [
        'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
        'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
        'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
        'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
        'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    ];
@endphp

<div @if($this->hasInFlight) wire:poll.5s @endif>

@error('convert')
<div class="mb-4 px-4 py-2 rounded-lg bg-red-50 dark:bg-red-950/40 text-red-600 dark:text-red-400 text-sm">{{ $message }}</div>
@enderror

{{-- ── Status tabs ──────────────────────────────────────────────────────────── --}}
<div class="mb-4 flex items-center gap-1 flex-wrap border-b border-slate-200 dark:border-slate-700 pb-0">
    <a href="{{ route('documents.pipeline') }}"
       class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors
              {{ ! $activeStatus ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600' }}">
        All <span class="ml-1 text-[10px] text-slate-400">{{ $this->counts->sum() }}</span>
    </a>
    @foreach($pipelineStatuses as $s)
    @php $meta = \App\Models\Document::STATUSES[$s]; @endphp
    <a href="{{ route('documents.pipeline', ['status' => $s]) }}"
       class="px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors
              {{ $activeStatus === $s ? 'border-indigo-500 text-indigo-600 dark:text-indigo-400' : 'border-transparent text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 hover:border-slate-300 dark:hover:border-slate-600' }}">
        {{ $meta['label'] }} <span class="ml-1 text-[10px] text-slate-400">{{ $this->counts[$s] ?? 0 }}</span>
    </a>
    @endforeach
</div>

@if($this->documents->isEmpty())
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col items-center justify-center py-20 text-center">
    <i class="ti ti-checkbox text-3xl text-slate-200 dark:text-slate-600 mb-3"></i>
    <p class="text-sm text-slate-500 dark:text-slate-400">Nothing in the pipeline right now.</p>
    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Everything is either verified or hasn't been uploaded yet.</p>
</div>
@else
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 dark:border-slate-700 text-left">
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Title</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Context</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Status</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Method</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500">Uploaded</th>
                    <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide text-slate-400 dark:text-slate-500 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($this->documents as $doc)
                @php
                    $statusMeta = \App\Models\Document::STATUSES[$doc->status];
                    $docUrl = match(true) {
                        $doc->folder && $doc->division => route('documents.divisions.folders.show', [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc->folder, $doc]),
                        (bool) $doc->folder            => route('documents.folders.show',           [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->folder, $doc]),
                        (bool) $doc->division           => route('documents.divisions.show',         [$doc->department->levelAlias(), $doc->department, $doc->section, $doc->division, $doc]),
                        (bool) $doc->section             => route('documents.show',                   [$doc->department->levelAlias(), $doc->department, $doc->section, $doc]),
                        default                           => route('documents.rules.show',            [$doc->department->levelAlias(), $doc->department, $doc->ruleSet, $doc]),
                    };
                    $contextName = $doc->folder?->name ?? $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name;
                    $isPolling = in_array($doc->status, ['processing', 'ocr_pending'], true);
                    $canConvert = in_array($doc->status, ['uploaded', 'failed'], true);
                @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors" wire:key="doc-{{ $doc->id }}">
                    <td class="px-4 py-3">
                        <a href="{{ $docUrl }}" class="font-medium text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400">{{ $doc->title }}</a>
                        <p class="text-[10px] text-slate-400 dark:text-slate-500 mt-0.5">{{ \App\Models\Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type }}</p>
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500 dark:text-slate-400">
                        {{ $doc->department->name }}<br>
                        <span class="text-slate-400 dark:text-slate-500">{{ $contextName }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-medium {{ $statusColors[$statusMeta['color']] }}">
                            @if($isPolling)<i class="ti ti-loader-2 animate-spin text-[10px]"></i>@endif
                            {{ $statusMeta['label'] }}
                        </span>
                        @if($doc->metadata['structure_analyzed'] ?? false)
                        <i class="ti ti-layout-2 text-[11px] text-sky-500 dark:text-sky-400 ml-1" title="Structure analyzed (Docling): {{ $doc->metadata['structure_headings_count'] ?? 0 }} headings, {{ $doc->metadata['structure_tables_count'] ?? 0 }} tables"></i>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-400 dark:text-slate-500">
                        {{ $doc->metadata['extraction_method'] ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-400 dark:text-slate-500">
                        {{ $doc->created_at->format('d M Y, H:i') }}
                        @if($doc->user)<br><span>{{ $doc->user->name }}</span>@endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="inline-flex items-center gap-2">
                            @auth
                            @if(auth()->user()->isAdmin() && $canConvert)
                            <button type="button" wire:click="convert({{ $doc->id }})" wire:loading.attr="disabled" wire:target="convert({{ $doc->id }})"
                                    class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline disabled:opacity-50">
                                <span wire:loading.remove wire:target="convert({{ $doc->id }})">{{ $doc->status === 'failed' ? 'Retry' : 'Convert' }}</span>
                                <span wire:loading wire:target="convert({{ $doc->id }})">Starting…</span>
                            </button>
                            @endif
                            @endauth
                            <a href="{{ $docUrl }}" class="text-xs font-medium text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400">View</a>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="px-4 py-3 border-t border-slate-100 dark:border-slate-700">
        {{ $this->documents->links() }}
    </div>
</div>
@endif

</div>

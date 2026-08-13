<?php

use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $documentId;

    #[Computed]
    public function document(): Document
    {
        return Document::findOrFail($this->documentId);
    }

    #[Computed]
    public function isConverting(): bool
    {
        return in_array($this->document->status, ['processing', 'ocr_pending'], true);
    }

    #[Computed]
    public function hasMarkdown(): bool
    {
        return $this->document->markdown_path
            && \Illuminate\Support\Facades\Storage::disk('public')->exists($this->document->markdown_path);
    }

    // Same "is another document's job actively running" check as
    // DocumentController::conversionStatus() — reused verbatim, not re-derived.
    #[Computed]
    public function queuedBehindOtherJob(): bool
    {
        if (! $this->isConverting) {
            return false;
        }

        return DB::table('jobs')->whereNotNull('reserved_at')->pluck('payload')
            ->contains(function (string $payload) {
                $command = json_decode($payload, true)['data']['command'] ?? '';
                preg_match('/"documentId";i:(\d+);/', $command, $m);

                return isset($m[1]) && (int) $m[1] !== $this->documentId;
            });
    }

    #[Computed]
    public function conversionStartedAt(): \Illuminate\Support\Carbon
    {
        return $this->document->statusHistory->sortByDesc('created_at')->first()?->created_at ?? now();
    }

    // Replaces the old fetch()-poll's "status left processing/ocr_pending -> reload the page"
    // behavior verbatim — the rest of the page (convert/edit buttons, PDF/Markdown viewer) is
    // still static Blade computed at initial load, so a full reload is still what re-syncs it.
    public function poll(): void
    {
        if (! $this->isConverting) {
            $this->js('window.location.reload()');
        }
    }
}; ?>

@if($this->isConverting)
<div id="markdown-card" wire:poll.3s="poll"
     class="bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800 rounded-xl px-6 py-8 text-center"
     data-started-at="{{ $this->conversionStartedAt->timestamp * 1000 }}">
    <i class="ti ti-loader-2 animate-spin text-2xl text-indigo-500 dark:text-indigo-400"></i>
    <p class="mt-2 text-sm font-medium text-indigo-700 dark:text-indigo-300">
        {{ $this->document->status === 'ocr_pending' ? 'Running OCR (Hindi + English)…' : 'Converting to Markdown…' }}
    </p>
    <p class="mt-1 text-xs text-indigo-500 dark:text-indigo-400">
        Elapsed <span id="convert-elapsed">0:00</span> — OCR on scanned documents can take several minutes. This page updates automatically.
    </p>
    <p class="mt-1 text-xs text-amber-600 dark:text-amber-400 {{ $this->queuedBehindOtherJob ? '' : 'hidden' }}">
        Waiting in queue — other documents are ahead of this one.
    </p>
</div>
@elseif($this->document->status === 'failed')
<div id="markdown-card" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl px-6 py-8 text-center">
    <i class="ti ti-alert-triangle text-2xl text-red-500 dark:text-red-400"></i>
    <p class="mt-2 text-sm font-medium text-red-600 dark:text-red-400">Conversion failed.</p>
    <p class="mt-1 text-xs text-red-500 dark:text-red-400">Use the "Retry Conversion" button above.</p>
</div>
@elseif(! $this->hasMarkdown)
<div id="markdown-card" class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-6 py-8 text-center">
    <i class="ti ti-markdown-off text-2xl text-slate-300 dark:text-slate-600"></i>
    <p class="mt-2 text-sm text-slate-400 dark:text-slate-500">Not yet converted to Markdown.</p>
</div>
@endif

<?php

namespace App\Console\Commands;

use App\Jobs\ConvertDocumentToMarkdown;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * There's no "Convert All" button in the UI — only per-document. This dispatches the same
 * ConvertDocumentToMarkdown job the "Convert to Markdown" button uses, for every document that
 * needs it, relying on the existing async queue (safe to run with multiple concurrent
 * `queue:work` processes, per DEPLOY.md's "Running more than one queue:work process
 * concurrently" note — the database queue driver row-locks on pop).
 *
 * Guards against double-dispatch by row-locking each document and re-checking its status inside
 * the same transaction that flips it to 'processing' before dispatch — identical to what
 * DocumentController::convert() does for a single manual click, just looped. Running this
 * command twice (or overlapping with someone clicking Convert in the UI) can't grab the same
 * document twice, since the second attempt sees status='processing' and skips it.
 */
class ConvertAllDocuments extends Command
{
    protected $signature = 'documents:convert-all
        {--status=uploaded,failed : Comma-separated statuses to (re)queue for conversion}';

    protected $description = 'Dispatch Convert to Markdown for every document not yet converted (or previously failed), across every rule set';

    public function handle(): int
    {
        $statuses = array_map('trim', explode(',', $this->option('status')));
        $engine   = config('docling.default_ocr_engine');
        $actor    = User::orderBy('id')->first();

        $ids = Document::whereIn('status', $statuses)
            ->orderBy('rule_set_id')
            ->orderBy('created_at')
            ->pluck('id');

        if ($ids->isEmpty()) {
            $this->info('Nothing to convert.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped    = 0;

        foreach ($ids as $id) {
            $result = DB::transaction(function () use ($id, $actor) {
                $document = Document::whereKey($id)->lockForUpdate()->first();

                if (! $document || ! in_array($document->status, ['uploaded', 'review', 'verified', 'failed'], true)) {
                    return 'in-flight';
                }

                $sourcePath = $document->isNativeFormat() ? $document->native_path : $document->original_pdf_path;
                if (! $sourcePath || ! Storage::disk('public')->exists($sourcePath)) {
                    return 'missing-file';
                }

                $oldStatus = $document->status;
                $document->update(['status' => 'processing']);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => $actor?->id,
                    'from_status' => $oldStatus,
                    'to_status'   => 'processing',
                    'note'        => 'Convert to Markdown queued via documents:convert-all bulk dispatch.',
                ]);

                return 'ok';
            });

            if ($result === 'ok') {
                ConvertDocumentToMarkdown::dispatch($id, $engine);
                $dispatched++;
            } else {
                $this->warn("Skipped document #{$id}: {$result}");
                $skipped++;
            }
        }

        $this->info("Dispatched {$dispatched}, skipped {$skipped}. Watch /documents/pipeline for live progress.");

        return self::SUCCESS;
    }
}

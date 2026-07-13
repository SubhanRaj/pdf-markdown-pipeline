<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentStatusHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ConvertDocumentToMarkdown implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    /** Reuses the venv markitdown:install already provisions — pdfminer.six is one of its own dependencies. */
    private string $pythonBin;

    private string $extractorScript;

    public function __construct(public int $documentId)
    {
        $this->pythonBin = base_path('vendor/innobrain/markitdown/python/venv/bin/python3');
        $this->extractorScript = resource_path('python/pdf_structure_extractor.py');
    }

    public function handle(): void
    {
        $document = Document::findOrFail($this->documentId);

        $document->forceFill(['status' => 'processing'])->save();

        $absolutePdfPath = Storage::disk('public')->path($document->original_pdf_path);

        try {
            // Default path is text-layer extraction only — fast (seconds, not minutes) and
            // correct for the vast majority of uploads, which have a real, selectable text
            // layer. OCR is no longer auto-triggered: it's slow, and running it unconditionally
            // on every upload was both wasteful and backed up the single queue worker behind
            // documents that didn't need it. If this pass looks low-quality (near-empty text —
            // i.e. actually a scanned/photographed page), we flag it for a human to decide,
            // via RunOcrExtraction triggered explicitly from the review screen.
            $markdown = $this->tryStructuredExtract($absolutePdfPath);
            $needsOcrReview = ! $this->isGoodQuality($markdown, $absolutePdfPath);

            $markdownPath = preg_replace('/\.pdf$/i', '.md', $document->original_pdf_path);
            Storage::disk('public')->put($markdownPath, $markdown);

            DB::transaction(function () use ($document, $markdownPath, $needsOcrReview) {
                $oldStatus = $document->status;
                $document->update([
                    'markdown_path' => $markdownPath,
                    'status'        => 'review',
                    'metadata'      => array_merge($document->metadata ?? [], [
                        'extraction_method' => 'pdf-text',
                        'needs_ocr_review'  => $needsOcrReview,
                    ]),
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => null,
                    'from_status' => $oldStatus,
                    'to_status'   => 'review',
                    'note'        => 'Converted to Markdown via pdf-text.' . ($needsOcrReview ? ' Text layer looks sparse or unreadable (possible font-encoding issue) — OCR review recommended.' : ''),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('ConvertDocumentToMarkdown failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);

            DB::transaction(function () use ($document, $e) {
                $oldStatus = $document->status;
                $document->forceFill(['status' => 'failed'])->save();

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => null,
                    'from_status' => $oldStatus,
                    'to_status'   => 'failed',
                    'note'        => $e->getMessage(),
                ]);
            });
        }
    }

    /**
     * Structure-aware extraction for native-text PDFs — uses pdfminer's per-character font
     * size/name data to detect headings, bold, and lists. Deliberately bypasses markitdown's
     * own PDF converter, which only calls pdfminer.high_level.extract_text() and is plain-text
     * only by its own documentation ("most style information is ignored").
     */
    private function tryStructuredExtract(string $absolutePdfPath): string
    {
        try {
            $result = Process::timeout(120)->run([$this->pythonBin, $this->extractorScript, '--mode', 'pdf', $absolutePdfPath]);

            return $result->successful() ? trim($result->output()) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Two independent failure modes, both meaning "don't trust this text layer":
     *  - Near-empty text (per page) — no real text layer at all, i.e. scanned/photographed.
     *  - "(cid:NNN)" glyph-ID fallbacks — pdfminer found text but couldn't resolve it to
     *    Unicode because the embedded font has no (or a broken) ToUnicode CMap. Very common
     *    in older government PDFs typeset with legacy non-Unicode Devanagari fonts (Kruti Dev,
     *    Chanakya, DevLys) — the text is technically "selectable" but the codepoints are
     *    meaningless. Char-count alone doesn't catch this: a page full of "(cid:547)" garbage
     *    still has plenty of characters.
     */
    private function isGoodQuality(string $markdown, string $absolutePdfPath): bool
    {
        if (preg_match_all('/\(cid:\d+\)/', $markdown) > 5) {
            return false;
        }

        $charCount = strlen(preg_replace('/\s+/', '', $markdown));
        $pageCount = max(1, $this->countPages($absolutePdfPath));

        return $charCount >= ($pageCount * 40);
    }

    private function countPages(string $absolutePdfPath): int
    {
        $result = Process::run(['pdfinfo', $absolutePdfPath]);
        if ($result->successful() && preg_match('/Pages:\s+(\d+)/', $result->output(), $m)) {
            return (int) $m[1];
        }

        return 1;
    }
}

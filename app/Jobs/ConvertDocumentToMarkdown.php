<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Services\PdfConversionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConvertDocumentToMarkdown implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Bumped from 900 — the added Docling structure pass measured 2-3 min on real 54-112 page
    // documents during evaluation (see STRUCTURE_RESEARCH.md); this leaves headroom for larger ones.
    public int $timeout = 1200;

    private PdfConversionEngine $engine;

    public function __construct(public int $documentId, public string $structureEngine = 'tesseract')
    {
        $this->engine = new PdfConversionEngine();
    }

    public function handle(): void
    {
        $document = Document::findOrFail($this->documentId);

        $document->forceFill(['status' => 'processing'])->save();

        if ($document->isNativeFormat()) {
            $this->handleNative($document);

            return;
        }

        $absolutePdfPath = Storage::disk('public')->path($document->original_pdf_path);

        try {
            // Pass 1 first — pdfminer text-layer extraction, seconds even on 100+ pages, run
            // without Docling's structure yet (nothing to splice in until Pass 0 below runs).
            // This tells us upfront whether the document has usable selectable text, without
            // waiting on Docling's per-page structure-detection time to find out.
            $markdown = $this->engine->tryStructuredExtract($absolutePdfPath);

            // Both sentinels are stripped in a loop (not two independent `^`-anchored matches)
            // since pdf_structure_extractor.py can prepend either or both, in either order.
            $legacyFont = null;
            $sparsePages = null; // [sparse_count, total_pages]
            while (true) {
                if (preg_match('/^<!-- LEGACY_FONT_DETECTED:(.+?) -->\n/', $markdown, $m)) {
                    $legacyFont = $m[1];
                    $markdown = substr($markdown, strlen($m[0]));
                    continue;
                }
                if (preg_match('/^<!-- SPARSE_PAGES:(\d+)\/(\d+) -->\n/', $markdown, $m)) {
                    $sparsePages = [(int) $m[1], (int) $m[2]];
                    $markdown = substr($markdown, strlen($m[0]));
                    continue;
                }
                break;
            }

            // A meaningful fraction of near-blank pages means the document is a mix of typed
            // and scanned pages — confirmed on a real gazette notification where 13 of 15 pages
            // were pure scanned images with zero text layer, but the 2 text-heavy pages alone
            // cleared isGoodQuality()'s whole-document average by 10x, so the scanned pages
            // (which happened to carry all of the Hindi notification text) silently vanished
            // from the output with nothing ever flagged for OCR. 15% is deliberately low —
            // a real quality problem, not noise from the odd blank/divider page.
            $hasSparsePages = $sparsePages !== null && $sparsePages[1] > 0 && ($sparsePages[0] / $sparsePages[1]) >= 0.15;

            $needsOcrReview = $legacyFont !== null || $hasSparsePages || ! $this->engine->isGoodQuality($markdown, $absolutePdfPath);

            // Pass 0 — Docling structure detection (headings/tables/layout). Runs regardless of
            // the quality check above: structure/heading/table splicing is useful for the
            // text-layer render either way, and still needed when OCR ends up running next.
            $structureMeta = $this->engine->runDoclingStructureAnalysis($absolutePdfPath, $document->original_pdf_path, $document->id, $this->structureEngine, $legacyFont !== null);

            $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $document->original_pdf_path);
            $structureAbsolutePath = ($structureMeta !== [] && Storage::disk('public')->exists($structurePath))
                ? Storage::disk('public')->path($structurePath)
                : null;

            // Re-render with Docling's structure spliced in now that it exists — cheap, since
            // pdfminer's own extraction (the part repeated here) is the fast half of this job;
            // Docling's pass above is what actually took the time.
            if ($structureAbsolutePath !== null) {
                $markdown = $this->engine->tryStructuredExtract($absolutePdfPath, $structureAbsolutePath);
                // Two separate calls, not one pattern with a count limit — preg_replace's `^`
                // anchors to the original string's position 0 on every match it counts toward
                // the limit, so it can't strip two consecutive sentinel lines in one call.
                $markdown = preg_replace('/^<!-- LEGACY_FONT_DETECTED:.+? -->\n/', '', $markdown);
                $markdown = preg_replace('/^<!-- SPARSE_PAGES:\d+\/\d+ -->\n/', '', $markdown);
            }

            $markdownPath = preg_replace('/\.pdf$/i', '.md', $document->original_pdf_path);
            if (! Storage::disk('public')->put($markdownPath, $markdown)) {
                throw new \RuntimeException("Failed to write markdown file: {$markdownPath}");
            }

            DB::transaction(function () use ($document, $markdownPath, $needsOcrReview, $legacyFont, $hasSparsePages, $sparsePages, $structureMeta) {
                $oldStatus = $document->status;
                // If the text layer isn't trustworthy, queue OCR immediately rather than making
                // a reviewer click "Run OCR" after seeing the same "needs review" flag we
                // already know about right now.
                $nextStatus = $needsOcrReview ? 'ocr_pending' : 'review';

                $document->update([
                    'markdown_path' => $markdownPath,
                    'status'        => $nextStatus,
                    'metadata'      => array_merge($document->metadata ?? [], [
                        'extraction_method' => 'pdf-text',
                        'needs_ocr_review'  => $needsOcrReview,
                    ], $structureMeta),
                ]);

                $note = 'Converted to Markdown via pdf-text.';
                if ($legacyFont !== null) {
                    $note .= " Detected legacy non-Unicode font ({$legacyFont}) — text layer is unreliable; OCR queued automatically.";
                } elseif ($hasSparsePages) {
                    [$sparse, $total] = $sparsePages;
                    $note .= " {$sparse} of {$total} pages have little to no extractable text (likely a mix of typed and scanned pages) — OCR queued automatically so those pages aren't silently dropped.";
                } elseif ($needsOcrReview) {
                    $note .= ' Text layer looks sparse or unreadable (possible font-encoding issue) — OCR queued automatically.';
                }

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => null,
                    'from_status' => $oldStatus,
                    'to_status'   => $nextStatus,
                    'note'        => $note,
                ]);
            });

            if ($needsOcrReview) {
                RunOcrExtraction::dispatch($document->id, config('ocr.default'));
            }
        } catch (\Throwable $e) {
            $this->markFailed($document, $e);
        }
    }

    /**
     * Word/Excel/PowerPoint/ODT/RTF/TXT/CSV — markitdown reads the original file directly, no
     * PDF/OCR/Docling pipeline involved. Always lands in 'review', never 'ocr_pending': these
     * formats carry no scanned layout, so there's nothing OCR could add.
     */
    private function handleNative(Document $document): void
    {
        try {
            $absoluteNativePath = Storage::disk('public')->path($document->native_path);
            $markdown = $this->engine->convertNativeToMarkdown($absoluteNativePath);

            $markdownPath = preg_replace('/\.[^.\/]+$/', '.md', $document->native_path);
            if (! Storage::disk('public')->put($markdownPath, $markdown)) {
                throw new \RuntimeException("Failed to write markdown file: {$markdownPath}");
            }

            DB::transaction(function () use ($document, $markdownPath) {
                $oldStatus = $document->status;

                $document->update([
                    'markdown_path' => $markdownPath,
                    'status'        => 'review',
                    'metadata'      => array_merge($document->metadata ?? [], [
                        'extraction_method' => 'markitdown-native',
                        'needs_ocr_review'  => false,
                    ]),
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => null,
                    'from_status' => $oldStatus,
                    'to_status'   => 'review',
                    'note'        => 'Converted to Markdown via markitdown (' . strtoupper($document->original_format) . ' — no OCR needed, already selectable text).',
                ]);
            });
        } catch (\Throwable $e) {
            $this->markFailed($document, $e);
        }
    }

    private function markFailed(Document $document, \Throwable $e): void
    {
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

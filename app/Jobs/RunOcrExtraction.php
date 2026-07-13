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

/**
 * OCR-based re-extraction, run only when a human explicitly requests it from the review
 * screen (the text-layer pass in ConvertDocumentToMarkdown flagged the result as low-quality,
 * or the officer just wants to try OCR regardless). Never auto-dispatched.
 */
class RunOcrExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

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

        $document->forceFill(['status' => 'ocr_pending'])->save();

        $absolutePdfPath = Storage::disk('public')->path($document->original_pdf_path);

        try {
            $markdown = $this->runOcr($absolutePdfPath);

            $markdownPath = preg_replace('/\.pdf$/i', '.md', $document->original_pdf_path);
            Storage::disk('public')->put($markdownPath, $markdown);

            DB::transaction(function () use ($document, $markdownPath) {
                $oldStatus = $document->status;
                $document->update([
                    'markdown_path' => $markdownPath,
                    'status'        => 'review',
                    'metadata'      => array_merge($document->metadata ?? [], [
                        'extraction_method' => 'ocr',
                        'needs_ocr_review'  => false,
                    ]),
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => $oldStatus,
                    'to_status'   => 'review',
                    'note'        => 'Re-converted to Markdown via OCR (manually requested).',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('RunOcrExtraction failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);

            DB::transaction(function () use ($document, $e) {
                $oldStatus = $document->status;
                $document->forceFill(['status' => 'failed'])->save();

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => $oldStatus,
                    'to_status'   => 'failed',
                    'note'        => $e->getMessage(),
                ]);
            });
        }
    }

    private function runOcr(string $absolutePdfPath): string
    {
        $tmpDir = storage_path('app/private/ocr_tmp/' . uniqid('doc_', true));
        mkdir($tmpDir, 0755, true);

        try {
            $rasterResult = Process::timeout(600)->run([
                'pdftoppm', '-png', '-r', '300', $absolutePdfPath, "{$tmpDir}/page",
            ]);

            if (! $rasterResult->successful()) {
                throw new \RuntimeException('pdftoppm failed: ' . $rasterResult->errorOutput());
            }

            $pages = collect(glob("{$tmpDir}/page-*.png"))->sort()->values();

            if ($pages->isEmpty()) {
                throw new \RuntimeException('No pages rasterized for OCR.');
            }

            // hOCR (not plain stdout text) — gives per-line x_size, the font-size proxy the
            // structure extractor needs to detect headings in scanned documents. Tesseract
            // appends .hocr itself when given an output basename instead of "stdout".
            $pages->each(function (string $imagePath) {
                $outputBase = preg_replace('/\.png$/', '', $imagePath);
                $result = Process::timeout(300)->run([
                    'tesseract', $imagePath, $outputBase, '-l', 'hin+eng', 'hocr',
                ]);

                if (! $result->successful()) {
                    throw new \RuntimeException('tesseract failed: ' . $result->errorOutput());
                }
            });

            $structured = Process::timeout(120)->run([$this->pythonBin, $this->extractorScript, '--mode', 'hocr', $tmpDir]);

            if (! $structured->successful()) {
                throw new \RuntimeException('Structure extraction failed: ' . $structured->errorOutput());
            }

            return trim($structured->output());
        } finally {
            // ponytail: page images are never retained — user confirmed no need to keep them
            foreach (glob("{$tmpDir}/*") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }
}

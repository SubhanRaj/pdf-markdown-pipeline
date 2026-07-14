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

    // EasyOCR/PaddleOCR/Surya load multi-hundred-MB models per run and are far slower than
    // Tesseract per page; keep enough headroom for those, not just the tesseract path.
    public int $timeout = 1900;

    private string $extractorScript;

    public function __construct(public int $documentId, public string $engine = 'tesseract')
    {
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

            // Preserve the pre-OCR (text-layer) result exactly once, so a reviewer can revert
            // back to it later if OCR turns out worse — never overwritten by subsequent OCR
            // re-runs, since only the *original* text-layer pass is worth keeping as a fallback.
            $preOcrBackupPath = preg_replace('/\.pdf$/i', '.pre-ocr.md', $document->original_pdf_path);
            if (
                ($document->metadata['extraction_method'] ?? null) !== 'ocr'
                && $document->markdown_path
                && Storage::disk('public')->exists($document->markdown_path)
                && ! Storage::disk('public')->exists($preOcrBackupPath)
            ) {
                Storage::disk('public')->put($preOcrBackupPath, Storage::disk('public')->get($document->markdown_path));
            }

            Storage::disk('public')->put($markdownPath, $markdown);

            DB::transaction(function () use ($document, $markdownPath) {
                $oldStatus = $document->status;
                $document->update([
                    'markdown_path' => $markdownPath,
                    'status'        => 'review',
                    'metadata'      => array_merge($document->metadata ?? [], [
                        'extraction_method' => 'ocr',
                        'ocr_engine'        => $this->engine,
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

            $engines = config('ocr.engines');

            if (! isset($engines[$this->engine])) {
                throw new \RuntimeException("Unknown OCR engine: {$this->engine}");
            }

            if ($this->engine === 'tesseract') {
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

                $pythonBin = base_path('vendor/innobrain/markitdown/python/venv/bin/python3');
                $mode = 'hocr';
            } else {
                // EasyOCR/PaddleOCR/Surya each OCR the page images themselves inside
                // pdf_structure_extractor.py, so nothing to pre-process here — just point at
                // that engine's own isolated venv (heavy ML deps, kept out of the main app).
                $pythonBin = $engines[$this->engine]['venv'] . '/bin/python3';
                $mode = $this->engine;
            }

            // These engines load large models per invocation, well beyond Tesseract's per-page cost.
            $structured = Process::timeout(1800)
                ->env($engines[$this->engine]['env'] ?? [])
                ->run([$pythonBin, $this->extractorScript, '--mode', $mode, $tmpDir]);

            if (! $structured->successful()) {
                throw new \RuntimeException("Structure extraction ({$this->engine}) failed: " . $structured->errorOutput());
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

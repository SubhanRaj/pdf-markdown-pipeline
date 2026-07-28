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
 * OCR-based re-extraction. Auto-dispatched by ConvertDocumentToMarkdown itself when its
 * text-layer pass looks unreadable (sparse text or a detected legacy font) — no reviewer click
 * needed for that case. Also dispatchable manually from the review screen, e.g. to retry with a
 * different engine, or if a reviewer wants to try OCR on a document that passed quality checks.
 */
class RunOcrExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1900;

    private string $extractorScript;

    public function __construct(public int $documentId, public string $engine = 'tesseract')
    {
        $this->extractorScript = resource_path('python/pdf_structure_extractor.py');
        $this->timeout = self::timeoutForDocument($documentId);
    }

    /**
     * Scales with page count, not file size — a 500-page scan needs far longer than the
     * worker's original 1900s (~32min) default, which was sized for small documents only.
     * Read here (constructor), not in handle(): Laravel captures $timeout from the job
     * object before it's serialized onto the queue, so it must be set before dispatch.
     */
    public static function timeoutForDocument(int $documentId): int
    {
        $document = Document::find($documentId);

        if (! $document || ! $document->original_pdf_path) {
            return 1900;
        }

        $absolutePath = Storage::disk('public')->path($document->original_pdf_path);
        $pages = self::pageCount($absolutePath);

        // ponytail: fixed buckets, not a per-page formula — revisit with real timing data
        // once OCR runs at this scale are common enough to tune against.
        return match (true) {
            $pages <= 50    => 1900,   // ~32 min
            $pages <= 150   => 3600,   // 1 hr
            $pages <= 250   => 5400,   // 1.5 hr
            $pages <= 500   => 7200,   // 2 hr
            $pages <= 1000  => 10800,  // 3 hr
            default         => 14400,  // 4 hr
        };
    }

    private static function pageCount(string $pdfPath): int
    {
        $result = Process::timeout(30)->run(['pdfinfo', $pdfPath]);

        if ($result->successful() && preg_match('/^Pages:\s+(\d+)/m', $result->output(), $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    public function handle(): void
    {
        $document = Document::findOrFail($this->documentId);

        $document->forceFill(['status' => 'ocr_pending'])->save();

        $absolutePdfPath = Storage::disk('public')->path($document->original_pdf_path);

        try {
            $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $document->original_pdf_path);
            $structureAbsolutePath = Storage::disk('public')->exists($structurePath)
                ? Storage::disk('public')->path($structurePath)
                : null;

            $markdown = $this->runOcr($absolutePdfPath, $structureAbsolutePath);

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

    private function runOcr(string $absolutePdfPath, ?string $structureJsonPath = null): string
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

            $command = [$pythonBin, $this->extractorScript, '--mode', $mode];
            if ($structureJsonPath !== null) {
                $command[] = '--structure-json';
                $command[] = $structureJsonPath;
            }
            $command[] = $tmpDir;

            // These engines load large models per invocation, well beyond Tesseract's per-page cost.
            $structured = Process::timeout(1800)
                ->env($engines[$this->engine]['env'] ?? [])
                ->run($command);

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

<?php

namespace App\Jobs;

use App\Models\QuickConversion;
use App\Services\PdfConversionEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Mirrors ConvertDocumentToMarkdown's orchestration, against QuickConversion instead of Document. */
class ConvertQuickConversionToMarkdown implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;

    private PdfConversionEngine $engine;

    public function __construct(public int $quickConversionId, public string $structureEngine = 'tesseract')
    {
        $this->engine = new PdfConversionEngine();
    }

    public function handle(): void
    {
        $quickConversion = QuickConversion::find($this->quickConversionId);
        if (! $quickConversion) {
            return; // already saved/discarded/pruned before the job ran
        }

        $quickConversion->forceFill(['status' => 'processing'])->save();

        $absolutePdfPath = Storage::disk('public')->path($quickConversion->pdf_path);

        try {
            $markdown = $this->engine->tryStructuredExtract($absolutePdfPath);

            $legacyFont = null;
            $sparsePages = null;
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

            $hasSparsePages = $sparsePages !== null && $sparsePages[1] > 0 && ($sparsePages[0] / $sparsePages[1]) >= 0.15;

            $needsOcrReview = $legacyFont !== null || $hasSparsePages || ! $this->engine->isGoodQuality($markdown, $absolutePdfPath);

            $structureMeta = $this->engine->runDoclingStructureAnalysis($absolutePdfPath, $quickConversion->pdf_path, $quickConversion->id, $this->structureEngine, $legacyFont !== null);

            $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $quickConversion->pdf_path);
            $structureAbsolutePath = ($structureMeta !== [] && Storage::disk('public')->exists($structurePath))
                ? Storage::disk('public')->path($structurePath)
                : null;

            if ($structureAbsolutePath !== null) {
                $markdown = $this->engine->tryStructuredExtract($absolutePdfPath, $structureAbsolutePath);
                $markdown = preg_replace('/^<!-- LEGACY_FONT_DETECTED:.+? -->\n/', '', $markdown);
                $markdown = preg_replace('/^<!-- SPARSE_PAGES:\d+\/\d+ -->\n/', '', $markdown);
            }

            $markdownPath = preg_replace('/\.pdf$/i', '.md', $quickConversion->pdf_path);
            if (! Storage::disk('public')->put($markdownPath, $markdown)) {
                throw new \RuntimeException("Failed to write markdown file: {$markdownPath}");
            }

            $nextStatus = $needsOcrReview ? 'ocr_pending' : 'review';

            $quickConversion->update([
                'markdown_path'  => $markdownPath,
                'structure_path' => $structureAbsolutePath !== null ? $structurePath : null,
                'status'         => $nextStatus,
                'metadata'       => array_merge($quickConversion->metadata ?? [], [
                    'extraction_method' => 'pdf-text',
                    'needs_ocr_review'  => $needsOcrReview,
                ], $structureMeta),
            ]);

            if ($needsOcrReview) {
                RunQuickConversionOcrExtraction::dispatch($quickConversion->id, config('ocr.default'));
            }
        } catch (\Throwable $e) {
            Log::error('ConvertQuickConversionToMarkdown failed', ['quick_conversion_id' => $quickConversion->id, 'error' => $e->getMessage()]);

            $quickConversion->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();
        }
    }
}

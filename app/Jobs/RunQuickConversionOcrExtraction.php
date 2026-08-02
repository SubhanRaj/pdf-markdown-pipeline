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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/** Mirrors RunOcrExtraction's orchestration, against QuickConversion instead of Document. */
class RunQuickConversionOcrExtraction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1900;

    private PdfConversionEngine $conversionEngine;

    public function __construct(public int $quickConversionId, public string $engine = 'tesseract')
    {
        $this->conversionEngine = new PdfConversionEngine();
        $this->timeout = self::timeoutForQuickConversion($quickConversionId);
    }

    public static function timeoutForQuickConversion(int $quickConversionId): int
    {
        $quickConversion = QuickConversion::find($quickConversionId);

        if (! $quickConversion || ! $quickConversion->pdf_path) {
            return 1900;
        }

        $absolutePath = Storage::disk('public')->path($quickConversion->pdf_path);
        $pages = self::pageCount($absolutePath);

        return match (true) {
            $pages <= 50    => 1900,
            $pages <= 150   => 3600,
            $pages <= 250   => 5400,
            $pages <= 500   => 7200,
            $pages <= 1000  => 10800,
            default         => 14400,
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
        $quickConversion = QuickConversion::find($this->quickConversionId);
        if (! $quickConversion) {
            return;
        }

        $quickConversion->forceFill(['status' => 'ocr_pending'])->save();

        $absolutePdfPath = Storage::disk('public')->path($quickConversion->pdf_path);

        try {
            $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $quickConversion->pdf_path);
            $structureAbsolutePath = Storage::disk('public')->exists($structurePath)
                ? Storage::disk('public')->path($structurePath)
                : null;

            $markdown = $this->conversionEngine->runOcr($absolutePdfPath, $this->engine, $structureAbsolutePath);

            $markdownPath = preg_replace('/\.pdf$/i', '.md', $quickConversion->pdf_path);
            Storage::disk('public')->put($markdownPath, $markdown);

            $quickConversion->update([
                'markdown_path' => $markdownPath,
                'status'        => 'review',
                'metadata'      => array_merge($quickConversion->metadata ?? [], [
                    'extraction_method' => 'ocr',
                    'ocr_engine'        => $this->engine,
                    'needs_ocr_review'  => false,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::error('RunQuickConversionOcrExtraction failed', ['quick_conversion_id' => $quickConversion->id, 'error' => $e->getMessage()]);

            $quickConversion->forceFill(['status' => 'failed', 'error_message' => $e->getMessage()])->save();
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Shared PDF → Markdown conversion pipeline, extracted verbatim from
 * ConvertDocumentToMarkdown/RunOcrExtraction so both those jobs and the QuickConversion jobs
 * call the same code. Every method takes explicit paths/ids instead of a Document model —
 * behavior is unchanged from the pre-extraction version, only the parameter list moved.
 */
class PdfConversionEngine
{
    /** Reuses the venv markitdown:install already provisions — pdfminer.six is one of its own dependencies. */
    private string $pythonBin;

    private string $markitdownBin;

    private string $extractorScript;

    public function __construct()
    {
        $this->pythonBin = base_path('vendor/innobrain/markitdown/python/venv/bin/python3');
        $this->markitdownBin = base_path('vendor/innobrain/markitdown/python/venv/bin/markitdown');
        $this->extractorScript = resource_path('python/pdf_structure_extractor.py');
    }

    /**
     * Converts an image (or any other LibreOffice-openable format not in
     * Document::NATIVE_MARKITDOWN_MIMES) to PDF via headless LibreOffice's Draw component.
     * Images have no native text layer, so unlike Word/Excel/etc. they still need this — the
     * OCR pipeline runs against the resulting PDF. Returns the absolute path of the converted
     * PDF; throws on failure (soffice error, or no output produced).
     */
    public function convertToPdf(string $absoluteSourcePath, string $absoluteOutputDir): string
    {
        // -env:UserInstallation avoids relying on a writable $HOME for the www-data user (it has
        // none — /var/www is root-owned); a per-conversion profile dir keeps concurrent
        // conversions from sharing/locking one LibreOffice profile.
        $profileDir = storage_path('app/soffice-profile-' . \Illuminate\Support\Str::random(16));

        // 120s -> 240s (2026-09-01): a large/complex source, or several conversions competing
        // for CPU during a bulk upload, can genuinely take longer than 120s.
        $result = Process::timeout(240)->run([
            'soffice', '--headless', '--convert-to', 'pdf', '--outdir', $absoluteOutputDir,
            '-env:UserInstallation=file://' . $profileDir, $absoluteSourcePath,
        ]);
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($profileDir);

        $convertedPath = $absoluteOutputDir . '/' . pathinfo($absoluteSourcePath, PATHINFO_FILENAME) . '.pdf';

        if (! $result->successful() || ! is_file($convertedPath)) {
            throw new \RuntimeException('soffice conversion failed: ' . $result->errorOutput());
        }

        return $convertedPath;
    }

    /**
     * Word/Excel/PowerPoint/ODT/RTF/TXT/CSV go straight through markitdown's own converters
     * (DocxConverter, XlsxConverter, ...) instead of the PDF-oriented pipeline above — these
     * formats are already selectable text or structured cells, nothing scanned to OCR or run
     * Docling structure detection on. See Document::NATIVE_MARKITDOWN_MIMES.
     *
     * @throws \RuntimeException on conversion failure — caller decides how to record it.
     */
    public function convertNativeToMarkdown(string $absoluteNativePath): string
    {
        $result = Process::timeout(120)->run([$this->markitdownBin, $absoluteNativePath]);

        if (! $result->successful()) {
            throw new \RuntimeException('markitdown failed: ' . $result->errorOutput());
        }

        return trim($result->output());
    }

    /**
     * Pass 0 — Docling layout/table structure detection. Additive and non-fatal: any failure
     * here (bad venv, timeout, malformed output) is logged and swallowed, never blocks the
     * text-layer/OCR pipeline below. Table/heading cell text Docling extracts is kept and
     * spliced into the final Markdown by the caller.
     *
     * Docling's default mode trusts the PDF's native text layer for any region it doesn't
     * detect as a scanned bitmap — fine normally, but wrong for legacy non-Unicode Devanagari
     * fonts (Kruti Dev etc.): that text is technically "selectable" so Docling never OCRs it,
     * and table cells come out with the same garbled codepoints the main pipeline's OCR pass
     * (which renders full pages to images, font-encoding-proof) was supposed to fix. $forceOcr
     * makes Docling re-read every region — tables included — from rendered pixels instead of
     * the broken text layer. Only passed true when the legacy-font sentinel was already
     * detected by pdf_structure_extractor.py, since force-OCR is measurably slower (impractical
     * across a whole large document otherwise — see STRUCTURE_RESEARCH.md) and this is the one
     * case where the native text layer cannot be trusted at all.
     *
     * @param  string  $pdfPath  Relative (Storage disk "public") path of the source PDF — used to derive the sibling .structure.json path.
     * @return array Metadata fields to merge into the caller's `metadata` column, or [] on failure.
     */
    public function runDoclingStructureAnalysis(string $absolutePdfPath, string $pdfPath, int $id, string $structureEngine, bool $forceOcr = false): array
    {
        try {
            $engines = config('docling.ocr_engines');
            $engineKey = array_key_exists($structureEngine, $engines) ? $structureEngine : config('docling.default_ocr_engine');
            $ocrLang = $engines[$engineKey]['ocr_lang'] ?? 'hin+eng';
            $doclingBin = config('docling.venv') . '/bin/docling';

            $tmpDir = storage_path('app/private/docling_tmp/' . uniqid('doc_', true));
            mkdir($tmpDir, 0755, true);

            try {
                $command = [
                    $doclingBin, 'convert', '--to', 'json',
                    '--ocr-engine', $engineKey,
                    '--ocr-lang', $ocrLang,
                    '--output', $tmpDir,
                ];
                if ($forceOcr) {
                    $command[] = '--force-ocr';
                }
                $command[] = $absolutePdfPath;

                // force-ocr genuinely takes longer (whole-document OCR instead of bitmap-only) —
                // give it more room than the default path, still inside the job's 1200s ceiling.
                $result = Process::timeout($forceOcr ? 900 : 600)->run($command);

                if (! $result->successful()) {
                    Log::warning('Docling structure analysis failed', ['id' => $id, 'error' => $result->errorOutput()]);

                    return [];
                }

                $jsonFiles = glob("{$tmpDir}/*.json");
                if (empty($jsonFiles)) {
                    return [];
                }

                $raw = json_decode(file_get_contents($jsonFiles[0]), true);
                if (! is_array($raw)) {
                    return [];
                }

                $headings = [];
                foreach ($raw['texts'] ?? [] as $text) {
                    if (($text['label'] ?? null) !== 'section_header') {
                        continue;
                    }
                    $prov = $text['prov'][0] ?? null;
                    $headings[] = [
                        'page' => $prov['page_no'] ?? null,
                        'bbox' => $prov['bbox'] ?? null,
                        'text' => $text['text'] ?? '',
                    ];
                }

                $tables = [];
                foreach ($raw['tables'] ?? [] as $table) {
                    $prov = $table['prov'][0] ?? null;
                    $data = $table['data'] ?? [];
                    $tables[] = [
                        'page'     => $prov['page_no'] ?? null,
                        'bbox'     => $prov['bbox'] ?? null,
                        'num_rows' => $data['num_rows'] ?? null,
                        'num_cols' => $data['num_cols'] ?? null,
                        'cells'    => array_map(fn ($cell) => [
                            'row'      => $cell['start_row_offset_idx'] ?? null,
                            'col'      => $cell['start_col_offset_idx'] ?? null,
                            'row_span' => $cell['row_span'] ?? 1,
                            'col_span' => $cell['col_span'] ?? 1,
                            'text'     => $cell['text'] ?? '',
                            'bbox'     => $cell['bbox'] ?? null,
                        ], $data['table_cells'] ?? []),
                    ];
                }

                $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $pdfPath);
                Storage::disk('public')->put($structurePath, json_encode([
                    'engine'     => 'docling',
                    'ocr_engine' => $engineKey,
                    'force_ocr'  => $forceOcr,
                    'headings'   => $headings,
                    'tables'     => $tables,
                ], JSON_UNESCAPED_UNICODE));

                return [
                    'structure_analyzed'       => true,
                    'structure_engine'         => $engineKey,
                    'structure_headings_count' => count($headings),
                    'structure_tables_count'   => count($tables),
                    'structure_force_ocr'      => $forceOcr,
                ];
            } finally {
                foreach (glob("{$tmpDir}/*") ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($tmpDir);
            }
        } catch (\Throwable $e) {
            Log::warning('Docling structure analysis threw', ['id' => $id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Structure-aware extraction for native-text PDFs — uses pdfminer's per-character font
     * size/name data to detect headings, bold, and lists. Deliberately bypasses markitdown's
     * own PDF converter, which only calls pdfminer.high_level.extract_text() and is plain-text
     * only by its own documentation ("most style information is ignored").
     */
    public function tryStructuredExtract(string $absolutePdfPath, ?string $structureJsonPath = null): string
    {
        try {
            $command = [$this->pythonBin, $this->extractorScript, '--mode', 'pdf'];
            if ($structureJsonPath !== null) {
                $command[] = '--structure-json';
                $command[] = $structureJsonPath;
            }
            $command[] = $absolutePdfPath;

            $result = Process::timeout(120)->run($command);

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
    public function isGoodQuality(string $markdown, string $absolutePdfPath): bool
    {
        if (preg_match_all('/\(cid:\d+\)/', $markdown) > 5) {
            return false;
        }

        $charCount = strlen(preg_replace('/\s+/', '', $markdown));
        $pageCount = max(1, $this->countPages($absolutePdfPath));

        return $charCount >= ($pageCount * 40);
    }

    public function countPages(string $absolutePdfPath): int
    {
        $result = Process::run(['pdfinfo', $absolutePdfPath]);
        if ($result->successful() && preg_match('/Pages:\s+(\d+)/', $result->output(), $m)) {
            return (int) $m[1];
        }

        return 1;
    }

    /**
     * Rasterize the PDF and run the given OCR engine over every page, returning the resulting
     * Markdown. Page images are never retained — deleted in the `finally` block regardless of
     * outcome.
     */
    public function runOcr(string $absolutePdfPath, string $engine, ?string $structureJsonPath = null): string
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

            if (! isset($engines[$engine])) {
                throw new \RuntimeException("Unknown OCR engine: {$engine}");
            }

            if ($engine === 'tesseract') {
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
                $pythonBin = $engines[$engine]['venv'] . '/bin/python3';
                $mode = $engine;
            }

            $command = [$pythonBin, $this->extractorScript, '--mode', $mode];
            if ($structureJsonPath !== null) {
                $command[] = '--structure-json';
                $command[] = $structureJsonPath;
            }
            $command[] = $tmpDir;

            // These engines load large models per invocation, well beyond Tesseract's per-page cost.
            $structured = Process::timeout(1800)
                ->env($engines[$engine]['env'] ?? [])
                ->run($command);

            if (! $structured->successful()) {
                throw new \RuntimeException("Structure extraction ({$engine}) failed: " . $structured->errorOutput());
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

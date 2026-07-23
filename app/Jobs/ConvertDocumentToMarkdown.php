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

    // Bumped from 900 — the added Docling structure pass measured 2-3 min on real 54-112 page
    // documents during evaluation (see STRUCTURE_RESEARCH.md); this leaves headroom for larger ones.
    public int $timeout = 1200;

    /** Reuses the venv markitdown:install already provisions — pdfminer.six is one of its own dependencies. */
    private string $pythonBin;

    private string $extractorScript;

    public function __construct(public int $documentId, public string $structureEngine = 'tesseract')
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
            // Pass 1 first — pdfminer text-layer extraction, seconds even on 100+ pages, run
            // without Docling's structure yet (nothing to splice in until Pass 0 below runs).
            // This tells us upfront whether the document has usable selectable text, without
            // waiting on Docling's per-page structure-detection time to find out.
            $markdown = $this->tryStructuredExtract($absolutePdfPath);

            $legacyFont = null;
            if (preg_match('/^<!-- LEGACY_FONT_DETECTED:(.+?) -->\n/', $markdown, $m)) {
                $legacyFont = $m[1];
                $markdown = substr($markdown, strlen($m[0]));
            }

            $needsOcrReview = $legacyFont !== null || ! $this->isGoodQuality($markdown, $absolutePdfPath);

            // Pass 0 — Docling structure detection (headings/tables/layout). Runs regardless of
            // the quality check above: structure/heading/table splicing is useful for the
            // text-layer render either way, and still needed when OCR ends up running next.
            $structureMeta = $this->runDoclingStructureAnalysis($absolutePdfPath, $document);

            $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $document->original_pdf_path);
            $structureAbsolutePath = ($structureMeta !== [] && Storage::disk('public')->exists($structurePath))
                ? Storage::disk('public')->path($structurePath)
                : null;

            // Re-render with Docling's structure spliced in now that it exists — cheap, since
            // pdfminer's own extraction (the part repeated here) is the fast half of this job;
            // Docling's pass above is what actually took the time.
            if ($structureAbsolutePath !== null) {
                $markdown = $this->tryStructuredExtract($absolutePdfPath, $structureAbsolutePath);
                $markdown = preg_replace('/^<!-- LEGACY_FONT_DETECTED:.+? -->\n/', '', $markdown);
            }

            $markdownPath = preg_replace('/\.pdf$/i', '.md', $document->original_pdf_path);
            if (! Storage::disk('public')->put($markdownPath, $markdown)) {
                throw new \RuntimeException("Failed to write markdown file: {$markdownPath}");
            }

            DB::transaction(function () use ($document, $markdownPath, $needsOcrReview, $legacyFont, $structureMeta) {
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
     * Pass 0 — Docling layout/table structure detection. Additive and non-fatal: any failure
     * here (bad venv, timeout, malformed output) is logged and swallowed, never blocks the
     * text-layer/OCR pipeline below. Docling's own OCR text (used only to read scanned regions
     * well enough to detect their structure) is discarded — only region/table shape + bbox is
     * kept, trimmed from Docling's raw ~100MB+ export down to a small sibling JSON file. See
     * STRUCTURE_RESEARCH.md for the schema this was built from and why the merge into the
     * rendered Markdown is deferred to a later pass.
     *
     * @return array Metadata fields to merge into the document's `metadata` column, or [] on failure.
     */
    private function runDoclingStructureAnalysis(string $absolutePdfPath, Document $document): array
    {
        try {
            $engines = config('docling.ocr_engines');
            $engineKey = array_key_exists($this->structureEngine, $engines) ? $this->structureEngine : config('docling.default_ocr_engine');
            $ocrLang = $engines[$engineKey]['ocr_lang'] ?? 'hin+eng';
            $doclingBin = config('docling.venv') . '/bin/docling';

            $tmpDir = storage_path('app/private/docling_tmp/' . uniqid('doc_', true));
            mkdir($tmpDir, 0755, true);

            try {
                $result = Process::timeout(600)->run([
                    $doclingBin, 'convert', '--to', 'json',
                    '--ocr-engine', $engineKey,
                    '--ocr-lang', $ocrLang,
                    '--output', $tmpDir,
                    $absolutePdfPath,
                ]);

                if (! $result->successful()) {
                    Log::warning('Docling structure analysis failed', ['document_id' => $document->id, 'error' => $result->errorOutput()]);

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

                $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $document->original_pdf_path);
                Storage::disk('public')->put($structurePath, json_encode([
                    'engine'     => 'docling',
                    'ocr_engine' => $engineKey,
                    'headings'   => $headings,
                    'tables'     => $tables,
                ], JSON_UNESCAPED_UNICODE));

                return [
                    'structure_analyzed'       => true,
                    'structure_engine'         => $engineKey,
                    'structure_headings_count' => count($headings),
                    'structure_tables_count'   => count($tables),
                ];
            } finally {
                foreach (glob("{$tmpDir}/*") ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($tmpDir);
            }
        } catch (\Throwable $e) {
            Log::warning('Docling structure analysis threw', ['document_id' => $document->id, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Structure-aware extraction for native-text PDFs — uses pdfminer's per-character font
     * size/name data to detect headings, bold, and lists. Deliberately bypasses markitdown's
     * own PDF converter, which only calls pdfminer.high_level.extract_text() and is plain-text
     * only by its own documentation ("most style information is ignored").
     */
    private function tryStructuredExtract(string $absolutePdfPath, ?string $structureJsonPath = null): string
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

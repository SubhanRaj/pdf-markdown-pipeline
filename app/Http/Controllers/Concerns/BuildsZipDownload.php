<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

trait BuildsZipDownload
{
    /**
     * @param  iterable<array{document: Document, dir: string}>  $entries  "dir" is the
     *         zip-relative folder path for that document ('' = zip root), mirroring the
     *         section/division/folder or policy-container/period tree it came from.
     *
     * Markdown only, never the original PDF: bulk-zipping hundreds of PDFs is bandwidth-heavy
     * and pointless — a PDF is already downloadable one at a time from its own page, and that's
     * the only place the exact-original file matters. Bulk is for the markdown, which has no
     * other download path.
     */
    protected function zipDocuments(string $downloadName, iterable $entries): BinaryFileResponse
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'doc_zip_');

        $zip = new \ZipArchive();
        $zip->open($tmpPath, \ZipArchive::OVERWRITE);

        $used  = [];
        $added = 0;

        foreach ($entries as $entry) {
            $document = $entry['document'];
            $dir      = trim($entry['dir'], '/');
            $base     = Str::slug($document->title) ?: "document-{$document->id}";

            // Two documents can share a title in the same folder (e.g. amendments) — disambiguate.
            $key        = "{$dir}/{$base}";
            $used[$key] = ($used[$key] ?? 0) + 1;
            $suffix     = $used[$key] > 1 ? '-'.$used[$key] : '';

            $path = $document->markdown_path;

            if ($path && Storage::disk('public')->exists($path)) {
                $zip->addFile(
                    Storage::disk('public')->path($path),
                    ($dir !== '' ? "{$dir}/" : '')."{$base}{$suffix}.md"
                );
                $added++;
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($tmpPath);
            abort(404, 'No files to download.');
        }

        return response()->download($tmpPath, $downloadName)->deleteFileAfterSend(true);
    }
}

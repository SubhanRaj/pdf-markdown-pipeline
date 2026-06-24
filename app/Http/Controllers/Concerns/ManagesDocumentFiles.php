<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Document;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Handles physical file movement when a document is archived (soft-deleted)
 * or restored. Files are moved to the private local disk on archive so they
 * are no longer web-accessible, and moved back to the public disk on restore.
 *
 * Archive paths on local disk:
 *   archived_documents/{document_id}.pdf
 *   archived_documents/{document_id}.md
 *
 * Always call these methods AFTER the DB transaction so a failed file move
 * never prevents the database state from being committed.
 */
trait ManagesDocumentFiles
{
    /**
     * Move a document's files from the public disk to the private local disk.
     * Best-effort — logs on failure but never throws.
     */
    protected function archiveFiles(Document $document): void
    {
        $this->moveFile(
            Storage::disk('public'),
            $document->original_pdf_path,
            Storage::disk('local'),
            'archived_documents/' . $document->id . '.pdf',
            $document->id,
            'pdf'
        );

        if ($document->markdown_path) {
            $this->moveFile(
                Storage::disk('public'),
                $document->markdown_path,
                Storage::disk('local'),
                'archived_documents/' . $document->id . '.md',
                $document->id,
                'md'
            );
        }
    }

    /**
     * Move a document's files from the private local disk back to the public disk.
     * Best-effort — logs on failure but never throws.
     */
    protected function restoreFiles(Document $document): void
    {
        $this->moveFile(
            Storage::disk('local'),
            'archived_documents/' . $document->id . '.pdf',
            Storage::disk('public'),
            $document->original_pdf_path,
            $document->id,
            'pdf'
        );

        if ($document->markdown_path) {
            $this->moveFile(
                Storage::disk('local'),
                'archived_documents/' . $document->id . '.md',
                Storage::disk('public'),
                $document->markdown_path,
                $document->id,
                'md'
            );
        }
    }

    /**
     * Delete a document's archived files from the private local disk.
     * Also attempts the public disk as a fallback for documents archived before
     * this file-move flow was introduced.
     */
    protected function deleteArchivedFiles(Document $document): void
    {
        Storage::disk('local')->delete('archived_documents/' . $document->id . '.pdf');
        Storage::disk('local')->delete('archived_documents/' . $document->id . '.md');

        // Legacy: documents archived before this flow may still be on the public disk
        if ($document->original_pdf_path) {
            Storage::disk('public')->delete($document->original_pdf_path);
        }
        if ($document->markdown_path) {
            Storage::disk('public')->delete($document->markdown_path);
        }
    }

    private function moveFile(
        \Illuminate\Contracts\Filesystem\Filesystem $from,
        ?string $fromPath,
        \Illuminate\Contracts\Filesystem\Filesystem $to,
        ?string $toPath,
        int $documentId,
        string $ext
    ): void {
        if (! $fromPath || ! $toPath) {
            return;
        }

        try {
            if (! $from->exists($fromPath)) {
                return;
            }

            $to->put($toPath, $from->get($fromPath));
            $from->delete($fromPath);
        } catch (\Throwable $e) {
            Log::warning('ManagesDocumentFiles: file move failed', [
                'document_id' => $documentId,
                'ext'         => $ext,
                'from'        => $fromPath,
                'to'          => $toPath,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}

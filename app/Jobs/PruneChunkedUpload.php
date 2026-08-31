<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Dispatched once, delayed 24h, when the first chunk of a split-PDF upload lands (see
 * DocumentController::storeChunk()). If the chunk directory still exists when this runs — the
 * browser tab was closed, the network died, or the upload was simply never finished — deletes
 * it. A no-op if the upload already completed (storeChunk() deletes the directory itself once
 * the last chunk is merged) or failed and was already cleaned up.
 */
class PruneChunkedUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $uploadId)
    {
    }

    public function handle(): void
    {
        Storage::disk('local')->deleteDirectory("pdf-chunk-uploads/{$this->uploadId}");
    }
}

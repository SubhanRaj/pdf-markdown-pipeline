<?php

namespace App\Jobs;

use App\Models\QuickConversion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Dispatched once, delayed until expires_at, at QuickConversion creation time (see
 * QuickConversionController::store()). If the row still exists when this runs — i.e. the user
 * never saved or discarded it — deletes its files and the row itself. A no-op if the row is
 * already gone (saved into a real Document, or discarded).
 */
class PruneQuickConversion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $quickConversionId)
    {
    }

    public function handle(): void
    {
        $quickConversion = QuickConversion::find($this->quickConversionId);
        if (! $quickConversion) {
            return;
        }

        foreach ([$quickConversion->pdf_path, $quickConversion->native_path, $quickConversion->markdown_path, $quickConversion->structure_path] as $path) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }

        $quickConversion->delete();
    }
}

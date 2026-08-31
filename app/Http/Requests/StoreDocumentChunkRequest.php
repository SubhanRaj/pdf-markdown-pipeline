<?php

namespace App\Http\Requests;

/**
 * One piece of a large PDF the browser split client-side before upload (see
 * resources/js — or rather resources/views' inline pdf-split script — for the split itself).
 * Same destination/metadata fields as StoreDocumentRequest on every chunk (cheap to resend,
 * and only the last chunk's copy actually gets used) — extending it reuses authorize(),
 * prepareForValidation(), and every non-file rule as-is; only `file` and the three chunk
 * fields differ.
 */
class StoreDocumentChunkRequest extends StoreDocumentRequest
{
    // Per-chunk cap: the client aims for ~90 MB per piece to stay under a 100 MB tunnel/proxy
    // edge limit — this allows some headroom above that target rather than matching it exactly.
    public const MAX_CHUNK_KB = 112640; // 110 MB

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'file'         => ['required', 'file', 'mimetypes:application/pdf', 'max:'.self::MAX_CHUNK_KB],
            'upload_id'         => ['required', 'uuid'],
            'chunk_index'       => ['required', 'integer', 'min:0'],
            'total_chunks'      => ['required', 'integer', 'min:1', 'max:50'],
            // The original (pre-split) filename — each chunk is just "N.pdf" on disk, so the
            // real name only exists client-side; needed on the last chunk for original_filename.
            'original_filename' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'file.mimetypes' => 'Split uploads only support PDF — the file being split must already be a PDF.',
            'file.max'       => 'Each piece must not exceed 110 MB.',
        ];
    }
}

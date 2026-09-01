<?php

namespace App\Models\Concerns;

trait HasUnicodeSlug
{
    /**
     * Build a URL-safe slug that preserves Unicode letters (Devanagari, etc.)
     * instead of transliterating them to Latin approximations.
     *
     * Spaces and non-letter/number sequences collapse to a single hyphen. The
     * slug is used as (part of) a filesystem filename, so it's capped to 150
     * bytes -- ext4's NAME_MAX is 255 bytes per path component, and a 3-byte-
     * per-character script like Devanagari can blow past that from a title
     * that's a normal sentence length to a human reader. A long title used to
     * produce an uncapped slug, which meant a file_put_contents() that failed
     * silently (no PHP warning) once the stored filename crossed 255 bytes.
     */
    protected static function makeSlug(string $text): string
    {
        $slug = mb_strtolower($text);
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $slug);
        $slug = trim($slug, '-');
        $slug = mb_strcut($slug, 0, 150, 'UTF-8');

        return trim($slug, '-');
    }
}

<?php

namespace App\Models\Concerns;

trait HasUnicodeSlug
{
    /**
     * Build a URL-safe slug that preserves Unicode letters (Devanagari, etc.)
     * instead of transliterating them to Latin approximations.
     *
     * Spaces and non-letter/number sequences collapse to a single hyphen. No
     * length cap here -- the DB `slug` column and URLs have no byte-per-
     * component limit, and the title this is built from is already capped at
     * 255 characters by validation. Use slugForFilename() when the slug is
     * going to become part of an actual on-disk filename.
     */
    protected static function makeSlug(string $text): string
    {
        $slug = mb_strtolower($text);
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $slug);

        return trim($slug, '-');
    }

    /**
     * Truncates a slug for safe use as (part of) a physical filename.
     *
     * ext4's NAME_MAX is 255 bytes per path component, and a 3-byte-per-
     * character script like Devanagari can blow past that from a title
     * that's a normal sentence length to a human reader -- even though the
     * DB slug itself (used in URLs) has no such limit. Callers append a
     * "_{timestamp}.{ext}" suffix (~25 bytes) on top of this, so 150 bytes
     * leaves comfortable headroom. Before this existed, a long title
     * produced a stored filename that crossed 255 bytes and made
     * file_put_contents() fail silently (no PHP warning) -- see
     * tests/Feature/LongTitleUploadTest.php.
     */
    public static function slugForFilename(string $slug): string
    {
        return trim(mb_strcut($slug, 0, 150, 'UTF-8'), '-');
    }
}

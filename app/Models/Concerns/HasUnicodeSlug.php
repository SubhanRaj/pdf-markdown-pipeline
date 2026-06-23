<?php

namespace App\Models\Concerns;

trait HasUnicodeSlug
{
    /**
     * Build a URL-safe slug that preserves Unicode letters (Devanagari, etc.)
     * instead of transliterating them to Latin approximations.
     *
     * Spaces and non-letter/number sequences collapse to a single hyphen.
     */
    protected static function makeSlug(string $text): string
    {
        $slug = mb_strtolower($text);
        $slug = preg_replace('/[^\p{L}\p{M}\p{N}]+/u', '-', $slug);

        return trim($slug, '-');
    }
}

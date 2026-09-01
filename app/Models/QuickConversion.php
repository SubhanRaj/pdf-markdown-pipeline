<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuickConversion extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'original_filename',
        'pdf_path',
        'native_path',
        'original_format',
        'markdown_path',
        'structure_path',
        'status',
        'metadata',
        'error_message',
        'expires_at',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Same NATIVE_MARKITDOWN_MIMES bucket as Document::isNativeFormat() — see that model for why. */
    public function isNativeFormat(): bool
    {
        return in_array($this->original_format, Document::NATIVE_MARKITDOWN_MIMES, true);
    }
}

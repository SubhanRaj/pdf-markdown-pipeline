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
}

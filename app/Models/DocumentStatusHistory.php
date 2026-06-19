<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentStatusHistory extends Model
{
    public $timestamps = false;

    const CREATED_AT = 'created_at';

    protected $fillable = [
        'document_id',
        'actor_id',
        'from_status',
        'to_status',
        'note',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

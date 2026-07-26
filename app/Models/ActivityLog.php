<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'action',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected $casts = [
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Append an activity row. Never throws — log failures are non-fatal.
     *
     * $userId defaults to the current auth()->id(), but must be passed explicitly for events
     * fired after the guard has already cleared the authenticated user — logout, most notably.
     *
     * @param array<string,mixed> $meta
     */
    public static function record(string $action, Request $request, array $meta = [], ?int $userId = null): void
    {
        try {
            static::create([
                'user_id'    => $userId ?? auth()->id(),
                'action'     => $action,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent() ? substr($request->userAgent(), 0, 500) : null,
                'metadata'   => array_merge(['method' => $request->method(), 'url' => $request->fullUrl()], $meta),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ActivityLog::record failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}

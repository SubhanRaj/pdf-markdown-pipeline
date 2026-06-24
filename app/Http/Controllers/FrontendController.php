<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;

class FrontendController extends Controller
{
    public function dashboard()
    {
        $isGuest = ! auth()->check();

        // baseQuery scopes to public-only for guests; includes only active (non-deleted) docs
        $baseQuery = fn () => $isGuest ? Document::where('visibility', 'public') : Document::query();

        $stats = [
            'total'      => $baseQuery()->count(),
            'archived'   => $isGuest ? 0 : Document::onlyTrashed()->count(),
            'verified'   => $baseQuery()->where('status', 'verified')->count(),
            'review'     => $isGuest ? 0 : Document::where('status', 'review')->count(),
            'processing' => $isGuest ? 0 : Document::whereIn('status', ['processing', 'ocr_pending'])->count(),
            'failed'     => $isGuest ? 0 : Document::where('status', 'failed')->count(),
            'uploaded'   => $baseQuery()->where('status', 'uploaded')->count(),
        ];

        $departments = Department::withCount([
            'documents' => fn ($q) => $isGuest ? $q->where('visibility', 'public') : $q,
        ])->orderBy('name')->get();

        // Guests see only public documents in the recent feed
        $recentDocuments = Document::with(['department', 'section', 'ruleSet'])
            ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
            ->latest()
            ->limit(8)
            ->get();

        return view('frontend.index', compact('stats', 'departments', 'recentDocuments'));
    }
}

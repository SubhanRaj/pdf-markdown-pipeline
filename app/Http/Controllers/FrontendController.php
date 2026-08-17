<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;

class FrontendController extends Controller
{
    public function dashboard()
    {
        $isGuest = ! auth()->check();

        // baseQuery scopes to public-only for guests; excludes pending/rejected docs from counts
        $baseQuery = fn () => $isGuest
            ? Document::publishable()->publiclyVisible()
            : Document::publishable();

        $user = auth()->user();

        $stats = [
            'total'            => $baseQuery()->count(),
            'archived'         => $isGuest ? 0 : Document::onlyTrashed()->count(),
            'verified'         => $baseQuery()->where('status', 'verified')->count(),
            'review'           => $isGuest ? 0 : Document::publishable()->where('status', 'review')->count(),
            'processing'       => $isGuest ? 0 : Document::publishable()->whereIn('status', ['processing', 'ocr_pending'])->count(),
            'failed'           => $isGuest ? 0 : Document::publishable()->where('status', 'failed')->count(),
            'uploaded'         => $baseQuery()->where('status', 'uploaded')->count(),
            'pending_approval' => ($user && ($user->isAdmin() || $user->hasPrivilege('documents.approve')))
                ? Document::where('status', 'pending_approval')->count()
                : 0,
        ];

        $departments = Department::withCount([
            'documents' => fn ($q) => $isGuest ? $q->publiclyVisible() : $q,
        ])->orderBy('name')->get();

        // Guests see only public documents in the recent feed; pending/rejected are hidden
        $recentDocuments = Document::with(['department', 'section', 'ruleSet', 'folder'])
            ->publishable()
            ->when(! auth()->check(), fn ($q) => $q->publiclyVisible())
            ->latest()
            ->limit(8)
            ->get();

        return view('frontend.index', compact('stats', 'departments', 'recentDocuments'));
    }
}

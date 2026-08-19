<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;

class FrontendController extends Controller
{
    public function dashboard()
    {
        $isGuest = ! auth()->check();
        $user    = auth()->user();

        // baseQuery scopes to public-only for guests, org-scope for authenticated non-global
        // users, and excludes pending/rejected docs from counts either way
        $baseQuery = fn () => $isGuest
            ? Document::publishable()->publiclyVisible()
            : Document::publishable()->viewableBy($user);

        $stats = [
            'total'            => $baseQuery()->count(),
            'archived'         => $isGuest ? 0 : Document::onlyTrashed()->viewableBy($user)->count(),
            'verified'         => $baseQuery()->where('status', 'verified')->count(),
            'review'           => $isGuest ? 0 : Document::publishable()->viewableBy($user)->where('status', 'review')->count(),
            'processing'       => $isGuest ? 0 : Document::publishable()->viewableBy($user)->whereIn('status', ['processing', 'ocr_pending'])->count(),
            'failed'           => $isGuest ? 0 : Document::publishable()->viewableBy($user)->where('status', 'failed')->count(),
            'uploaded'         => $baseQuery()->where('status', 'uploaded')->count(),
            'pending_approval' => ($user && ($user->isAdmin() || $user->hasPrivilege('documents.approve')))
                ? Document::where('status', 'pending_approval')->count()
                : 0,
        ];

        $departments = Department::withCount([
            'documents' => fn ($q) => $isGuest ? $q->publiclyVisible() : $q->viewableBy($user),
        ])->orderBy('name')->get();

        // Guests see only public documents in the recent feed; authenticated non-global users
        // are further narrowed to their own org-unit scope
        $recentDocuments = Document::with(['department', 'section', 'ruleSet', 'folder'])
            ->publishable()
            ->viewableBy($user)
            ->when($isGuest, fn ($q) => $q->publiclyVisible())
            ->latest()
            ->limit(8)
            ->get();

        return view('frontend.index', compact('stats', 'departments', 'recentDocuments'));
    }
}

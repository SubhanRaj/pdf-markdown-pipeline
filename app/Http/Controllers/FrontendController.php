<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Document;

class FrontendController extends Controller
{
    public function dashboard()
    {
        $stats = [
            'total'      => Document::count(),
            'verified'   => Document::where('status', 'verified')->count(),
            'review'     => Document::where('status', 'review')->count(),
            'processing' => Document::whereIn('status', ['processing', 'ocr_pending'])->count(),
            'failed'     => Document::where('status', 'failed')->count(),
            'uploaded'   => Document::where('status', 'uploaded')->count(),
        ];

        $departments = Department::withCount('documents')->orderBy('name')->get();

        // Guests see only verified documents in the recent feed
        $recentDocuments = Document::with(['department', 'section'])
            ->when(! auth()->check(), fn ($q) => $q->where('status', 'verified'))
            ->latest()
            ->limit(8)
            ->get();

        return view('frontend.index', compact('stats', 'departments', 'recentDocuments'));
    }
}

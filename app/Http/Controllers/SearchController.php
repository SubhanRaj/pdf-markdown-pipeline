<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Document;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q = trim($request->string('q'));

        if ($q === '') {
            return view('search.index', [
                'q'         => '',
                'documents' => collect(),
                'sections'  => collect(),
                'ruleSets'  => collect(),
                'divisions' => collect(),
            ]);
        }

        $term = "%{$q}%";

        // Documents — title match, or match via section/division/rule-set name
        $documentsQuery = Document::with(['department', 'section', 'division', 'ruleSet'])
            ->where('title', 'LIKE', $term)
            ->orWhere(fn ($sub) => $sub
                ->whereHas('section',  fn ($s) => $s->where('name', 'LIKE', $term))
            )
            ->orWhere(fn ($sub) => $sub
                ->whereHas('division', fn ($d) => $d->where('name', 'LIKE', $term))
            )
            ->orWhere(fn ($sub) => $sub
                ->whereHas('ruleSet', fn ($r) => $r->where('name', 'LIKE', $term))
            );

        // Guests only see public documents
        if (! auth()->check()) {
            $documentsQuery->where('visibility', 'public');
        }

        $documents = $documentsQuery
            ->orderByRaw("CASE WHEN title LIKE ? THEN 0 ELSE 1 END", [$term])
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        // Sections — name match
        $sections = Section::with('department')
            ->where('name', 'LIKE', $term)
            ->orderBy('name')
            ->limit(20)
            ->get();

        // Rule sets — name or description match
        $ruleSets = RuleSet::with('department')
            ->where('name', 'LIKE', $term)
            ->orWhere('description', 'LIKE', $term)
            ->orderBy('name')
            ->limit(20)
            ->get();

        // Divisions — name or description match
        $divisions = Division::with(['section.department'])
            ->where('name', 'LIKE', $term)
            ->orWhere('description', 'LIKE', $term)
            ->orderBy('name')
            ->limit(20)
            ->get();

        return view('search.index', compact('q', 'documents', 'sections', 'ruleSets', 'divisions'));
    }
}

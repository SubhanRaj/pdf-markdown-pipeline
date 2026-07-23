<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Models\Document;
use App\Models\Folder;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $q            = trim($request->string('q'));
        $documentType = trim($request->string('document_type'));
        $state        = trim($request->string('state'));
        $hasFilter    = $documentType !== '' || $state !== '';

        if ($q === '' && ! $hasFilter) {
            return view('search.index', [
                'q'            => '',
                'documentType' => '',
                'state'        => '',
                'documents'    => collect(),
                'sections'     => collect(),
                'ruleSets'     => collect(),
                'divisions'    => collect(),
                'folders'      => collect(),
            ]);
        }

        $term = "%{$q}%";

        // Documents — title match, or match via section/division/rule-set name.
        // document_type/state are exact filters (from clicking a pill on a document's
        // show page), independent of and combinable with the free-text q search.
        $documentsQuery = Document::with(['department', 'section', 'division', 'ruleSet', 'folder'])
            ->publishable()
            ->when($q !== '', fn ($query) => $query->where(fn ($sub) => $sub
                ->where('title', 'LIKE', $term)
                ->orWhere(fn ($sub) => $sub
                    ->whereHas('section',  fn ($s) => $s->where('name', 'LIKE', $term))
                )
                ->orWhere(fn ($sub) => $sub
                    ->whereHas('division', fn ($d) => $d->where('name', 'LIKE', $term))
                )
                ->orWhere(fn ($sub) => $sub
                    ->whereHas('ruleSet', fn ($r) => $r->where('name', 'LIKE', $term))
                )
                ->orWhere(fn ($sub) => $sub
                    ->whereHas('folder', fn ($f) => $f->where('name', 'LIKE', $term))
                )
            ))
            ->when($documentType !== '', fn ($query) => $query->where('document_type', $documentType))
            ->when($state !== '', fn ($query) => $query->whereHas('ruleSet', fn ($r) => $r->where('state', $state)));

        // Guests only see public documents
        if (! auth()->check()) {
            $documentsQuery->where('visibility', 'public');
        }

        $documents = $documentsQuery
            ->when($q !== '', fn ($query) => $query->orderByRaw("CASE WHEN title LIKE ? THEN 0 ELSE 1 END", [$term]))
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        // Sections/rule sets/divisions/folders only match against free-text q — an exact
        // document_type/state pill filter with no q has nothing meaningful to match here.
        if ($q !== '') {
            $sections = Section::with('department')
                ->where('name', 'LIKE', $term)
                ->orderBy('name')
                ->limit(20)
                ->get();

            $ruleSets = RuleSet::with('department')
                ->where('name', 'LIKE', $term)
                ->orWhere('description', 'LIKE', $term)
                ->orderBy('name')
                ->limit(20)
                ->get();

            $divisions = Division::with(['section.department'])
                ->where('name', 'LIKE', $term)
                ->orWhere('description', 'LIKE', $term)
                ->orderBy('name')
                ->limit(20)
                ->get();

            $foldersQuery = Folder::with(['department', 'section', 'division'])
                ->where('name', 'LIKE', $term)
                ->orWhere('description', 'LIKE', $term);

            if (! auth()->check()) {
                $foldersQuery->where('visibility', 'public');
            }

            $folders = $foldersQuery->orderBy('name')->limit(20)->get();
        } else {
            $sections = $ruleSets = $divisions = $folders = collect();
        }

        return view('search.index', compact('q', 'documentType', 'state', 'documents', 'sections', 'ruleSets', 'divisions', 'folders'));
    }
}

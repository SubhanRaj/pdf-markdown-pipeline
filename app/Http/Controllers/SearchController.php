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
            ->viewableBy(auth()->user())
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

        // Guests only see public documents — and only if the folder they live in (if any) is
        // public too, since a document's own visibility can't override its folder's.
        if (! auth()->check()) {
            $documentsQuery->publiclyVisible();
        }

        $documents = $documentsQuery
            ->when($q !== '', fn ($query) => $query->orderByRaw("CASE WHEN title LIKE ? THEN 0 ELSE 1 END", [$term]))
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        // Sections/rule sets/divisions/folders only match against free-text q — an exact
        // document_type/state pill filter with no q has nothing meaningful to match here.
        if ($q !== '') {
            $user = auth()->user();

            // Rule sets are deliberately not org-scope-filtered — department-wide reference
            // material (Acts/Rules/Policies) with no section-level owner, see User::canView().
            $ruleSets = RuleSet::with('department')
                ->where('name', 'LIKE', $term)
                ->orWhere('description', 'LIKE', $term)
                ->orderBy('name')
                ->limit(20)
                ->get();

            // A section/division appears if it's public (any viewer, guest included) or the
            // viewer can fully view it — an out-of-scope authenticated user must still be able to
            // find a public section/division by name, same as they can browse to it directly (see
            // SectionController::index()); the old `! $user || $user->canView(...)` check dropped
            // it from results entirely for anyone out of scope, and separately let a *guest* see
            // even an authenticated-only section/division's name (`! $user` short-circuited true).
            $sections = Section::with('department')
                ->where('name', 'LIKE', $term)
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->filter(fn ($s) => $s->visibility === 'public' || ($user && $user->canView($s)))
                ->values();

            $divisions = Division::with(['section.department'])
                ->where('name', 'LIKE', $term)
                ->orWhere('description', 'LIKE', $term)
                ->orderBy('name')
                ->limit(20)
                ->get()
                ->filter(fn ($d) => $d->visibility === 'public' || ($user && $user->canView($d)))
                ->values();

            $foldersQuery = Folder::with(['department', 'section', 'division'])
                ->where('name', 'LIKE', $term)
                ->orWhere('description', 'LIKE', $term);

            if (! $user) {
                $foldersQuery->where('visibility', 'public');
            }

            $folders = $foldersQuery->orderBy('name')->limit(20)->get()
                ->filter(fn ($f) => ! $user || $user->canView($f) || $f->visibility === 'public')
                ->values();
        } else {
            $sections = $ruleSets = $divisions = $folders = collect();
        }

        return view('search.index', compact('q', 'documentType', 'state', 'documents', 'sections', 'ruleSets', 'divisions', 'folders'));
    }
}

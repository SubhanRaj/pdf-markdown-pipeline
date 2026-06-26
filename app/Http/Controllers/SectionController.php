<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\Department;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class SectionController extends Controller
{
    public function index(string $level, Department $department): View
    {
        $isGuest = ! auth()->check();
        $visibilityScope = fn ($q) => $isGuest ? $q->where('visibility', 'public') : $q;

        $sections = $department->sections()
            ->withCount(['documents' => $visibilityScope])
            ->orderBy('wing')
            ->orderBy('name')
            ->get();

        return view('sections.index', compact('department', 'sections'));
    }

    public function create(string $level, Department $department): View
    {
        return view('sections.create', compact('department'));
    }

    public function store(StoreSectionRequest $request, string $level, Department $department): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $department) {
                $department->sections()->create($request->validated());
            });

            flash()->success("Section \"{$request->name}\" created.");
            return redirect()->route('departments.show', [$department->levelAlias(), $department]);
        } catch (\Throwable $e) {
            Log::error('SectionController@store failed', [
                'dept_id' => $department->id,
                'error'   => $e->getMessage(),
            ]);
            flash()->error('Failed to create section. Please try again.');
            return back()->withInput();
        }
    }

    public function show(Request $request, string $level, Department $department, Section $section): View
    {
        $isGuest    = ! auth()->check();
        $sort       = $request->get('sort', 'uploaded_desc');
        $filterYear = (int) $request->get('year', 0);

        // Direct documents only (no division) — paginated
        $documentsQuery = $section->documents()
            ->publishable()
            ->whereNull('division_id')
            ->with('user:id,name')
            ->when($isGuest, fn ($q) => $q->where('visibility', 'public'))
            ->when($filterYear, fn ($q) => $q->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.effective_year')) = ?", [$filterYear]));

        match ($sort) {
            'year_desc'    => $documentsQuery->orderByRaw("JSON_EXTRACT(metadata, '$.effective_year') IS NULL, JSON_EXTRACT(metadata, '$.effective_year') DESC"),
            'year_asc'     => $documentsQuery->orderByRaw("JSON_EXTRACT(metadata, '$.effective_year') IS NULL, JSON_EXTRACT(metadata, '$.effective_year') ASC"),
            'uploaded_asc' => $documentsQuery->orderBy('created_at'),
            default        => $documentsQuery->orderByDesc('created_at'),
        };

        $documents = $documentsQuery->paginate(20)->withQueryString();

        // Available years for the filter dropdown
        $availableYears = $section->documents()
            ->publishable()
            ->whereNull('division_id')
            ->when($isGuest, fn ($q) => $q->where('visibility', 'public'))
            ->pluck('metadata')
            ->map(fn ($m) => is_array($m) ? ($m['effective_year'] ?? null) : null)
            ->filter()->unique()->sort()->values();

        // Divisions with document counts
        $visibilityScope = fn ($q) => $isGuest ? $q->where('visibility', 'public') : $q;

        $divisions = $section->divisions()
            ->withCount(['documents' => $visibilityScope])
            ->get();

        // For the "Amends" dropdown in the upload modal — direct section docs only
        $parentOptions = auth()->check()
            ? $section->documents()
                ->whereNull('division_id')
                ->select('id', 'title', 'created_at')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'date' => $d->created_at->format('d M Y')])
                ->values()
            : collect();

        return view('sections.show', compact('department', 'section', 'documents', 'divisions', 'parentOptions', 'sort', 'filterYear', 'availableYears'));
    }

    public function edit(string $level, Department $department, Section $section): View
    {
        return view('sections.edit', compact('department', 'section'));
    }

    public function update(UpdateSectionRequest $request, string $level, Department $department, Section $section): RedirectResponse
    {
        try {
            DB::transaction(fn () => $section->update($request->validated()));

            flash()->success("Section \"{$section->name}\" updated.");
            return redirect()->route('departments.show', [$department->levelAlias(), $department]);
        } catch (\Throwable $e) {
            Log::error('SectionController@update failed', [
                'section_id' => $section->id,
                'error'      => $e->getMessage(),
            ]);
            flash()->error('Failed to update section. Please try again.');
            return back()->withInput();
        }
    }

    public function destroy(string $level, Department $department, Section $section): RedirectResponse
    {
        try {
            DB::transaction(fn () => $section->delete());

            flash()->success("Section \"{$section->name}\" deleted.");
            return redirect()->route('departments.show', [$department->levelAlias(), $department]);
        } catch (\Throwable $e) {
            Log::error('SectionController@destroy failed', [
                'section_id' => $section->id,
                'error'      => $e->getMessage(),
            ]);
            flash()->error('Failed to delete section. Please try again.');
            return back();
        }
    }
}

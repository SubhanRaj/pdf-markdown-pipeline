<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSectionRequest;
use App\Http\Requests\UpdateSectionRequest;
use App\Models\Department;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
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

    public function show(string $level, Department $department, Section $section): View
    {
        $documentsQuery = $section->documents()
            ->with('user:id,name')
            ->orderByDesc('created_at');

        if (! auth()->check()) {
            $documentsQuery->where('visibility', 'public');
        }

        $documents = $documentsQuery->paginate(20)->withQueryString();

        // For the "Amends" dropdown in the upload modal — only fetched for authenticated users
        $parentOptions = auth()->check()
            ? $section->documents()->select('id', 'title', 'created_at')->orderBy('created_at')->get()
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'date' => $d->created_at->format('d M Y')])
                ->values()
            : collect();

        return view('sections.show', compact('department', 'section', 'documents', 'parentOptions'));
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

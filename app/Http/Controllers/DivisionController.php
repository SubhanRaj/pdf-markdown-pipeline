<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDivisionRequest;
use App\Http\Requests\UpdateDivisionRequest;
use App\Models\Department;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DivisionController extends Controller
{
    public function create(string $level, Department $department, Section $section): View
    {
        return view('divisions.create', compact('department', 'section'));
    }

    public function store(StoreDivisionRequest $request, string $level, Department $department, Section $section): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $section) {
                $slug = Division::uniqueSlugForSection($request->validated()['name'], $section->id);
                $section->divisions()->create([
                    ...$request->validated(),
                    'slug' => $slug,
                ]);
            });

            flash()->success("Internal division \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.sections.show', [$department->levelAlias(), $department, $section]);
        } catch (\Throwable $e) {
            Log::error('DivisionController@store failed', [
                'section_id' => $section->id,
                'error'      => $e->getMessage(),
            ]);
            flash()->error('Failed to create division. Please try again.');
            return back()->withInput();
        }
    }

    public function show(string $level, Department $department, Section $section, Division $division): View
    {
        // All root documents in this division with amendments pre-loaded
        $rootDocuments = $division->documents()
            ->with([
                'user:id,name',
                'amendments' => fn ($q) => $q
                    ->with('user:id,name')
                    ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
                    ->orderBy('created_at'),
            ])
            ->whereNull('parent_id')
            ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
            ->orderBy('created_at')
            ->get();

        $totalCount = $division->documents()
            ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
            ->count();

        // Parent options for amendment upload — all root documents in the SECTION
        // (not just this division) since cross-division amendments are permitted.
        $parentOptions = auth()->check()
            ? Document::where('section_id', $section->id)
                ->select('id', 'title', 'created_at', 'division_id')
                ->whereNull('parent_id')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($d) => [
                    'id'    => $d->id,
                    'title' => $d->title,
                    'date'  => $d->created_at->format('d M Y'),
                ])
                ->values()
            : collect();

        return view('divisions.show', compact('department', 'section', 'division', 'rootDocuments', 'totalCount', 'parentOptions'));
    }

    public function edit(string $level, Department $department, Section $section, Division $division): View
    {
        return view('divisions.edit', compact('department', 'section', 'division'));
    }

    public function update(UpdateDivisionRequest $request, string $level, Department $department, Section $section, Division $division): RedirectResponse
    {
        try {
            DB::transaction(fn () => $division->update($request->validated()));

            flash()->success("Division \"{$division->name}\" updated.");
            return redirect()->route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]);
        } catch (\Throwable $e) {
            Log::error('DivisionController@update failed', [
                'division_id' => $division->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to update division. Please try again.');
            return back()->withInput();
        }
    }

    public function destroy(string $level, Department $department, Section $section, Division $division): RedirectResponse
    {
        try {
            DB::transaction(function () use ($division) {
                // Soft-delete all documents in this division with an audit entry
                $division->documents()->each(function (Document $doc) {
                    DocumentStatusHistory::create([
                        'document_id' => $doc->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => $doc->status,
                        'to_status'   => 'deleted',
                        'note'        => 'Deleted with parent internal division.',
                    ]);
                    $doc->delete();
                });

                $division->delete();
            });

            flash()->success("Division \"{$division->name}\" and all its documents deleted.");
            return redirect()->route('departments.sections.show', [$department->levelAlias(), $department, $section]);
        } catch (\Throwable $e) {
            Log::error('DivisionController@destroy failed', [
                'division_id' => $division->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to delete division. Please try again.');
            return back();
        }
    }
}

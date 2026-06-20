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
    public function index(Department $department): View
    {
        $sections = $department->sections()
            ->withCount('documents')
            ->orderBy('wing')
            ->orderBy('name')
            ->get();

        return view('sections.index', compact('department', 'sections'));
    }

    public function create(Department $department): View
    {
        return view('sections.create', compact('department'));
    }

    public function store(StoreSectionRequest $request, Department $department): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $department) {
                $department->sections()->create($request->validated());
            });

            flash()->success("Section \"{$request->name}\" created.");
            return redirect()->route('departments.show', $department);
        } catch (\Throwable $e) {
            Log::error('SectionController@store failed', [
                'dept_id' => $department->id,
                'error'   => $e->getMessage(),
            ]);
            flash()->error('Failed to create section. Please try again.');
            return back()->withInput();
        }
    }

    public function show(Department $department, Section $section): View
    {
        $section->loadCount('documents');
        return view('sections.show', compact('department', 'section'));
    }

    public function edit(Department $department, Section $section): View
    {
        return view('sections.edit', compact('department', 'section'));
    }

    public function update(UpdateSectionRequest $request, Department $department, Section $section): RedirectResponse
    {
        try {
            DB::transaction(fn () => $section->update($request->validated()));

            flash()->success("Section \"{$section->name}\" updated.");
            return redirect()->route('departments.show', $department);
        } catch (\Throwable $e) {
            Log::error('SectionController@update failed', [
                'section_id' => $section->id,
                'error'      => $e->getMessage(),
            ]);
            flash()->error('Failed to update section. Please try again.');
            return back()->withInput();
        }
    }

    public function destroy(Department $department, Section $section): RedirectResponse
    {
        try {
            DB::transaction(fn () => $section->delete());

            flash()->success("Section \"{$section->name}\" deleted.");
            return redirect()->route('departments.show', $department);
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

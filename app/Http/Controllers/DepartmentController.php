<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDepartmentRequest;
use App\Http\Requests\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DepartmentController extends Controller
{
    public function index(): View
    {
        $departments = Department::withCount(['sections', 'documents'])
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        return view('department.index', compact('departments'));
    }

    public function create(): View
    {
        return view('department.create');
    }

    public function store(StoreDepartmentRequest $request): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request) {
                Department::create($request->validated());
            });

            flash()->success("Department \"{$request->name}\" created.");
            return redirect()->route('departments.index');
        } catch (\Throwable $e) {
            Log::error('DepartmentController@store failed', ['error' => $e->getMessage()]);
            flash()->error('Failed to create department. Please try again.');
            return back()->withInput();
        }
    }

    public function show(string $level, Department $department): View
    {
        $department->loadCount(['sections', 'documents']);
        $sections = $department->sections()->withCount('documents')->orderBy('name')->get();
        $ruleSets = $department->ruleSets()->withCount('documents')->orderBy('name')->get();

        return view('department.show', compact('department', 'sections', 'ruleSets'));
    }

    public function edit(string $level, Department $department): View
    {
        return view('department.edit', compact('department'));
    }

    public function update(UpdateDepartmentRequest $request, string $level, Department $department): RedirectResponse
    {
        try {
            DB::transaction(fn () => $department->update($request->validated()));

            flash()->success("Department \"{$department->name}\" updated.");
            return redirect()->route('departments.show', [$department->levelAlias(), $department]);
        } catch (\Throwable $e) {
            Log::error('DepartmentController@update failed', [
                'dept_id' => $department->id,
                'error'   => $e->getMessage(),
            ]);
            flash()->error('Failed to update department. Please try again.');
            return back()->withInput();
        }
    }

    public function destroy(string $level, Department $department): RedirectResponse
    {
        try {
            DB::transaction(fn () => $department->delete());

            flash()->success("Department \"{$department->name}\" deleted.");
            return redirect()->route('departments.index');
        } catch (\Throwable $e) {
            Log::error('DepartmentController@destroy failed', [
                'dept_id' => $department->id,
                'error'   => $e->getMessage(),
            ]);
            flash()->error('Failed to delete department. Please try again.');
            return back();
        }
    }
}

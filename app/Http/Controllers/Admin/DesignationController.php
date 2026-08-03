<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDesignationRequest;
use App\Http\Requests\Admin\UpdateDesignationRequest;
use App\Models\Department;
use App\Models\Designation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DesignationController extends Controller
{
    public function index(): View
    {
        $designations = Designation::with('department')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Designation $d) => $d->department?->name ?? 'Generic (any department)');

        return view('admin.designations.index', compact('designations'));
    }

    public function create(): View
    {
        $departments = Department::orderBy('name')->get(['id', 'name', 'level']);

        return view('admin.designations.create', compact('departments'));
    }

    public function store(StoreDesignationRequest $request): RedirectResponse
    {
        try {
            DB::transaction(fn () => Designation::create($request->validated()));

            flash()->success("Designation \"{$request->name}\" created.");

            return redirect()->route('admin.designations.index');
        } catch (\Throwable $e) {
            Log::error('DesignationController@store failed', ['error' => $e->getMessage()]);
            flash()->error('Failed to create designation. Please try again.');

            return back()->withInput();
        }
    }

    public function edit(Designation $designation): View
    {
        $departments = Department::orderBy('name')->get(['id', 'name', 'level']);

        return view('admin.designations.edit', compact('designation', 'departments'));
    }

    public function update(UpdateDesignationRequest $request, Designation $designation): RedirectResponse
    {
        try {
            DB::transaction(fn () => $designation->update($request->validated()));

            flash()->success("Designation \"{$designation->name}\" updated.");

            return redirect()->route('admin.designations.index');
        } catch (\Throwable $e) {
            Log::error('DesignationController@update failed', [
                'designation_id' => $designation->id,
                'error'          => $e->getMessage(),
            ]);
            flash()->error('Failed to update designation. Please try again.');

            return back()->withInput();
        }
    }

    public function destroy(Designation $designation): RedirectResponse
    {
        try {
            DB::transaction(fn () => $designation->delete());

            flash()->success("Designation \"{$designation->name}\" deleted.");

            return redirect()->route('admin.designations.index');
        } catch (\Throwable $e) {
            Log::error('DesignationController@destroy failed', [
                'designation_id' => $designation->id,
                'error'          => $e->getMessage(),
            ]);
            flash()->error('Failed to delete designation. Please try again.');

            return back();
        }
    }
}

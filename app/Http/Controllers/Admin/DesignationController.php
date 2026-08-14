<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDesignationRequest;
use App\Http\Requests\Admin\UpdateDesignationRequest;
use App\Models\Designation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class DesignationController extends Controller
{
    public function index(): View
    {
        return view('admin.designations.index');
    }

    // No return type: real routes get a RedirectResponse, but the designation-manager Livewire
    // component calls these directly, and Livewire swaps the redirect() helper for its own
    // Redirector (not a RedirectResponse) while a component method is executing.
    public function store(StoreDesignationRequest $request)
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

    public function update(UpdateDesignationRequest $request, Designation $designation)
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

    public function destroy(Designation $designation)
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

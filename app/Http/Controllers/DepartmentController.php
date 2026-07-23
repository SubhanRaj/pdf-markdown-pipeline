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
        $isGuest = ! auth()->check();
        $visibilityScope = fn ($q) => $isGuest ? $q->where('visibility', 'public') : $q;

        $departments = Department::withCount([
            'sections',
            'documents' => $visibilityScope,
        ])
            ->orderBy('level')
            ->orderBy('name')
            ->get();

        return view('department.index', compact('departments'));
    }

    /**
     * Same authorize() logic as Store/UpdateDepartmentRequest, duplicated here because
     * create/edit/destroy render or mutate state outside a FormRequest. See SECURITY.md H-04.
     */
    private function authorizeManage(): void
    {
        $user = auth()->user();
        abort_unless($user->isAdmin() || $user->hasPrivilege('organization.head'), 403);
    }

    public function create(): View
    {
        $this->authorizeManage();

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
        $isGuest = ! auth()->check();
        $visibilityScope = fn ($q) => $isGuest ? $q->where('visibility', 'public') : $q;

        $department->loadCount([
            'sections',
            'documents' => $visibilityScope,
        ]);

        $rulesCount = $department->ruleSets()->rules()->count();
        $policiesCount = $department->ruleSets()->currentPolicy()->count();
        $historicalPoliciesCount = $department->ruleSets()->policy()->where('policy_status', 'superseded')->count();

        return view('department.show', compact('department', 'rulesCount', 'policiesCount', 'historicalPoliciesCount'));
    }

    public function edit(string $level, Department $department): View
    {
        $this->authorizeManage();

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
        $this->authorizeManage();

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

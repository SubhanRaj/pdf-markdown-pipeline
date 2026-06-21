<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRuleSetRequest;
use App\Http\Requests\UpdateRuleSetRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\RuleSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class RuleSetController extends Controller
{
    public function create(string $level, Department $department): View
    {
        return view('rule_sets.create', compact('department'));
    }

    public function store(StoreRuleSetRequest $request, string $level, Department $department): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $department) {
                $slug = RuleSet::uniqueSlugForDepartment($request->validated()['name'], $department->id);

                $department->ruleSets()->create([
                    ...$request->validated(),
                    'slug' => $slug,
                ]);
            });

            flash()->success("Rule set \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.show', [$department->levelAlias(), $department]);
        } catch (\Throwable $e) {
            Log::error('RuleSetController@store failed', [
                'dept_id' => $department->id,
                'error'   => $e->getMessage(),
            ]);
            flash()->error('Failed to create rule set. Please try again.');
            return back()->withInput();
        }
    }

    public function show(string $level, Department $department, RuleSet $ruleSet): View
    {
        $documents = $ruleSet->documents()
            ->with('user:id,name')
            ->when(! auth()->check(), fn ($q) => $q->where('status', 'verified'))
            ->orderBy('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('rule_sets.show', compact('department', 'ruleSet', 'documents'));
    }

    public function edit(string $level, Department $department, RuleSet $ruleSet): View
    {
        return view('rule_sets.edit', compact('department', 'ruleSet'));
    }

    public function update(UpdateRuleSetRequest $request, string $level, Department $department, RuleSet $ruleSet): RedirectResponse
    {
        try {
            DB::transaction(fn () => $ruleSet->update($request->validated()));

            flash()->success("Rule set \"{$ruleSet->name}\" updated.");
            return redirect()->route('departments.rules.show', [$department->levelAlias(), $department, $ruleSet]);
        } catch (\Throwable $e) {
            Log::error('RuleSetController@update failed', [
                'rule_set_id' => $ruleSet->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to update rule set. Please try again.');
            return back()->withInput();
        }
    }

    public function destroy(string $level, Department $department, RuleSet $ruleSet): RedirectResponse
    {
        try {
            DB::transaction(fn () => $ruleSet->delete());

            flash()->success("Rule set \"{$ruleSet->name}\" deleted.");
            return redirect()->route('departments.show', [$department->levelAlias(), $department]);
        } catch (\Throwable $e) {
            Log::error('RuleSetController@destroy failed', [
                'rule_set_id' => $ruleSet->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to delete rule set. Please try again.');
            return back();
        }
    }
}

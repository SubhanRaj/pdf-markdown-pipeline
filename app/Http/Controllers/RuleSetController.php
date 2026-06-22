<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRuleSetRequest;
use App\Http\Requests\UpdateRuleSetRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
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
        // Root documents with their amendments pre-loaded — drives the hierarchy view
        $rootDocuments = $ruleSet->documents()
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

        $totalCount = $ruleSet->documents()
            ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
            ->count();

        // Parent dropdown in upload modal — only root documents are valid amendment targets
        $parentOptions = auth()->check()
            ? $ruleSet->documents()
                ->select('id', 'title', 'created_at')
                ->whereNull('parent_id')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'date' => $d->created_at->format('d M Y')])
                ->values()
            : collect();

        return view('rule_sets.show', compact('department', 'ruleSet', 'rootDocuments', 'totalCount', 'parentOptions'));
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
            DB::transaction(function () use ($ruleSet) {
                // Soft-delete all documents in this rule set with an audit entry
                $ruleSet->documents()->each(function (Document $doc) {
                    DocumentStatusHistory::create([
                        'document_id' => $doc->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => $doc->status,
                        'to_status'   => 'deleted',
                        'note'        => 'Deleted with parent rule set.',
                    ]);
                    $doc->delete();
                });

                $ruleSet->delete();
            });

            flash()->success("Rule set \"{$ruleSet->name}\" and all its documents deleted.");
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

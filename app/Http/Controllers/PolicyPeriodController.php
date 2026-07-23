<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRuleSetDocuments;
use App\Http\Controllers\Concerns\ManagesDocumentFiles;
use App\Http\Requests\StorePolicyPeriodRequest;
use App\Http\Requests\UpdatePolicyPeriodRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\RuleSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * A "period" is a yearly/cyclical policy document (e.g. "2024-25", "2025-26") added
 * underneath a policy container (state + policy_type, created once via RuleSetController).
 * Periods are plain RuleSet rows with container_id set — everything about how a period
 * holds its own root document + amendments is identical to today's rule-sets behavior,
 * reused via the ListsRuleSetDocuments trait.
 */
class PolicyPeriodController extends Controller
{
    use ListsRuleSetDocuments, ManagesDocumentFiles;

    /** Aborts 404 if $period doesn't actually belong to $policy — guards the nested URL. */
    private function assertBelongsTo(RuleSet $policy, RuleSet $period): void
    {
        abort_unless($period->container_id === $policy->id, 404);
    }

    public function create(string $level, Department $department, RuleSet $policy): View
    {
        abort_unless(auth()->user()->canManagePolicy($policy), 403);

        return view('rule_sets.periods.create', compact('department', 'policy'));
    }

    public function store(StorePolicyPeriodRequest $request, string $level, Department $department, RuleSet $policy): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $department, $policy) {
                $validated = $request->validated();
                $slug = RuleSet::uniqueSlugForDepartment($validated['name'], $department->id);

                $newPeriod = $department->ruleSets()->create([
                    ...$validated,
                    'slug'         => $slug,
                    'kind'         => 'policy',
                    'state'        => $policy->state,
                    'policy_type'  => $policy->policy_type,
                    'container_id' => $policy->id,
                ]);

                // A new period supersedes the current one under this same container, if any.
                $previousCurrent = RuleSet::currentPolicy()
                    ->where('container_id', $policy->id)
                    ->where('id', '!=', $newPeriod->id)
                    ->first();

                if ($previousCurrent) {
                    $previousCurrent->update(['policy_status' => 'superseded']);
                    $newPeriod->update(['previous_policy_id' => $previousCurrent->id]);
                }
            });

            flash()->success("Policy period \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.policy.show', [$department->levelAlias(), $department, $policy]);
        } catch (\Throwable $e) {
            Log::error('PolicyPeriodController@store failed', [
                'policy_id' => $policy->id,
                'error'     => $e->getMessage(),
            ]);
            flash()->error('Failed to create policy period. Please try again.');
            return back()->withInput();
        }
    }

    public function show(Request $request, string $level, Department $department, RuleSet $policy, RuleSet $period): View
    {
        $this->assertBelongsTo($policy, $period);

        return view('rule_sets.show', array_merge(
            compact('department', 'policy'),
            ['ruleSet' => $period],
            $this->loadRuleSetDocuments($period, $request)
        ));
    }

    public function edit(string $level, Department $department, RuleSet $policy, RuleSet $period): View
    {
        $this->assertBelongsTo($policy, $period);
        abort_unless(auth()->user()->canManagePolicy($period), 403);

        return view('rule_sets.periods.edit', compact('department', 'policy', 'period'));
    }

    public function update(UpdatePolicyPeriodRequest $request, string $level, Department $department, RuleSet $policy, RuleSet $period): RedirectResponse
    {
        $this->assertBelongsTo($policy, $period);

        try {
            DB::transaction(fn () => $period->update($request->validated()));

            flash()->success("Policy period \"{$period->name}\" updated.");
            return redirect()->route('departments.policy.periods.show', [$department->levelAlias(), $department, $policy, $period]);
        } catch (\Throwable $e) {
            Log::error('PolicyPeriodController@update failed', [
                'period_id' => $period->id,
                'error'     => $e->getMessage(),
            ]);
            flash()->error('Failed to update policy period. Please try again.');
            return back()->withInput();
        }
    }

    public function destroy(string $level, Department $department, RuleSet $policy, RuleSet $period): RedirectResponse
    {
        $this->assertBelongsTo($policy, $period);
        abort_unless(auth()->user()->canManagePolicy($period), 403);

        $docsToArchive = [];

        try {
            DB::transaction(function () use ($period, &$docsToArchive) {
                $period->documents()->each(function (Document $doc) use (&$docsToArchive) {
                    DocumentStatusHistory::create([
                        'document_id' => $doc->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => $doc->status,
                        'to_status'   => 'deleted',
                        'note'        => 'Deleted with parent policy period.',
                    ]);
                    $doc->delete();
                    $docsToArchive[] = $doc;
                });

                $period->delete();
            });

            foreach ($docsToArchive as $doc) {
                $this->archiveFiles($doc);
            }

            flash()->success("Policy period \"{$period->name}\" and all its documents deleted.");
            return redirect()->route('departments.policy.show', [$department->levelAlias(), $department, $policy]);
        } catch (\Throwable $e) {
            Log::error('PolicyPeriodController@destroy failed', [
                'period_id' => $period->id,
                'error'     => $e->getMessage(),
            ]);
            flash()->error('Failed to delete policy period. Please try again.');
            return back();
        }
    }
}

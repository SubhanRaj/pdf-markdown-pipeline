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
use Illuminate\Support\Facades\Storage;
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

    /**
     * Whether a newly-added period should take over "current" from the existing current
     * period. Only true when both effective_start_dates are known and the new one is on or
     * after the existing current one — an unknown or earlier date means the new period is
     * being backfilled (e.g. an old year added after a newer one already exists) and must
     * not silently supersede the real current period.
     */
    public static function isChronologicallyLater(?\Illuminate\Support\Carbon $newStart, ?\Illuminate\Support\Carbon $previousStart): bool
    {
        return $newStart !== null && $previousStart !== null && $newStart->gte($previousStart);
    }

    public function create(string $level, Department $department, RuleSet $policy): View
    {
        abort_unless(auth()->user()->canManagePolicy($policy), 403);

        return view('rule_sets.periods.create', compact('department', 'policy'));
    }

    public function store(StorePolicyPeriodRequest $request, string $level, Department $department, RuleSet $policy): RedirectResponse
    {
        $validated = $request->validated();
        $slug      = RuleSet::uniqueSlugForDepartment($validated['name'], $department->id);

        // Uploaded up front (outside the transaction, same convention as
        // DocumentController@store) so a failed DB write never leaves an orphaned file
        // and a failed file write never leaves a period without one.
        $pdfPath  = null;
        $vaultDir = null;

        if ($request->hasFile('file')) {
            $vaultDir = implode('/', ['document_vault', $department->level, $department->slug, 'rules', $slug]);
            $pdfPath  = $request->file('file')->storeAs($vaultDir, $slug . '_' . now()->format('YmdHis') . '.pdf', 'public');

            if (! $pdfPath) {
                flash()->error('File could not be saved. Please try again.');
                return back()->withInput();
            }
        }

        try {
            DB::transaction(function () use ($request, $department, $policy, $validated, $pdfPath, $vaultDir, $slug) {
                $newPeriod = $department->ruleSets()->create([
                    'name'                 => $validated['name'],
                    'effective_start_date' => $validated['effective_start_date'] ?? null,
                    'effective_end_date'   => $validated['effective_end_date'] ?? null,
                    'slug'                 => $slug,
                    'kind'                 => 'policy',
                    'state'                => $policy->state,
                    'policy_type'          => $policy->policy_type,
                    'container_id'         => $policy->id,
                ]);

                // A new period only supersedes the current one under this same container when
                // it's chronologically later — comparing effective_start_date stops a backfilled
                // older period (e.g. adding "2021-22" after "2024-25" already exists) from wrongly
                // taking over "current". Without both dates to compare, the new period is created
                // as superseded rather than guessing; it can be promoted later via the edit form.
                $previousCurrent = RuleSet::currentPolicy()
                    ->where('container_id', $policy->id)
                    ->where('id', '!=', $newPeriod->id)
                    ->first();

                if ($previousCurrent) {
                    if (self::isChronologicallyLater($newPeriod->effective_start_date, $previousCurrent->effective_start_date)) {
                        $previousCurrent->update(['policy_status' => 'superseded']);
                        $newPeriod->update(['previous_policy_id' => $previousCurrent->id]);
                    } else {
                        $newPeriod->update(['policy_status' => 'superseded']);
                    }
                }

                if ($pdfPath) {
                    $this->storePolicyDocument($request, $department, $newPeriod, $validated, $pdfPath, $vaultDir);
                }
            });

            flash()->success("Policy \"{$validated['name']}\" created.");
            return redirect()->route('departments.policy.show', [$department->levelAlias(), $department, $policy]);
        } catch (\Throwable $e) {
            if ($pdfPath) {
                Storage::disk('public')->delete($pdfPath);
            }

            Log::error('PolicyPeriodController@store failed', [
                'policy_id' => $policy->id,
                'error'     => $e->getMessage(),
            ]);
            flash()->error('Failed to create policy. Please try again.');
            return back()->withInput();
        }
    }

    /** Creates the period's root policy Document(s) from the file uploaded alongside it. */
    private function storePolicyDocument(Request $request, Department $department, RuleSet $period, array $validated, string $pdfPath, string $vaultDir): void
    {
        $requireApproval = $request->user()->shouldRequireApproval($period);
        $initialStatus   = $requireApproval ? 'pending_approval' : 'uploaded';
        $languages       = ($validated['language'] ?? 'english') === 'both' ? ['english', 'hindi'] : [$validated['language'] ?? 'english'];

        $created = [];

        foreach ($languages as $i => $language) {
            if ($i === 0) {
                $langSlug = $period->slug;
                $langPath = $pdfPath;
            } else {
                $langSlug = $period->slug . '-' . $language;
                $langPath = $vaultDir . '/' . $langSlug . '_' . now()->format('YmdHis') . '.pdf';
                Storage::disk('public')->copy($pdfPath, $langPath);
            }

            $doc = Document::create([
                'department_id'     => $department->id,
                'rule_set_id'       => $period->id,
                'user_id'           => $request->user()->id,
                'title'             => $period->name,
                'slug'              => $langSlug,
                'document_type'     => 'policy',
                'language'          => $language,
                'original_filename' => preg_replace('/[^\w\s\-\.\(\)]/', '_', $request->file('file')->getClientOriginalName()),
                'original_pdf_path' => $langPath,
                'vault_path'        => $vaultDir,
                'status'            => $initialStatus,
                'visibility'        => $validated['visibility'] ?? 'public',
            ]);

            DocumentStatusHistory::create([
                'document_id' => $doc->id,
                'actor_id'    => $request->user()->id,
                'from_status' => null,
                'to_status'   => $initialStatus,
                'note'        => $initialStatus === 'pending_approval'
                    ? 'Document submitted for approval.'
                    : 'Document uploaded.',
            ]);

            $created[] = $doc;
        }

        if (count($created) === 2) {
            $created[0]->update(['sibling_document_id' => $created[1]->id]);
            $created[1]->update(['sibling_document_id' => $created[0]->id]);
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
        $validated = $request->validated();
        $markCurrent = (bool) ($validated['mark_as_current'] ?? false);
        unset($validated['mark_as_current']);

        try {
            DB::transaction(function () use ($period, $policy, $validated, $markCurrent) {
                $period->update($validated);

                if ($markCurrent && $period->policy_status !== 'current') {
                    RuleSet::currentPolicy()
                        ->where('container_id', $policy->id)
                        ->where('id', '!=', $period->id)
                        ->update(['policy_status' => 'superseded']);

                    $period->update(['policy_status' => 'current']);
                }
            });

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

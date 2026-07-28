<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRuleSetDocuments;
use App\Http\Controllers\Concerns\ManagesDocumentFiles;
use App\Http\Requests\StoreRuleSetRequest;
use Illuminate\Http\Request;
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
    use ManagesDocumentFiles, ListsRuleSetDocuments;

    /**
     * Same authorize() logic as Store/UpdateRuleSetRequest, duplicated here because create/edit/
     * destroy render or mutate state outside a FormRequest and previously had no check at all
     * beyond the 'auth' middleware — any authenticated user could view any department's forms or,
     * critically, delete any rule set/policy. See SECURITY.md Pass 5.
     */
    private function authorizeManage(Department $department, string $kind, ?RuleSet $ruleSet = null): void
    {
        $user = auth()->user();

        $allowed = $kind === 'policy'
            ? ($ruleSet ? $user->canManagePolicy($ruleSet) : $user->canManagePolicyForDepartment($department))
            : $user->isAdmin();

        abort_unless($allowed, 403);
    }

    public function index(string $level, Department $department, string $kind = 'rules'): View
    {
        $isGuest = ! auth()->check();
        $visibilityScope = fn ($q) => $isGuest ? $q->where('visibility', 'public') : $q;

        if ($kind === 'policy') {
            // Landing page is just two counts now — browsing itself moved to policyState()/
            // policyOtherStates(). See RuleSet::STATES for why "Uttar Pradesh vs everyone else"
            // is a plain string comparison, not a real state/tenant model.
            $upCount = $department->ruleSets()->currentPolicy()->where('state', RuleSet::DEFAULT_STATE)->count();
            $otherStatesCount = $department->ruleSets()->currentPolicy()
                ->where('state', '!=', RuleSet::DEFAULT_STATE)
                ->distinct('state')->count('state');

            return view('rule_sets.index', compact('department', 'kind', 'upCount', 'otherStatesCount'));
        }

        $ruleSets = $department->ruleSets()->rules()
            ->withCount([
                'documents' => $visibilityScope,
                'documents as documents_converted_count' => fn ($q) => $visibilityScope($q)->whereNotNull('markdown_path'),
            ])->orderBy('name')->get();

        return view('rule_sets.index', compact('department', 'kind', 'ruleSets'));
    }

    /**
     * One state's policy containers + their policy documents, rendered as a grid. Serves both
     * the "Uttar Pradesh Policy" landing card and every "Other States' Policy" card — same
     * view, filtered by state, no UP-specific code path.
     */
    public function policyState(string $level, Department $department, string $state): View
    {
        $stateName = RuleSet::stateFromSlug($state);
        abort_if($stateName === null, 404);

        $containers = $department->ruleSets()->policyContainers()
            ->where('state', $stateName)
            ->with(['policyDocuments' => fn ($q) => $q->withCount('documents')])
            ->orderBy('name')
            ->get();

        return view('rule_sets.policy_state', compact('department', 'stateName', 'containers'));
    }

    /** Grid of every state/UT except Uttar Pradesh, with a current-policy count per state. */
    public function policyOtherStates(string $level, Department $department): View
    {
        $counts = $department->ruleSets()->currentPolicy()
            ->where('state', '!=', RuleSet::DEFAULT_STATE)
            ->selectRaw('state, count(*) as c')
            ->groupBy('state')
            ->pluck('c', 'state');

        $toCard = fn ($s) => ['name' => $s, 'count' => $counts[$s] ?? 0];

        $states = collect(RuleSet::STATES)
            ->reject(fn ($s) => $s === RuleSet::DEFAULT_STATE || in_array($s, RuleSet::UNION_TERRITORIES, true))
            ->map($toCard)->values();

        $unionTerritories = collect(RuleSet::UNION_TERRITORIES)->map($toCard)->values();

        return view('rule_sets.policy_other_states', compact('department', 'states', 'unionTerritories'));
    }

    public function create(string $level, Department $department, string $kind = 'rules'): View
    {
        $this->authorizeManage($department, $kind);

        $defaultPolicyType = match (true) {
            str_contains($department->slug, 'excise') => 'excise_policy',
            str_contains($department->slug, 'cane')   => 'cane_policy',
            str_contains($department->slug, 'sugar')  => 'sugar_policy',
            default                                    => null,
        };

        return view('rule_sets.create', compact('department', 'kind', 'defaultPolicyType'));
    }

    public function store(StoreRuleSetRequest $request, string $level, Department $department, string $kind = 'rules'): RedirectResponse
    {
        try {
            $finalName = null;
            DB::transaction(function () use ($request, $department, $kind, &$finalName) {
                $validated = $request->validated();
                $validated['name'] = $finalName = RuleSet::withKindSuffix($validated['name'], $kind);
                $slug = RuleSet::uniqueSlugForDepartment($validated['name'], $department->id);

                // For kind=policy this creates a container only (state + policy_type,
                // container_id left null) — created once. Yearly policy documents are
                // added underneath it via PolicyDocumentController.
                $department->ruleSets()->create([
                    ...$validated,
                    'slug' => $slug,
                    'kind' => $kind,
                ]);
            });

            $label = $kind === 'policy' ? 'Policy' : 'Rule set';
            flash()->success("{$label} \"{$finalName}\" created.");
            return redirect()->route('departments.show', [$department->levelAlias(), $department]);
        } catch (\Throwable $e) {
            Log::error('RuleSetController@store failed', [
                'dept_id' => $department->id,
                'kind'    => $kind,
                'error'   => $e->getMessage(),
            ]);
            flash()->error('Failed to create rule set. Please try again.');
            return back()->withInput();
        }
    }

    public function show(Request $request, string $level, Department $department, RuleSet $ruleSet): View
    {
        // A kind=policy RuleSet reached here is always a container (state + policy_type,
        // created once) — its yearly policy documents live underneath it, handled by
        // PolicyDocumentController. Containers never hold documents directly.
        if ($ruleSet->kind === 'policy') {
            $policyDocuments = $ruleSet->policyDocuments()->withCount('documents')->get();

            return view('rule_sets.policy_container', compact('department', 'ruleSet', 'policyDocuments'));
        }

        return view('rule_sets.show', array_merge(
            compact('department', 'ruleSet'),
            $this->loadRuleSetDocuments($ruleSet, $request)
        ));
    }

    public function edit(string $level, Department $department, RuleSet $ruleSet): View
    {
        $this->authorizeManage($department, $ruleSet->kind, $ruleSet);

        return view('rule_sets.edit', compact('department', 'ruleSet'));
    }

    public function update(UpdateRuleSetRequest $request, string $level, Department $department, RuleSet $ruleSet): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $ruleSet) {
                $validated = $request->validated();
                if (array_key_exists('name', $validated) && $validated['name'] !== $ruleSet->name) {
                    $validated['name'] = RuleSet::withKindSuffix($validated['name'], $ruleSet->kind);
                }
                $ruleSet->update($validated);
            });

            $label = $ruleSet->kind === 'policy' ? 'Policy' : 'Rule set';
            flash()->success("{$label} \"{$ruleSet->name}\" updated.");
            return redirect()->route("departments.{$ruleSet->kind}.show", [$department->levelAlias(), $department, $ruleSet]);
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
        $this->authorizeManage($department, $ruleSet->kind, $ruleSet);

        $docsToArchive = [];

        try {
            DB::transaction(function () use ($ruleSet, &$docsToArchive) {
                // Soft-delete all documents in this rule set with an audit entry
                $ruleSet->documents()->each(function (Document $doc) use (&$docsToArchive) {
                    DocumentStatusHistory::create([
                        'document_id' => $doc->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => $doc->status,
                        'to_status'   => 'deleted',
                        'note'        => 'Deleted with parent rule set.',
                    ]);
                    $doc->delete();
                    $docsToArchive[] = $doc;
                });

                $ruleSet->delete();
            });

            foreach ($docsToArchive as $doc) {
                $this->archiveFiles($doc);
            }

            $label = $ruleSet->kind === 'policy' ? 'Policy' : 'Rule set';
            flash()->success("{$label} \"{$ruleSet->name}\" and all its documents deleted.");
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

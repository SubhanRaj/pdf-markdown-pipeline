<?php

namespace App\Http\Controllers;

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
    use ManagesDocumentFiles;

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

    public function show(Request $request, string $level, Department $department, RuleSet $ruleSet): View
    {
        $sort       = $request->get('sort', 'amendment_number_desc');
        $filterYear = (int) $request->get('year', 0);

        // Root documents with amendments pre-loaded
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

        // Collect all years present in amendments for the filter dropdown
        $availableYears = $rootDocuments
            ->flatMap(fn ($root) => $root->amendments)
            ->map(fn ($a) => ($a->metadata['effective_year'] ?? null))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        // Apply sort and optional year filter to each root's amendments collection
        $rootDocuments->each(function ($root) use ($sort, $filterYear) {
            $amendments = $root->amendments;

            if ($filterYear) {
                $amendments = $amendments->filter(
                    fn ($a) => ($a->metadata['effective_year'] ?? null) == $filterYear
                );
            }

            $amendments = match ($sort) {
                'amendment_number_asc'  => $amendments->sortBy(fn ($a) => $a->metadata['amendment_number'] ?? PHP_INT_MAX),
                'year_desc'             => $amendments->sortByDesc(fn ($a) => $a->metadata['effective_year'] ?? 0),
                'year_asc'              => $amendments->sortBy(fn ($a) => $a->metadata['effective_year'] ?? PHP_INT_MAX),
                'uploaded_asc'          => $amendments->sortBy('created_at'),
                'uploaded_desc'         => $amendments->sortByDesc('created_at'),
                default                 => $amendments->sortByDesc(fn ($a) => $a->metadata['amendment_number'] ?? -PHP_INT_MAX),
            };

            $root->setRelation('amendments', $amendments->values());
        });

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

        return view('rule_sets.show', compact('department', 'ruleSet', 'rootDocuments', 'totalCount', 'parentOptions', 'sort', 'filterYear', 'availableYears'));
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

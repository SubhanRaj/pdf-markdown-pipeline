<?php

namespace App\Http\Controllers\Concerns;

use App\Models\RuleSet;
use Illuminate\Http\Request;

/**
 * Shared root-document + amendment listing/sort/year-filter logic for a single RuleSet
 * row's document view — used identically by RuleSetController (kind=rules) and
 * PolicyPeriodController (a policy period, which is also just a RuleSet row).
 */
trait ListsRuleSetDocuments
{
    private function loadRuleSetDocuments(RuleSet $ruleSet, Request $request): array
    {
        $sort       = $request->get('sort', 'amendment_number_desc');
        $filterYear = (int) $request->get('year', 0);

        $rootDocuments = $ruleSet->documents()
            ->publishable()
            ->with([
                'user:id,name',
                'amendments' => fn ($q) => $q
                    ->publishable()
                    ->with('user:id,name')
                    ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
                    ->orderBy('created_at'),
            ])
            ->whereNull('parent_id')
            ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
            ->orderBy('created_at')
            ->get();

        $availableYears = $rootDocuments
            ->flatMap(fn ($root) => $root->amendments)
            ->map(fn ($a) => ($a->metadata['effective_year'] ?? null))
            ->filter()
            ->unique()
            ->sort()
            ->values();

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

        $parentOptions = auth()->check()
            ? $ruleSet->documents()
                ->select('id', 'title', 'created_at')
                ->whereNull('parent_id')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'date' => $d->created_at->format('d M Y')])
                ->values()
            : collect();

        $supersededBy = $ruleSet->policy_status === 'superseded' ? $ruleSet->supersededBy : null;

        return compact('rootDocuments', 'totalCount', 'parentOptions', 'sort', 'filterYear', 'availableYears', 'supersededBy');
    }
}

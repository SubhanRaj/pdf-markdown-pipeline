<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Department;
use App\Models\Division;
use App\Models\RuleSet;
use App\Models\Section;
use App\Models\User;

/**
 * Departments/sections/divisions/folders/rule-sets the current user may upload to, scoped by
 * User::uploadScope() so the picker never offers a context that would 403 on submit. Shared by
 * DocumentController (bulk-upload picker) and QuickConversionController ("Save to…" picker).
 */
trait BuildsUploadScopeTree
{
    private function buildUploadScopeTree(User $user): array
    {
        $scope = $user->uploadScope();

        if ($scope === 'none') {
            return [];
        }

        $departments = Department::query()
            ->when($scope === 'department', fn ($q) => $q->where('id', $user->department_id))
            ->when($scope === 'section', fn ($q) => $q->whereHas('sections', fn ($q2) => $q2->where('id', $user->section_id)))
            ->when($scope === 'division', fn ($q) => $q->whereHas('sections.divisions', fn ($q2) => $q2->where('id', $user->division_id)))
            ->orderBy('name')
            ->get();

        $mapParentOptions = fn ($query) => $query
            ->select('id', 'title', 'created_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'date' => $d->created_at->format('d M Y')])
            ->values();

        return $departments->map(function (Department $department) use ($scope, $user, $mapParentOptions) {
            $sections = $department->sections()
                ->when($scope === 'section', fn ($q) => $q->where('id', $user->section_id))
                ->when($scope === 'division', fn ($q) => $q->whereHas('divisions', fn ($q2) => $q2->where('id', $user->division_id)))
                ->orderBy('name')
                ->get()
                ->map(function (Section $section) use ($scope, $user, $mapParentOptions) {
                    // Subfolders — one level deep, see the folders.parent_id migration.
                    $mapFolder = fn ($f) => [
                        'id'            => $f->id,
                        'name'          => $f->name,
                        'parentOptions' => $mapParentOptions($f->documents()->whereNull('parent_id')),
                        'subfolders'    => $f->children()->get()->map(fn ($c) => [
                            'id'            => $c->id,
                            'name'          => $c->name,
                            'parentOptions' => $mapParentOptions($c->documents()->whereNull('parent_id')),
                        ])->values(),
                    ];

                    $divisions = $section->divisions()
                        ->when($scope === 'division', fn ($q) => $q->where('id', $user->division_id))
                        ->get()
                        ->map(fn (Division $division) => [
                            'id'      => $division->id,
                            'name'    => $division->name,
                            'folders' => $division->folders()->get()->map($mapFolder)->values(),
                        ])->values();

                    return [
                        'id'      => $section->id,
                        'name'    => $section->name,
                        'wing'    => $section->wing,
                        'folders' => $scope === 'division' ? [] : $section->folders()->get()->map($mapFolder)->values(),
                        // Reused for the section itself AND every division under it —
                        // amendments are allowed to cross division boundaries within a section.
                        'parentOptions' => $mapParentOptions($section->documents()->whereNull('division_id')),
                        'divisions'     => $divisions,
                    ];
                })->values();

            $ruleSets = in_array($scope, ['global', 'department'], true)
                ? $department->ruleSets()->rules()->orderBy('name')->get()->map(function (RuleSet $ruleSet) use ($mapParentOptions) {
                    $rootDocs = $ruleSet->documents()->whereNull('parent_id')->get(['id', 'document_type']);

                    return [
                        'id'            => $ruleSet->id,
                        'kind'          => 'rules',
                        'name'          => $ruleSet->name,
                        'hasRuleDoc'    => $rootDocs->where('document_type', 'rule')->isNotEmpty(),
                        'parentOptions' => $mapParentOptions($ruleSet->documents()->whereNull('parent_id')),
                    ];
                })->values()
                : collect();

            // Policy management is stricter than the generic upload scope (admin or the
            // department's own department.head only — see User::canManagePolicy()). Merged into
            // the same "Rule Set" picker rather than a separate tab — both submit via
            // rule_set_id, so a parallel UI mode would just be a duplicate of this one with a
            // different source array. Superseded policies are included — amendments are allowed
            // on any policy regardless of status.
            $policies = $user->canManagePolicyForDepartment($department)
                ? $department->ruleSets()->policy()->orderBy('name')->get()->map(function (RuleSet $ruleSet) use ($mapParentOptions) {
                    $rootDocs = $ruleSet->documents()->whereNull('parent_id')->get(['id', 'document_type']);

                    return [
                        'id'            => $ruleSet->id,
                        'kind'          => 'policy',
                        'name'          => '[Policy] ' . $ruleSet->name . ($ruleSet->policy_status === 'superseded' ? ' (Superseded)' : ''),
                        'hasRuleDoc'    => $rootDocs->where('document_type', 'policy')->isNotEmpty(),
                        'parentOptions' => $mapParentOptions($ruleSet->documents()->whereNull('parent_id')),
                    ];
                })->values()
                : collect();

            return [
                'id'          => $department->id,
                'name'        => $department->name,
                'level'       => $department->level,
                'levelAlias'  => $department->levelAlias(),
                'levelLabel'  => $department->levelLabel(),
                'sections'    => $sections,
                'ruleSets'    => $ruleSets->concat($policies)->values(),
            ];
        })->values()->all();
    }
}

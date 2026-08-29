<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsZipDownload;
use App\Models\Department;
use App\Models\Division;
use App\Models\Folder;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Support\Str;

/**
 * Bulk "download as zip" for every browse level the site already has: a folder, a division,
 * a section, a rule set, a policy container (all its yearly policy documents), a single policy
 * document, an entire state's policy, or an entire department. Each zip holds every document's
 * original PDF plus its markdown (when present), laid out in the same folder tree as the site.
 */
class DownloadController extends Controller
{
    use BuildsZipDownload;

    private function isGuest(): bool
    {
        return ! auth()->check();
    }

    /** @return array<int, array{document: \App\Models\Document, dir: string}> */
    private function folderEntries(Folder $folder, string $dir): array
    {
        // A guest can't browse this folder at all (FolderController::show() blocks it the same
        // way) — a zip download must not become a side door around that, regardless of what any
        // individual document's own visibility says.
        if ($this->isGuest() && $folder->visibility === 'authenticated') {
            return [];
        }

        $entries = $folder->documents()->publishable()
            ->when($this->isGuest(), fn ($q) => $q->where('visibility', 'public'))
            ->get()
            ->map(fn ($doc) => ['document' => $doc, 'dir' => $dir])
            ->all();

        foreach ($folder->children as $child) {
            $entries = [...$entries, ...$this->folderEntries($child, trim("{$dir}/".Str::slug($child->name), '/'))];
        }

        return $entries;
    }

    /** @return array<int, array{document: \App\Models\Document, dir: string}> */
    private function divisionEntries(Division $division, string $dir): array
    {
        $isGuest = $this->isGuest();

        $entries = $division->documents()->publishable()
            ->when($isGuest, fn ($q) => $q->publiclyVisible())
            ->get()
            ->map(fn ($doc) => ['document' => $doc, 'dir' => $dir])
            ->all();

        foreach ($division->folders as $folder) {
            $entries = [...$entries, ...$this->folderEntries($folder, trim("{$dir}/".Str::slug($folder->name), '/'))];
        }

        return $entries;
    }

    /** @return array<int, array{document: \App\Models\Document, dir: string}> */
    private function sectionEntries(Section $section, string $dir): array
    {
        $isGuest = $this->isGuest();

        $entries = $section->documents()->publishable()
            ->whereNull('division_id')->whereNull('folder_id')
            ->when($isGuest, fn ($q) => $q->where('visibility', 'public'))
            ->get()
            ->map(fn ($doc) => ['document' => $doc, 'dir' => $dir])
            ->all();

        foreach ($section->folders as $folder) {
            $entries = [...$entries, ...$this->folderEntries($folder, trim("{$dir}/".Str::slug($folder->name), '/'))];
        }

        foreach ($section->divisions as $division) {
            $entries = [...$entries, ...$this->divisionEntries($division, trim("{$dir}/".Str::slug($division->name), '/'))];
        }

        return $entries;
    }

    /** A rule set (kind=rules) or a policy container — either way, every document under it. */
    private function ruleSetEntries(RuleSet $ruleSet, string $dir): array
    {
        $isGuest = $this->isGuest();

        if ($ruleSet->kind === 'policy' && $ruleSet->container_id === null) {
            $entries = [];
            foreach ($ruleSet->policyDocuments as $period) {
                $entries = [...$entries, ...$this->ruleSetEntries($period, trim("{$dir}/".Str::slug($period->name), '/'))];
            }

            return $entries;
        }

        return $ruleSet->documents()->publishable()
            ->when($isGuest, fn ($q) => $q->where('visibility', 'public'))
            ->get()
            ->map(fn ($doc) => ['document' => $doc, 'dir' => $dir])
            ->all();
    }

    /** Org-scope ceiling for authenticated non-global users — mirrors the show-page 403 gate. */
    private function authorizeZipView(object $context): void
    {
        if (auth()->check()) {
            abort_unless(auth()->user()->canView($context), 403);
        }
    }

    public function folder(string $level, Department $department, Section $section, Folder $folder)
    {
        $this->authorizeZipView($folder);

        return $this->zipDocuments("{$folder->slug}.zip", $this->folderEntries($folder, ''));
    }

    public function divisionFolder(string $level, Department $department, Section $section, Division $division, Folder $folder)
    {
        $this->authorizeZipView($folder);

        return $this->zipDocuments("{$folder->slug}.zip", $this->folderEntries($folder, ''));
    }

    public function division(string $level, Department $department, Section $section, Division $division)
    {
        $this->authorizeZipView($division);

        return $this->zipDocuments("{$division->slug}.zip", $this->divisionEntries($division, ''));
    }

    public function section(string $level, Department $department, Section $section)
    {
        $this->authorizeZipView($section);

        return $this->zipDocuments("{$section->slug}.zip", $this->sectionEntries($section, ''));
    }

    public function ruleSet(string $level, Department $department, RuleSet $ruleSet)
    {
        return $this->zipDocuments("{$ruleSet->slug}.zip", $this->ruleSetEntries($ruleSet, ''));
    }

    public function policyPeriod(string $level, Department $department, RuleSet $policy, RuleSet $policyDoc)
    {
        return $this->zipDocuments("{$policyDoc->slug}.zip", $this->ruleSetEntries($policyDoc, ''));
    }

    /** Every policy container for one state (e.g. all of Punjab's policies), as one zip. */
    public function policyState(string $level, Department $department, string $state)
    {
        $stateName = RuleSet::stateFromSlug($state);
        abort_if($stateName === null, 404);

        $containers = $department->ruleSets()->policyContainers()->where('state', $stateName)->get();

        $entries = [];
        foreach ($containers as $container) {
            $entries = [...$entries, ...$this->ruleSetEntries($container, Str::slug($container->name))];
        }

        return $this->zipDocuments(Str::slug($stateName).'.zip', $entries);
    }

    /** Every rule set (kind=rules) in the department, as one zip — the "Rules & Regulations" listing page. */
    public function rules(string $level, Department $department)
    {
        $entries = [];
        foreach ($department->ruleSets()->rules()->get() as $ruleSet) {
            $entries = [...$entries, ...$this->ruleSetEntries($ruleSet, Str::slug($ruleSet->name))];
        }

        return $this->zipDocuments("{$department->slug}-rules.zip", $entries);
    }

    /** Everything in the department: direct documents, every section, every rule set, every policy. */
    public function department(string $level, Department $department)
    {
        // Whole-department bulk export — only a department-wide-or-broader scope may pull this;
        // canView() itself doesn't resolve a bare Department (it's built for Section/Division/
        // Folder), so check the tier directly rather than force a context it isn't meant for.
        if (auth()->check()) {
            $user  = auth()->user();
            $scope = $user->uploadScope();
            abort_unless(
                $scope === 'global' || $scope === 'none' || ($scope === 'department' && $user->department_id === $department->id),
                403
            );
        }

        $isGuest = $this->isGuest();

        $entries = $department->documents()->publishable()
            ->when($isGuest, fn ($q) => $q->publiclyVisible())
            ->get()
            ->map(fn ($doc) => ['document' => $doc, 'dir' => ''])
            ->all();

        foreach ($department->sections as $section) {
            $entries = [...$entries, ...$this->sectionEntries($section, Str::slug($section->name))];
        }

        foreach ($department->ruleSets()->rules()->get() as $ruleSet) {
            $entries = [...$entries, ...$this->ruleSetEntries($ruleSet, 'rules/'.Str::slug($ruleSet->name))];
        }

        foreach ($department->ruleSets()->policyContainers()->get() as $container) {
            $entries = [...$entries, ...$this->ruleSetEntries($container, 'policy/'.Str::slug($container->state).'/'.Str::slug($container->name))];
        }

        return $this->zipDocuments("{$department->slug}.zip", $entries);
    }
}

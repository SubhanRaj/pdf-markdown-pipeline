<?php

namespace App\Http\Requests\Concerns;

use App\Models\Division;
use App\Models\Folder;
use App\Models\RuleSet;
use App\Models\Section;

/**
 * Shared destination validation + authorization, used by both StoreDocumentRequest (upload) and
 * PlaceQuickConversionRequest ("Save to…"). Exactly one of section_id/rule_set_id must be given;
 * division_id/folder_id are optional refinements within a section. A policy-kind rule set is
 * gated by canManagePolicy() instead of the generic canUploadTo().
 */
trait ResolvesUploadDestination
{
    protected function authorizeDestination(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        // Input is not yet validated at authorize() time, so cast defensively.
        $folderId   = (int) $this->input('folder_id')   ?: null;
        $divisionId = (int) $this->input('division_id') ?: null;
        $sectionId  = (int) $this->input('section_id')  ?: null;
        $ruleSetId  = (int) $this->input('rule_set_id') ?: null;

        if ($folderId) {
            $context = Folder::find($folderId);
            if ($context && $sectionId && $context->section_id !== $sectionId) {
                return false;
            }
            if ($context && $context->division_id && $context->division_id !== $divisionId) {
                return false;
            }
        } elseif ($divisionId) {
            $context = Division::find($divisionId);
            if ($context && $sectionId && $context->section_id !== $sectionId) {
                return false;
            }
        } elseif ($sectionId) {
            $context = Section::find($sectionId);
        } elseif ($ruleSetId) {
            $context = RuleSet::find($ruleSetId);
        } else {
            return false;
        }

        if (! $context) {
            return false;
        }

        if ($context instanceof RuleSet && $context->kind === 'policy') {
            return $user->canManagePolicy($context);
        }

        return $user->canUploadTo($context);
    }

    /** @return array<string, array<mixed>> */
    protected function destinationRules(): array
    {
        return [
            // Exactly one of section_id or rule_set_id must be provided.
            // division_id is optional and only valid alongside section_id.
            'section_id'  => ['required_without:rule_set_id', 'nullable', 'integer', 'exists:sections,id'],
            'rule_set_id' => ['required_without:section_id',  'nullable', 'integer', 'exists:rule_sets,id'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'folder_id'   => ['nullable', 'integer', 'exists:folders,id'],
        ];
    }

    protected function destinationMessages(): array
    {
        return [
            'section_id.required_without'  => 'A section or rule set must be selected.',
            'rule_set_id.required_without' => 'A section or rule set must be selected.',
        ];
    }
}

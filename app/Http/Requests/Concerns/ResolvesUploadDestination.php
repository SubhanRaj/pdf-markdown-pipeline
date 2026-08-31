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
            // One of section_id/division_id/folder_id/rule_set_id must be provided — a
            // division or folder upload already identifies its section through that record
            // (see DocumentController::store()), so section_id itself is only required when
            // none of the other three narrower destinations were given either.
            'section_id'  => ['required_without_all:rule_set_id,division_id,folder_id', 'nullable', 'integer', 'exists:sections,id'],
            'rule_set_id' => ['required_without_all:section_id,division_id,folder_id',  'nullable', 'integer', 'exists:rule_sets,id'],
            'division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'folder_id'   => ['nullable', 'integer', 'exists:folders,id'],
        ];
    }

    protected function destinationMessages(): array
    {
        return [
            'section_id.required_without_all'  => 'A section, division, folder, or rule set must be selected.',
            'rule_set_id.required_without_all' => 'A section, division, folder, or rule set must be selected.',
        ];
    }
}

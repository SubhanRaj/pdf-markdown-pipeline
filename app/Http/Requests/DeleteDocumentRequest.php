<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteDocumentRequest extends FormRequest
{
    /**
     * Soft-delete (archive): any authenticated user who has delete scope for the document's context.
     * The route-bound $document is resolved before this runs, so we read it from the route.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        // Resolve context from the route-bound document (section, division, or rule set)
        $document = $this->route('doc') ?? $this->route('document');

        if ($document) {
            if ($document->division) {
                $context = $document->division;
            } elseif ($document->section) {
                $context = $document->section;
            } elseif ($document->ruleSet) {
                $context = $document->ruleSet;
            } else {
                return false;
            }

            if ($context instanceof \App\Models\RuleSet && $context->kind === 'policy') {
                return $user->canManagePolicy($context);
            }

            return $user->canDeleteFrom($context);
        }

        // Fallback: if no document on route (e.g. bulk), require delete privilege
        return $user->hasPrivilege('documents.delete');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => strip_tags(trim($this->input('reason', ''))),
        ]);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'A deletion reason is required for audit purposes.',
            'reason.min'      => 'Reason must be at least 5 characters.',
            'reason.max'      => 'Reason may not exceed 500 characters.',
        ];
    }
}

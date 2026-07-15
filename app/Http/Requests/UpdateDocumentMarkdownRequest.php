<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentMarkdownRequest extends FormRequest
{
    /**
     * Editing extracted Markdown during review is admin-only, same gate as Edit/Delete/Convert —
     * except for a policy-kind rule-set document, where the owning department's department.head
     * may also manage the full lifecycle (see RuleSet::kind, User::canManagePolicy()).
     */
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $document = Document::find($this->route('id'));
        $ruleSet  = $document?->ruleSet;

        return $ruleSet !== null && $ruleSet->kind === 'policy' && $user->canManagePolicy($ruleSet);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'verify' => $this->boolean('verify'),
        ]);
    }

    public function rules(): array
    {
        return [
            'content' => ['required', 'string', 'max:2000000'],
            'verify'  => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Markdown content cannot be empty.',
            'content.max'       => 'Markdown content is too large.',
        ];
    }
}

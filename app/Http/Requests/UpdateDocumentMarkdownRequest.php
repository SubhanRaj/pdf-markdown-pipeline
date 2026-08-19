<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentMarkdownRequest extends FormRequest
{
    /**
     * Same gate as convert/OCR/discard/revert (DocumentController::canManageDocument()) —
     * system_admin unconditionally; a policy-kind rule-set document's owning department.head;
     * or (M79, 2026-08-19) any user holding documents.verify scoped via canUploadTo() against
     * the document's own division/section/rule-set context. Duplicated here (not delegated to
     * the controller helper) because FormRequest::authorize() runs before the controller method
     * body — kept in sync by hand; if this drifts from canManageDocument() again, merge them.
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

        if ($ruleSet !== null && $ruleSet->kind === 'policy' && $user->canManagePolicy($ruleSet)) {
            return true;
        }

        $context = $document?->division ?? $document?->section ?? $ruleSet;

        return $context !== null && $user->hasPrivilege('documents.verify') && $user->canUploadTo($context);
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

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteDocumentsRequest extends FormRequest
{
    /**
     * Privilege-only gate — same shape as BulkRestoreDocumentsRequest. Per-document *scope* is
     * enforced inside DocumentController::bulkDestroy()'s loop (canDeleteFrom() per document,
     * admins bypass), not here; a blanket isAdmin()-only gate here would have (as of M79) let
     * only system_admin bulk-delete anything, leaving a scoped admin/operator with a real
     * documents.delete privilege unable to bulk-delete even their own section's documents.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->hasPrivilege('documents.delete');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('reason')) {
            $this->merge(['reason' => strip_tags(trim($this->input('reason', '')))]);
        }
    }

    public function rules(): array
    {
        return [
            'ids'    => ['required', 'array', 'min:1', 'max:100'],
            'ids.*'  => ['required', 'integer', 'exists:documents,id'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'   => 'No documents selected.',
            'ids.min'        => 'Select at least one document.',
            'ids.max'        => 'Cannot delete more than 100 documents at once.',
            'ids.*.exists'   => 'One or more selected documents no longer exist.',
            'reason.required' => 'A deletion reason is required.',
            'reason.min'      => 'Reason must be at least 5 characters.',
        ];
    }
}

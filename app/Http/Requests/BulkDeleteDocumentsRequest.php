<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkDeleteDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
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

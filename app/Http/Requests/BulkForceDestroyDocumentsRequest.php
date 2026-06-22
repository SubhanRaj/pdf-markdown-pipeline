<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkForceDestroyDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'No documents selected.',
            'ids.min'      => 'Select at least one document.',
            'ids.max'      => 'Cannot permanently delete more than 100 documents at once.',
        ];
    }
}

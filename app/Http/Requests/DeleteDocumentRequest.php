<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
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

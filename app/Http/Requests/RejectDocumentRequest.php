<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && ($user->isAdmin() || $user->hasPrivilege('documents.approve'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim(strip_tags($this->reason ?? '')),
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
            'reason.required' => 'A rejection reason is required.',
            'reason.min'      => 'Rejection reason must be at least 5 characters.',
            'reason.max'      => 'Rejection reason may not exceed 500 characters.',
        ];
    }
}

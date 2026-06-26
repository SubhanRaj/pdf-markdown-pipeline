<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && ($user->isAdmin() || $user->hasPrivilege('documents.approve'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->note) {
            $this->merge(['note' => trim(strip_tags($this->note))]);
        }
    }

    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}

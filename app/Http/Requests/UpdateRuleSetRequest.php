<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRuleSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name'        => strip_tags(trim($this->name ?? '')),
            'description' => strip_tags(trim($this->description ?? '')) ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'description'      => ['nullable', 'string', 'max:500'],
            'requires_approval' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Rule set name is required.',
            'name.regex'    => 'Name contains invalid characters.',
        ];
    }
}

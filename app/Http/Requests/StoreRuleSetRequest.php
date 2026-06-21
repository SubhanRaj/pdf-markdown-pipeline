<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRuleSetRequest extends FormRequest
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
            'name'        => ['required', 'string', 'regex:/^[\p{L}0-9\s\(\)\-\.\/&\']{2,150}$/u'],
            'description' => ['nullable', 'string', 'max:500'],
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

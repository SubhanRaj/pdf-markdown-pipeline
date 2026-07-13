<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentMarkdownRequest extends FormRequest
{
    /** Editing extracted Markdown during review is an admin-only action, same gate as Edit/Delete/Convert. */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
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

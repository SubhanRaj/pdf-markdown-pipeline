<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge(['title' => strip_tags(trim($this->input('title', '')))]);
        }
        if ($this->has('visibility')) {
            $this->merge(['visibility' => strtolower(trim($this->input('visibility', 'public')))]);
        }
    }

    public function rules(): array
    {
        return [
            'title'         => ['sometimes', 'required', 'string', 'min:3', 'max:255', 'regex:/^[\p{L}0-9\s\-\(\)\.,\/&#;:]+$/u'],
            'document_type' => ['sometimes', 'required', 'string', 'in:' . implode(',', array_keys(Document::DOCUMENT_TYPES))],
            'status'        => ['sometimes', 'required', 'string', 'in:' . implode(',', array_keys(Document::STATUSES))],
            'visibility'    => ['sometimes', 'nullable', 'string', 'in:public,authenticated'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.min'            => 'Title must be at least 3 characters.',
            'title.max'            => 'Title may not exceed 255 characters.',
            'title.regex'          => 'Title contains invalid characters.',
            'document_type.in'     => 'Invalid document type selected.',
            'status.in'            => 'Invalid status selected.',
            'visibility.in'        => 'Visibility must be public or authenticated.',
        ];
    }
}

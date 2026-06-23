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
        if ($this->has('parent_id')) {
            $this->merge(['parent_id' => $this->parent_id ? (int) $this->parent_id : null]);
        }
        foreach (['amendment_number', 'effective_year', 'effective_month', 'effective_day'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->$field ? (int) $this->$field : null]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'title'            => ['sometimes', 'required', 'string', 'min:3', 'max:255', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'document_type'    => ['sometimes', 'required', 'string', 'in:' . implode(',', array_keys(Document::DOCUMENT_TYPES))],
            'status'           => ['sometimes', 'required', 'string', 'in:' . implode(',', array_keys(Document::STATUSES))],
            'visibility'       => ['sometimes', 'nullable', 'string', 'in:public,authenticated'],
            'parent_id'        => ['sometimes', 'nullable', 'integer', 'exists:documents,id'],
            'amendment_number' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:999'],
            'effective_year'   => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2099'],
            'effective_month'  => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'effective_day'    => ['sometimes', 'nullable', 'integer', 'min:1', 'max:31'],
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

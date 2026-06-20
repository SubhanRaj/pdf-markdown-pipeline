<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title'         => strip_tags(trim($this->title ?? '')),
            'document_type' => strtolower(trim($this->document_type ?? '')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $validTypes = implode(',', array_keys(Document::DOCUMENT_TYPES));

        return [
            'section_id'    => ['required', 'integer', 'exists:sections,id'],
            'title'         => ['required', 'string', 'max:255', 'regex:/^[\p{L}\p{N}\s\-_.,()\/\#\&]+$/u'],
            'document_type' => ['required', 'string', "in:{$validTypes}"],
            'file'          => ['required', 'file', 'mimes:pdf', 'max:51200'], // 50 MB
        ];
    }

    public function messages(): array
    {
        return [
            'title.regex'         => 'Title contains invalid characters.',
            'document_type.in'    => 'Please select a valid document type.',
            'file.mimes'          => 'Only PDF files are accepted.',
            'file.max'            => 'File size must not exceed 50 MB.',
        ];
    }
}

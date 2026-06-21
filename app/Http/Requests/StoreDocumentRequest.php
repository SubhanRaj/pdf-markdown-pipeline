<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreDocumentRequest extends FormRequest
{
    // Accepted MIME types — validated against actual file signature (magic bytes), not extension.
    // mimetypes: rule uses PHP's Fileinfo extension, not client-supplied Content-Type.
    public const ACCEPTED_MIMETYPES = [
        // Documents
        'application/pdf',
        'application/msword',                                                          // .doc
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',    // .docx
        'application/vnd.ms-excel',                                                   // .xls
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',          // .xlsx
        'application/vnd.ms-powerpoint',                                              // .ppt
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',  // .pptx
        'application/vnd.oasis.opendocument.text',                                    // .odt
        'application/vnd.oasis.opendocument.spreadsheet',                             // .ods
        'application/vnd.oasis.opendocument.presentation',                            // .odp
        'application/rtf',
        'text/plain',
        'text/csv',
        // Images
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/tiff',
        'image/bmp',
        'image/heic',
        'image/heif',
        'image/svg+xml',
    ];

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
        $validTypes     = implode(',', array_keys(Document::DOCUMENT_TYPES));
        $acceptedMimes  = implode(',', self::ACCEPTED_MIMETYPES);

        return [
            // One of section_id or rule_set_id must be provided — not both
            'section_id'    => ['required_without:rule_set_id', 'nullable', 'integer', 'exists:sections,id'],
            'rule_set_id'   => ['required_without:section_id',  'nullable', 'integer', 'exists:rule_sets,id'],
            'title'         => ['required', 'string', 'max:255', 'regex:/^[\p{L}\p{N}\s\-_.,()\/\#\&]+$/u'],
            'document_type' => ['required', 'string', "in:{$validTypes}"],
            'file'          => ['required', 'file', "mimetypes:{$acceptedMimes}", 'max:51200'], // 50 MB
        ];
    }

    // Always return JSON for validation failures — this endpoint is AJAX-only
    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'The given data was invalid.',
            'errors'  => $validator->errors(),
        ], 422));
    }

    public function messages(): array
    {
        return [
            'section_id.required_without'  => 'A section or rule set must be selected.',
            'rule_set_id.required_without' => 'A section or rule set must be selected.',
            'title.regex'                  => 'Title contains invalid characters.',
            'document_type.in'             => 'Please select a valid document type.',
            'file.mimetypes'               => 'Unsupported file type. Accepted: PDF, Word, Excel, PowerPoint, ODT, images (JPEG/PNG/WebP/GIF/TIFF/BMP/HEIC), RTF, TXT, CSV.',
            'file.max'                     => 'File size must not exceed 50 MB.',
        ];
    }
}

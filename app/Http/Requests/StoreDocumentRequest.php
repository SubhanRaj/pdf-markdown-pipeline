<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesUploadDestination;
use App\Models\Document;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreDocumentRequest extends FormRequest
{
    use ResolvesUploadDestination;

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
    ];

    public function authorize(): bool
    {
        return $this->authorizeDestination();
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title'            => strip_tags(trim($this->title ?? '')),
            'document_type'    => strtolower(trim($this->document_type ?? '')),
            'language'         => strtolower(trim($this->language ?? 'english')),
            'visibility'       => strtolower(trim($this->visibility ?? 'public')),
            'parent_id'        => $this->parent_id        ? (int) $this->parent_id        : null,
            'division_id'      => $this->division_id      ? (int) $this->division_id      : null,
            'folder_id'        => $this->folder_id        ? (int) $this->folder_id        : null,
            'amendment_number' => $this->amendment_number ? (int) $this->amendment_number : null,
            'effective_year'   => $this->effective_year   ? (int) $this->effective_year   : null,
            'effective_month'  => $this->effective_month  ? (int) $this->effective_month  : null,
            'effective_day'    => $this->effective_day    ? (int) $this->effective_day    : null,
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
            ...$this->destinationRules(),
            'parent_id'     => ['nullable', 'integer', 'exists:documents,id'],
            // The closure rejects titles with no letters at all — the upload form auto-fills
            // the title field from the file's name (see fileToTitle() in
            // bulk-upload.blade.php/rule_sets/show.blade.php) as a convenience default, and a
            // source filename that's purely a document/gazette number (e.g. "1776420884.pdf")
            // slips through unedited more easily than you'd think. A numbers-only title also
            // becomes a numbers-only, non-descriptive slug (its one-time source, see
            // Document::uniqueSlugForRuleSet()), which defeats the whole point of a readable,
            // shareable URL. Real titles always contain at least one letter in any language.
            // A closure (not a second `regex:` rule) so it gets its own, specific message —
            // Laravel can't attach distinct custom messages to two `regex` rules on one field.
            'title' => [
                'required', 'string', 'max:255', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (is_string($value) && ! preg_match('/\p{L}/u', $value)) {
                        $fail('Title must contain at least one letter — a filename or ID number alone makes a poor, unreadable document URL.');
                    }
                },
            ],
            'document_type' => ['required', 'string', "in:{$validTypes}"],
            'language'      => ['nullable', 'string', 'in:english,hindi,both'],
            'visibility'    => ['nullable', 'string', 'in:public,authenticated'],
            'file'             => ['required', 'file', "mimetypes:{$acceptedMimes}", 'max:307200'], // 300 MB
            'amendment_number' => ['nullable', 'integer', 'min:1', 'max:999'],
            'effective_year'   => ['nullable', 'integer', 'min:1900', 'max:2099'],
            'effective_month'  => ['nullable', 'integer', 'min:1', 'max:12'],
            'effective_day'    => ['nullable', 'integer', 'min:1', 'max:31'],
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
            ...$this->destinationMessages(),
            'title.regex'                  => 'Title contains invalid characters.',
            'document_type.in'             => 'Please select a valid document type.',
            'file.mimetypes'               => 'Unsupported file type. Accepted: PDF, Word, Excel, PowerPoint, ODT, images (JPEG/PNG/WebP/GIF/TIFF/BMP/HEIC), RTF, TXT, CSV. SVG files are not permitted.',
            'file.max'                     => 'File size must not exceed 300 MB.',
        ];
    }
}

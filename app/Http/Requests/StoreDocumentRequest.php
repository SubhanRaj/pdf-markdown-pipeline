<?php

namespace App\Http\Requests;

use App\Models\Division;
use App\Models\Document;
use App\Models\RuleSet;
use App\Models\Section;
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
    ];

    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        // Resolve the upload context from the validated IDs.
        // Input is not yet validated at authorize() time, so cast defensively.
        $divisionId  = (int) $this->input('division_id')  ?: null;
        $sectionId   = (int) $this->input('section_id')   ?: null;
        $ruleSetId   = (int) $this->input('rule_set_id')  ?: null;

        if ($divisionId) {
            $context = Division::find($divisionId);
            // Division must belong to the provided section when both are given
            if ($context && $sectionId && $context->section_id !== $sectionId) {
                return false;
            }
        } elseif ($sectionId) {
            $context = Section::find($sectionId);
        } elseif ($ruleSetId) {
            $context = RuleSet::find($ruleSetId);
        } else {
            return false;
        }

        if (! $context) {
            return false;
        }

        return $user->canUploadTo($context);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title'            => strip_tags(trim($this->title ?? '')),
            'document_type'    => strtolower(trim($this->document_type ?? '')),
            'visibility'       => strtolower(trim($this->visibility ?? 'public')),
            'parent_id'        => $this->parent_id        ? (int) $this->parent_id        : null,
            'division_id'      => $this->division_id      ? (int) $this->division_id      : null,
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
            // Exactly one of section_id or rule_set_id must be provided.
            // division_id is optional and only valid alongside section_id.
            'section_id'    => ['required_without:rule_set_id', 'nullable', 'integer', 'exists:sections,id'],
            'rule_set_id'   => ['required_without:section_id',  'nullable', 'integer', 'exists:rule_sets,id'],
            'division_id'   => ['nullable', 'integer', 'exists:divisions,id'],
            'parent_id'     => ['nullable', 'integer', 'exists:documents,id'],
            'title'         => ['required', 'string', 'max:255', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'document_type' => ['required', 'string', "in:{$validTypes}"],
            'visibility'    => ['nullable', 'string', 'in:public,authenticated'],
            'file'             => ['required', 'file', "mimetypes:{$acceptedMimes}", 'max:51200'], // 50 MB
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
            'section_id.required_without'  => 'A section or rule set must be selected.',
            'rule_set_id.required_without' => 'A section or rule set must be selected.',
            'title.regex'                  => 'Title contains invalid characters.',
            'document_type.in'             => 'Please select a valid document type.',
            'file.mimetypes'               => 'Unsupported file type. Accepted: PDF, Word, Excel, PowerPoint, ODT, images (JPEG/PNG/WebP/GIF/TIFF/BMP/HEIC), RTF, TXT, CSV. SVG files are not permitted.',
            'file.max'                     => 'File size must not exceed 50 MB.',
        ];
    }
}

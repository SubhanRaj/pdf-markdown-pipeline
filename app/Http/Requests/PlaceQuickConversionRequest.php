<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesUploadDestination;
use App\Models\Document;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** "Save to…" — places a QuickConversion into a real Document. Same destination rules/authorization as StoreDocumentRequest. */
class PlaceQuickConversionRequest extends FormRequest
{
    use ResolvesUploadDestination;

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

    public function rules(): array
    {
        $validTypes = implode(',', array_keys(Document::DOCUMENT_TYPES));

        return [
            ...$this->destinationRules(),
            'parent_id'     => ['nullable', 'integer', 'exists:documents,id'],
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
            'amendment_number' => ['nullable', 'integer', 'min:1', 'max:999'],
            'effective_year'   => ['nullable', 'integer', 'min:1900', 'max:2099'],
            'effective_month'  => ['nullable', 'integer', 'min:1', 'max:12'],
            'effective_day'    => ['nullable', 'integer', 'min:1', 'max:31'],
        ];
    }

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
            'title.regex'       => 'Title contains invalid characters.',
            'document_type.in'  => 'Please select a valid document type.',
        ];
    }
}

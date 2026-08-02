<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreQuickConversionRequest extends FormRequest
{
    /** Any authenticated user with some upload scope may start a quick conversion. */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->uploadScope() !== 'none';
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => $this->title !== null ? strip_tags(trim($this->title)) : null,
        ]);
    }

    public function rules(): array
    {
        $acceptedMimes = implode(',', StoreDocumentRequest::ACCEPTED_MIMETYPES);

        return [
            'title' => ['nullable', 'string', 'max:255'],
            'file'  => ['required', 'file', "mimetypes:{$acceptedMimes}", 'max:307200'], // 300 MB
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
            'file.mimetypes' => 'Unsupported file type. Accepted: PDF, Word, Excel, PowerPoint, ODT, images (JPEG/PNG/WebP/GIF/TIFF/BMP/HEIC), RTF, TXT, CSV. SVG files are not permitted.',
            'file.max'       => 'File size must not exceed 300 MB.',
        ];
    }
}

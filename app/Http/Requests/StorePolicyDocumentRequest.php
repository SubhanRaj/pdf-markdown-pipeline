<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->canManagePolicy($this->route('policy'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name'       => strip_tags(trim($this->name ?? '')),
            'language'   => strtolower(trim($this->language ?? 'english')),
            'visibility' => strtolower(trim($this->visibility ?? 'public')),
        ]);
    }

    public function rules(): array
    {
        $acceptedMimes = implode(',', StoreDocumentRequest::ACCEPTED_MIMETYPES);

        return [
            'name'                  => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'effective_start_date'  => ['nullable', 'date'],
            'effective_end_date'    => ['nullable', 'date', 'after_or_equal:effective_start_date'],
            // The original policy PDF for this policy document — optional so it can still be
            // created ahead of the document being ready, but the form now offers it in the
            // same step instead of forcing a second visit to its own page.
            'file'                  => ['nullable', 'file', "mimetypes:{$acceptedMimes}", 'max:307200'],
            'language'              => ['nullable', 'string', 'in:english,hindi,both'],
            'visibility'            => ['nullable', 'string', 'in:public,authenticated'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                     => 'Policy name is required.',
            'name.regex'                         => 'Name contains invalid characters.',
            'effective_end_date.after_or_equal'  => 'End date must be on or after the start date.',
        ];
    }
}

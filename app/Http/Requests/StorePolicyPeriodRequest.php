<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePolicyPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && $user->canManagePolicy($this->route('policy'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => strip_tags(trim($this->name ?? '')),
        ]);
    }

    public function rules(): array
    {
        return [
            'name'                  => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'effective_start_date'  => ['nullable', 'date'],
            'effective_end_date'    => ['nullable', 'date', 'after_or_equal:effective_start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'                     => 'Period name is required.',
            'name.regex'                         => 'Name contains invalid characters.',
            'effective_end_date.after_or_equal'  => 'End date must be on or after the start date.',
        ];
    }
}

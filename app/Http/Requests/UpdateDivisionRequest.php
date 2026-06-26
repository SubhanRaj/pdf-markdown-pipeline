<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDivisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $section = $this->route('section');

        if ($user->hasPrivilege('section.head') && $section && $user->section_id === $section->id) {
            return true;
        }

        if ($user->hasPrivilege('department.head') && $section && $user->department_id === $section->department_id) {
            return true;
        }

        return false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name'        => strip_tags(trim($this->name ?? '')),
            'description' => strip_tags(trim($this->description ?? '')) ?: null,
        ]);
    }

    public function rules(): array
    {
        $user = $this->user();
        $canToggleApproval = $user && (
            $user->isAdmin()
            || $user->hasPrivilege('department.head')
            || $user->hasPrivilege('section.head')
        );

        return [
            'name'             => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'description'      => ['nullable', 'string', 'max:500'],
            'requires_approval' => $canToggleApproval ? ['nullable', 'boolean'] : [],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Division name is required.',
            'name.regex'    => 'Name contains invalid characters.',
        ];
    }
}

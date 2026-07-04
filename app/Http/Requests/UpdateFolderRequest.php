<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $division = $this->route('division');
        $section  = $this->route('section');
        $context  = $division ?? $section;

        return $context && $user->canUploadTo($context);
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name'        => strip_tags(trim($this->name ?? '')),
            'description' => strip_tags(trim($this->description ?? '')) ?: null,
            'visibility'  => strtolower(trim($this->visibility ?? 'public')),
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
            'name'              => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'description'       => ['nullable', 'string', 'max:500'],
            'visibility'        => ['nullable', 'string', 'in:public,authenticated'],
            'requires_approval' => $canToggleApproval ? ['nullable', 'boolean'] : [],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Folder name is required.',
            'name.regex'    => 'Name contains invalid characters.',
        ];
    }
}

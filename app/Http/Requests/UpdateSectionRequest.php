<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = trim(strip_tags($this->name ?? ''));
        $slug = $this->slug ? Str::slug(strip_tags($this->slug)) : Str::slug($name);
        $wing = trim(strip_tags($this->wing ?? '')) ?: null;

        $this->merge(compact('name', 'slug', 'wing'));
    }

    public function rules(): array
    {
        $departmentId = $this->route('department')?->id;
        $sectionId    = $this->route('section')?->id;

        return [
            'name' => ['required', 'string', 'max:120', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9\-_]+$/',
                       Rule::unique('sections')->where(fn ($q) => $q
                           ->where('department_id', $departmentId)
                           ->where('wing', $this->wing ?: null))
                       ->ignore($sectionId)],
            'wing' => ['nullable', 'string', 'in:headquarter,joint_secretary_wing,deputy_secretary_wing,field_office'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'A section with this slug already exists in this department / wing.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        return $user->isAdmin() || $user->hasPrivilege('organization.head');
    }

    protected function prepareForValidation(): void
    {
        $name  = trim(strip_tags($this->name ?? ''));
        $slug  = $this->slug ? Str::slug(strip_tags($this->slug)) : Str::slug($name);
        $level = trim($this->level ?? '');

        $this->merge(compact('name', 'slug', 'level'));
    }

    public function rules(): array
    {
        $deptId = $this->route('department')->id;

        return [
            'name'  => ['required', 'string', 'max:100', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'level' => ['required', 'in:secretariat_level,department_level'],
            'slug'  => ['required', 'string', 'max:80', 'regex:/^[a-z0-9\-_]+$/',
                        Rule::unique('departments')->where('level', $this->level)->ignore($deptId)],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'A department with this slug already exists at the selected level.',
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = trim(strip_tags($this->name ?? ''));

        $this->merge([
            'name'          => $name,
            'slug'          => Str::slug($name, '_'),
            'department_id' => $this->department_id === '' ? null : $this->department_id,
            'sort_order'    => $this->sort_order === null || $this->sort_order === '' ? 0 : $this->sort_order,
        ]);
    }

    public function rules(): array
    {
        return [
            'name'                => ['required', 'string', 'max:100'],
            'slug'                => ['required', 'string', 'max:120',
                                       Rule::unique('designations')->where('department_id', $this->department_id)],
            'department_id'       => ['nullable', 'integer', 'exists:departments,id'],
            'default_scope'       => ['required', 'in:global,department,section,division,none'],
            'default_privileges'  => ['nullable', 'array'],
            'default_privileges.*' => ['string', 'in:' . implode(',', User::PRIVILEGES)],
            'sort_order'          => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.unique' => 'A designation with this name already exists for the selected department.',
        ];
    }
}

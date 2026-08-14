<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $name = trim(strip_tags($this->name ?? ''));

        $this->merge([
            'name' => $name,
            'slug' => Str::slug($name, '_'),
        ]);
    }

    public function rules(): array
    {
        // route('designation') resolves via real route-model binding; the designation-manager
        // Livewire component isn't dispatched through that route, so it merges 'id' directly.
        $designationId = $this->route('designation')?->id ?? $this->input('id');

        return [
            'name'                => ['required', 'string', 'max:100'],
            'slug'                => ['required', 'string', 'max:120',
                                       Rule::unique('designations')->where('department_id', $this->department_id)->ignore($designationId)],
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

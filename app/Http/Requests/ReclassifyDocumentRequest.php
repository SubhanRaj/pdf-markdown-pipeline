<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReclassifyDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && ($user->isAdmin() || $user->hasPrivilege('documents.approve'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->note) {
            $this->merge(['note' => trim(strip_tags($this->note))]);
        }

        // Coerce empty strings to null for optional FK fields
        $this->merge([
            'new_section_id'  => $this->new_section_id  ?: null,
            'new_division_id' => $this->new_division_id ?: null,
            'new_rule_set_id' => $this->new_rule_set_id ?: null,
        ]);
    }

    public function rules(): array
    {
        return [
            'new_section_id'  => ['nullable', 'integer', 'exists:sections,id', 'required_without:new_rule_set_id'],
            'new_division_id' => ['nullable', 'integer', 'exists:divisions,id'],
            'new_rule_set_id' => ['nullable', 'integer', 'exists:rule_sets,id', 'required_without:new_section_id'],
            'approve'         => ['nullable', 'boolean'],
            'note'            => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'new_section_id.required_without'  => 'Either a section or a rule set must be selected.',
            'new_rule_set_id.required_without' => 'Either a section or a rule set must be selected.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\RuleSet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRuleSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        if ($this->route('kind', 'rules') === 'policy') {
            $department = $this->route('department');

            return $department instanceof Department && $user->canManagePolicyForDepartment($department);
        }

        return $user->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $isPolicy = $this->route('kind', 'rules') === 'policy';

        $merge = [
            'name'        => strip_tags(trim($this->name ?? '')),
            'description' => strip_tags(trim($this->description ?? '')) ?: null,
            'kind'        => $this->route('kind', 'rules'),
        ];

        if ($isPolicy) {
            // Primary "Add UP Policy" flow never renders a state field — default it server-side.
            $merge['state']             = trim(strip_tags($this->input('state', ''))) ?: RuleSet::DEFAULT_STATE;
            $merge['policy_type']       = trim(strip_tags($this->input('policy_type', '')));
            $merge['state_other']       = strip_tags(trim($this->input('state_other', ''))) ?: null;
            $merge['policy_type_other'] = strip_tags(trim($this->input('policy_type_other', ''))) ?: null;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $isPolicy = $this->route('kind', 'rules') === 'policy';

        $rules = [
            'name'        => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'description' => ['nullable', 'string', 'max:500'],
        ];

        if (! $isPolicy) {
            return $rules;
        }

        $unicodeText = 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u';

        return array_merge($rules, [
            'state'             => ['required', 'string', Rule::in([...RuleSet::STATES, 'other'])],
            'state_other'       => ['required_if:state,other', 'nullable', 'string', 'max:100', $unicodeText],
            'policy_type'       => ['required', 'string', Rule::in([...array_keys(RuleSet::POLICY_TYPES), 'other'])],
            'policy_type_other' => ['required_if:policy_type,other', 'nullable', 'string', 'max:100', $unicodeText],
            'effective_start_date' => ['nullable', 'date'],
            'effective_end_date'   => ['nullable', 'date', 'after_or_equal:effective_start_date'],
        ]);
    }

    public function messages(): array
    {
        return [
            'name.required'                => 'Rule set name is required.',
            'name.regex'                   => 'Name contains invalid characters.',
            'state.required'                => 'State is required.',
            'state_other.required_if'       => 'Please specify the state.',
            'policy_type.required'          => 'Policy type is required.',
            'policy_type_other.required_if' => 'Please specify the policy type.',
            'effective_end_date.after_or_equal' => 'End date must be on or after the start date.',
        ];
    }

    /**
     * Replaces the literal "other" sentinel with the sanitized free-text value before it's
     * persisted — the state/policy_type columns must never store the string "other" itself.
     * Only overrides the "give me everything" call shape (no $key) used by RuleSetController;
     * single-key lookups fall back to the untouched parent behavior.
     */
    public function validated($key = null, $default = null)
    {
        $data = parent::validated($key, $default);

        if ($key !== null) {
            return $data;
        }

        if (($data['state'] ?? null) === 'other') {
            $data['state'] = $data['state_other'];
        }
        if (($data['policy_type'] ?? null) === 'other') {
            $data['policy_type'] = $data['policy_type_other'];
        }
        unset($data['state_other'], $data['policy_type_other']);

        return $data;
    }
}

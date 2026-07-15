<?php

namespace App\Http\Requests;

use App\Models\RuleSet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateRuleSetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user) {
            return false;
        }

        $ruleSet = $this->route('rule_set');

        if ($ruleSet instanceof RuleSet && $ruleSet->kind === 'policy') {
            return $user->canManagePolicy($ruleSet);
        }

        return $user->isAdmin();
    }

    protected function prepareForValidation(): void
    {
        $isPolicy = $this->route('rule_set') instanceof RuleSet && $this->route('rule_set')->kind === 'policy';

        $merge = [
            'name'        => strip_tags(trim($this->name ?? '')),
            'description' => strip_tags(trim($this->description ?? '')) ?: null,
        ];

        if ($isPolicy) {
            $merge['state']             = trim(strip_tags($this->input('state', '')));
            $merge['policy_type']       = trim(strip_tags($this->input('policy_type', '')));
            $merge['state_other']       = strip_tags(trim($this->input('state_other', ''))) ?: null;
            // Same title-casing as StoreRuleSetRequest — keeps re-edits consistent regardless
            // of how the "other" value is retyped.
            $policyTypeOther = strip_tags(trim($this->input('policy_type_other', '')));
            $merge['policy_type_other'] = $policyTypeOther !== '' ? Str::title($policyTypeOther) : null;
        }

        $this->merge($merge);
    }

    public function rules(): array
    {
        $isPolicy = $this->route('rule_set') instanceof RuleSet && $this->route('rule_set')->kind === 'policy';

        $rules = [
            'name'              => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]+$/u'],
            'description'       => ['nullable', 'string', 'max:500'],
            'requires_approval' => ['nullable', 'boolean'],
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
            'name.required' => 'Rule set name is required.',
            'name.regex'    => 'Name contains invalid characters.',
            'state.required'                    => 'State is required.',
            'state_other.required_if'           => 'Please specify the state.',
            'policy_type.required'              => 'Policy type is required.',
            'policy_type_other.required_if'     => 'Please specify the policy type.',
            'effective_end_date.after_or_equal' => 'End date must be on or after the start date.',
        ];
    }

    /**
     * Same "other" sentinel resolution as StoreRuleSetRequest, and — critical per SECURITY.md
     * H-03 — policy_status/previous_policy_id are never in rules() above, so they can never
     * appear here no matter what a raw PATCH sends. Only the supersession logic in
     * RuleSetController::store() may set those two fields.
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

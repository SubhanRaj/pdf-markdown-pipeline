<?php $isPolicy = $kind === 'policy'; ?>
<x-layout
    title="{{ $isPolicy ? 'Add Policy' : 'Add Rule Set' }}"
    page-title="{{ $isPolicy ? 'Add Policy' : 'Add Rule Set' }}"
    page-subtitle="{{ $isPolicy ? 'Create a new policy under' : 'Create a new rule set under' }} {{ $department->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $isPolicy ? 'Add Policy' : 'Add Rule Set', 'url' => null],
]" />

<form id="ruleSetForm" method="POST"
      action="{{ route("departments.{$kind}.store", [$department->levelAlias(), $department]) }}"
      novalidate class="{{ $isPolicy ? 'max-w-4xl' : 'max-w-2xl' }}">
    @csrf

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti {{ $isPolicy ? 'ti-file-certificate' : 'ti-book' }} text-slate-400 dark:text-slate-500"></i>
                {{ $isPolicy ? 'Policy Details' : 'Rule Set Details' }}
            </h3>

            {{-- Department context --}}
            <div class="mb-4 px-3 py-2.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <i class="ti ti-building text-slate-400 dark:text-slate-500"></i>
                <span>{{ $department->name }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs font-mono text-slate-400 dark:text-slate-500">{{ $department->slug }}</span>
            </div>

            @if($isPolicy)
            {{-- State toggle: primary flow assumes Uttar Pradesh, secondary reveals a state picker --}}
            <div class="mb-4">
                <div class="inline-flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden text-sm font-medium divide-x divide-slate-200 dark:divide-slate-700">
                    <button type="button" id="btn-up-policy"
                            class="policy-toggle-btn px-3 py-2 transition-colors">
                        <i class="ti ti-map-pin text-sm"></i> Add UP Policy
                    </button>
                    <button type="button" id="btn-other-state-policy"
                            class="policy-toggle-btn px-3 py-2 transition-colors">
                        <i class="ti ti-world text-sm"></i> Add Other State's Policy
                    </button>
                </div>

                <div id="state-field-wrap" class="hidden mt-3">
                    <label for="state" class="field-label">State <span class="text-red-500">*</span></label>
                    <select id="state" name="state" class="field-input @error('state') field-error @enderror">
                        @foreach(\App\Models\RuleSet::STATES as $stateOption)
                        <option value="{{ $stateOption }}" @selected(old('state') === $stateOption)>{{ $stateOption }}</option>
                        @endforeach
                        <option value="other" @selected(old('state') === 'other')>Other</option>
                    </select>
                    <p class="field-err-msg hidden" id="state-err"></p>
                    @error('state') <p class="field-err-msg">{{ $message }}</p> @enderror

                    <div id="state-other-wrap" class="hidden mt-2">
                        <input id="state_other" name="state_other" type="text" value="{{ old('state_other') }}"
                               placeholder="Enter state / union territory name"
                               class="field-input @error('state_other') field-error @enderror">
                        @error('state_other') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>
                {{-- UP mode: state travels as a hidden field, never shown --}}
                <input type="hidden" id="state-hidden" name="state" value="{{ \App\Models\RuleSet::DEFAULT_STATE }}">
            </div>

            <div class="space-y-4">
                <div>
                    <label for="policy_type" class="field-label">Policy Type <span class="text-red-500">*</span></label>
                    <select id="policy_type" name="policy_type" class="field-input @error('policy_type') field-error @enderror">
                        @if($defaultPolicyType)
                        {{-- A department only ever uploads its own named policy (Excise dept → Excise
                             Policy, Cane dept → Cane Policy, ...) — the dropdown is locked to that one
                             match instead of listing every department's policy types. Anything else
                             (e.g. Import/Export Policy) goes through "Other" as free text. --}}
                        <option value="{{ $defaultPolicyType }}" @selected(old('policy_type', $defaultPolicyType) === $defaultPolicyType)>
                            {{ \App\Models\RuleSet::POLICY_TYPES[$defaultPolicyType] }}
                        </option>
                        <option value="other" @selected(old('policy_type') === 'other')>Other</option>
                        @else
                        <option value="">Select policy type…</option>
                        @foreach(\App\Models\RuleSet::POLICY_TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(old('policy_type') === $key)>{{ $label }}</option>
                        @endforeach
                        @endif
                    </select>
                    <p class="field-err-msg hidden" id="policy_type-err"></p>
                    @error('policy_type') <p class="field-err-msg">{{ $message }}</p> @enderror
                    @if($defaultPolicyType)
                    <p class="field-hint">Locked to {{ $department->name }}'s policy type. Pick "Other" for a different kind (e.g. Import/Export Policy).</p>
                    @endif

                    <div id="policy-type-other-wrap" class="hidden mt-2">
                        <input id="policy_type_other" name="policy_type_other" type="text" value="{{ old('policy_type_other') }}"
                               placeholder="Enter policy type"
                               class="field-input @error('policy_type_other') field-error @enderror">
                        @error('policy_type_other') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="field-hint">
                    This is created once per state + policy type. Once saved, add each year's
                    policy period (e.g. "2024-25", "2025-26") underneath it.
                </p>
            </div>
            <div class="border-t border-slate-100 dark:border-slate-700 my-5"></div>
            @endif

            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">{{ $isPolicy ? 'Policy Name' : 'Rule Set Name' }} <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name') }}"
                        placeholder="{{ $isPolicy ? 'e.g. UP Excise Policy' : 'e.g. U.P. Excise Act 1910' }}"
                        class="field-input @error('name') field-error @enderror"
                        required autofocus>
                    <p class="field-hint">{{ $isPolicy ? 'Name of this policy (state + policy type) — no year, that comes from each period.' : 'Name of the Act, Rule, or Regulation.' }} Letters, numbers, spaces, hyphens, dots, brackets allowed.</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="description" class="field-label">Description</label>
                    <textarea id="description" name="description" rows="3"
                        placeholder="Brief description (optional)"
                        class="field-input resize-none @error('description') field-error @enderror">{{ old('description') }}</textarea>
                    <p class="field-hint">Optional. Maximum 500 characters.</p>
                    <p class="field-err-msg hidden" id="description-err"></p>
                    @error('description') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('departments.show', [$department->levelAlias(), $department]) }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back
            </a>
            <button type="submit"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-plus"></i> {{ $isPolicy ? 'Create Policy' : 'Create Rule Set' }}
            </button>
        </div>

    </div>
</form>

@if($isPolicy)
@push('scripts')
<script>
(function () {
    try {
        // UP vs other-state toggle
        const btnUp    = document.getElementById('btn-up-policy');
        const btnOther = document.getElementById('btn-other-state-policy');
        const stateWrap = document.getElementById('state-field-wrap');
        const stateHidden = document.getElementById('state-hidden');
        const stateSelect = document.getElementById('state');

        // Swap the *entire* class set per state (rather than toggling individual
        // utility classes) — mixing a toggled `bg-indigo-600` with a static
        // `dark:bg-slate-800` left over from the inactive markup caused the
        // dark-mode `.dark .dark\:bg-slate-800` selector (two classes) to beat
        // the plain `.bg-indigo-600` (one class) on specificity, so the "Other
        // State" button never actually highlighted in dark mode, and the "UP"
        // button fell back to a plain white background instead of a dark one
        // once deselected.
        const ACTIVE_CLASSES   = ['bg-indigo-600', 'text-white'];
        const INACTIVE_CLASSES = [
            'bg-white', 'dark:bg-slate-900/60',
            'text-slate-600', 'dark:text-slate-300',
            'hover:bg-slate-50', 'dark:hover:bg-slate-700',
            'hover:text-indigo-600', 'dark:hover:text-indigo-400',
        ];

        function applyToggleState(btn, active) {
            btn.classList.remove(...ACTIVE_CLASSES, ...INACTIVE_CLASSES);
            btn.classList.add(...(active ? ACTIVE_CLASSES : INACTIVE_CLASSES));
        }

        function setMode(otherState) {
            stateWrap.classList.toggle('hidden', !otherState);
            stateHidden.disabled = otherState;
            stateSelect.disabled = !otherState;
            applyToggleState(btnUp, !otherState);
            applyToggleState(btnOther, otherState);
        }
        // Repopulated after a validation error where "other state" mode was in use.
        const oldState = stateSelect.value;
        setMode(!!(oldState && oldState !== '{{ \App\Models\RuleSet::DEFAULT_STATE }}'));
        stateSelect.disabled = !(oldState && oldState !== '{{ \App\Models\RuleSet::DEFAULT_STATE }}');
        stateHidden.disabled = !stateSelect.disabled;

        btnUp.addEventListener('click', () => setMode(false));
        btnOther.addEventListener('click', () => setMode(true));

        stateSelect.addEventListener('change', function () {
            document.getElementById('state-other-wrap').classList.toggle('hidden', this.value !== 'other');
        });
        if (stateSelect.value === 'other') {
            document.getElementById('state-other-wrap').classList.remove('hidden');
        }

        document.getElementById('policy_type').addEventListener('change', function () {
            document.getElementById('policy-type-other-wrap').classList.toggle('hidden', this.value !== 'other');
        });
        if (document.getElementById('policy_type').value === 'other') {
            document.getElementById('policy-type-other-wrap').classList.remove('hidden');
        }

        document.getElementById('ruleSetForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const form = this;
            Swal.fire({
                title: 'Create this policy?',
                html: 'You\'ll add each year\'s policy period (2024-25, 2025-26, ...) underneath it afterwards.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Create',
                confirmButtonColor: '#4f46e5',
                background: document.documentElement.classList.contains('dark') ? '#1e293b' : '#fff',
                color: document.documentElement.classList.contains('dark') ? '#e2e8f0' : '#0f172a',
            }).then((result) => {
                if (result.isConfirmed) form.submit();
            });
        });
    } catch (err) { console.error('Policy form init failed', err); }
})();
</script>
@endpush
@endif

@push('scripts')
<script>
(function () {
    try {
        const NAME_PATTERN = /^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]{2,150}$/u;

        function validateName() {
            const el = document.getElementById('name');
            const err = document.getElementById('name-err');
            const val = el.value.trim();
            const msg = !val ? 'Name is required.'
                       : !NAME_PATTERN.test(val) ? 'Name contains invalid characters.'
                       : null;
            if (msg) {
                el.classList.remove('field-valid'); el.classList.add('field-error');
                err.textContent = msg; err.classList.remove('hidden');
                return false;
            }
            el.classList.remove('field-error'); el.classList.add('field-valid');
            err.textContent = ''; err.classList.add('hidden');
            return true;
        }

        function validateDescription() {
            const el = document.getElementById('description');
            const err = document.getElementById('description-err');
            const val = el.value.trim();
            if (val.length > 500) {
                el.classList.add('field-error');
                err.textContent = 'Description must not exceed 500 characters.';
                err.classList.remove('hidden');
                return false;
            }
            el.classList.remove('field-error');
            err.textContent = ''; err.classList.add('hidden');
            return true;
        }

        document.getElementById('name').addEventListener('blur', validateName);
        document.getElementById('name').addEventListener('input', function () {
            if (this.classList.contains('field-error')) validateName();
        });
        document.getElementById('description').addEventListener('input', validateDescription);

        @if(!$isPolicy)
        document.getElementById('ruleSetForm').addEventListener('submit', function (e) {
            const valid = [validateName(), validateDescription()].every(Boolean);
            if (!valid) {
                e.preventDefault();
                document.querySelector('.field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        @endif
    } catch (err) { console.error('Form init failed', err); }
})();
</script>
@endpush

</x-layout>

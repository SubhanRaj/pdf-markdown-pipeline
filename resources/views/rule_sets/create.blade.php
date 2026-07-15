<?php $isPolicy = $kind === 'policy'; ?>
<x-layout
    title="{{ $isPolicy ? 'Add Policy' : 'Add Rule Set' }}"
    page-title="{{ $isPolicy ? 'Add Policy' : 'Add Rule Set' }}"
    page-subtitle="{{ $isPolicy ? 'Create a new policy period under' : 'Create a new rule set under' }} {{ $department->name }}"
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
      novalidate class="max-w-2xl">
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
                <div class="inline-flex rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden text-sm font-medium">
                    <button type="button" id="btn-up-policy"
                            class="px-3 py-2 bg-indigo-600 text-white transition-colors">
                        <i class="ti ti-map-pin text-sm"></i> Add UP Policy
                    </button>
                    <button type="button" id="btn-other-state-policy"
                            class="px-3 py-2 bg-white dark:bg-slate-800 text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
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
                        <option value="">Select policy type…</option>
                        @foreach(\App\Models\RuleSet::POLICY_TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(old('policy_type', $defaultPolicyType) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="field-err-msg hidden" id="policy_type-err"></p>
                    @error('policy_type') <p class="field-err-msg">{{ $message }}</p> @enderror

                    <div id="policy-type-other-wrap" class="hidden mt-2">
                        <input id="policy_type_other" name="policy_type_other" type="text" value="{{ old('policy_type_other') }}"
                               placeholder="Enter policy type"
                               class="field-input @error('policy_type_other') field-error @enderror">
                        @error('policy_type_other') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="effective_start_date_display" class="field-label">Effective From</label>
                        <input id="effective_start_date_display" type="text" inputmode="numeric" placeholder="DD-MM-YYYY"
                               class="field-input" autocomplete="off">
                        <input type="hidden" id="effective_start_date" name="effective_start_date" value="{{ old('effective_start_date') }}">
                        @error('effective_start_date') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="effective_end_date_display" class="field-label">Effective Till</label>
                        <input id="effective_end_date_display" type="text" inputmode="numeric" placeholder="DD-MM-YYYY"
                               class="field-input" autocomplete="off">
                        <input type="hidden" id="effective_end_date" name="effective_end_date" value="{{ old('effective_end_date') }}">
                        @error('effective_end_date') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="field-hint">
                    Descriptive only — a policy stays the one cited until a new period for the same
                    state + policy type is added, regardless of these dates.
                </p>
            </div>
            <div class="border-t border-slate-100 dark:border-slate-700 my-5"></div>
            @endif

            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">{{ $isPolicy ? 'Policy Name' : 'Rule Set Name' }} <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name') }}"
                        placeholder="{{ $isPolicy ? 'e.g. UP Excise Policy 2026-27' : 'e.g. U.P. Excise Act 1910' }}"
                        class="field-input @error('name') field-error @enderror"
                        required autofocus>
                    <p class="field-hint">{{ $isPolicy ? 'Name of this policy period.' : 'Name of the Act, Rule, or Regulation.' }} Letters, numbers, spaces, hyphens, dots, brackets allowed.</p>
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
<script src="https://cdn.jsdelivr.net/npm/cleave.js@1.6.0/dist/cleave.min.js"></script>
<script>
(function () {
    try {
        function bindDateMask(displayId, hiddenId) {
            const display = document.getElementById(displayId);
            const hidden  = document.getElementById(hiddenId);
            new Cleave(display, {
                date: true,
                datePattern: ['d', 'm', 'Y'],
                delimiter: '-',
                onValueChanged: function (e) {
                    const parts = e.target.value.split('-'); // DD-MM-YYYY
                    hidden.value = (parts.length === 3 && parts[2].length === 4)
                        ? `${parts[2]}-${parts[1]}-${parts[0]}`
                        : '';
                },
            });
            // Repopulate the masked display from an old()-repopulated hidden ISO value (validation bounce-back).
            if (hidden.value) {
                const [y, m, d] = hidden.value.split('-');
                if (y && m && d) display.value = `${d}-${m}-${y}`;
            }
        }
        bindDateMask('effective_start_date_display', 'effective_start_date');
        bindDateMask('effective_end_date_display', 'effective_end_date');

        // UP vs other-state toggle
        const btnUp    = document.getElementById('btn-up-policy');
        const btnOther = document.getElementById('btn-other-state-policy');
        const stateWrap = document.getElementById('state-field-wrap');
        const stateHidden = document.getElementById('state-hidden');
        const stateSelect = document.getElementById('state');

        function setMode(otherState) {
            stateWrap.classList.toggle('hidden', !otherState);
            stateHidden.disabled = otherState;
            stateSelect.disabled = !otherState;
            btnUp.classList.toggle('bg-indigo-600', !otherState);
            btnUp.classList.toggle('text-white', !otherState);
            btnUp.classList.toggle('bg-white', otherState);
            btnUp.classList.toggle('text-slate-500', otherState);
            btnOther.classList.toggle('bg-indigo-600', otherState);
            btnOther.classList.toggle('text-white', otherState);
            btnOther.classList.toggle('bg-white', !otherState);
            btnOther.classList.toggle('text-slate-500', !otherState);
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
                html: 'If a current policy already exists for this department, state, and policy type, it will automatically be marked <strong>historical</strong> (not deleted) and this new one becomes the current citation.',
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

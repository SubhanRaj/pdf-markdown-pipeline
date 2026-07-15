<?php
$isPolicy   = $ruleSet->kind === 'policy';
$canManage  = auth()->user()->isAdmin() || ($isPolicy && auth()->user()->canManagePolicy($ruleSet));
?>
<x-layout
    title="{{ $isPolicy ? 'Edit Policy' : 'Edit Rule Set' }}"
    page-title="{{ $isPolicy ? 'Edit Policy' : 'Edit Rule Set' }}"
    page-subtitle="{{ $ruleSet->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $ruleSet->name,            'url' => route(\"departments.{$ruleSet->kind}.show\", [$department->levelAlias(), $department, $ruleSet])],
    ['name' => 'Edit',                    'url' => null],
]" />

<form id="ruleSetForm" method="POST"
      action="{{ route(\"departments.{$ruleSet->kind}.update\", [$department->levelAlias(), $department, $ruleSet]) }}"
      novalidate class="max-w-2xl">
    @csrf
    @method('PATCH')

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
            <div class="mb-4 px-3 py-2.5 rounded-lg flex items-center gap-2 text-sm
                {{ $ruleSet->policy_status === 'current' ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300' : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' }}">
                <i class="ti {{ $ruleSet->policy_status === 'current' ? 'ti-circle-check' : 'ti-clock-pause' }}"></i>
                {{ $ruleSet->policy_status === 'current' ? 'Current policy' : 'Superseded — historical reference only' }}
            </div>

            <div class="space-y-4 mb-4">
                <div>
                    <label for="state" class="field-label">State <span class="text-red-500">*</span></label>
                    <select id="state" name="state" class="field-input @error('state') field-error @enderror">
                        @foreach(\App\Models\RuleSet::STATES as $stateOption)
                        <option value="{{ $stateOption }}" @selected(old('state', $ruleSet->state) === $stateOption)>{{ $stateOption }}</option>
                        @endforeach
                        <option value="other" @selected(! in_array(old('state', $ruleSet->state), \App\Models\RuleSet::STATES, true))>Other</option>
                    </select>
                    @error('state') <p class="field-err-msg">{{ $message }}</p> @enderror

                    <div id="state-other-wrap" class="hidden mt-2">
                        <input id="state_other" name="state_other" type="text"
                               value="{{ old('state_other', in_array($ruleSet->state, \App\Models\RuleSet::STATES, true) ? '' : $ruleSet->state) }}"
                               placeholder="Enter state / union territory name"
                               class="field-input @error('state_other') field-error @enderror">
                        @error('state_other') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label for="policy_type" class="field-label">Policy Type <span class="text-red-500">*</span></label>
                    <select id="policy_type" name="policy_type" class="field-input @error('policy_type') field-error @enderror">
                        <option value="">Select policy type…</option>
                        @foreach(\App\Models\RuleSet::POLICY_TYPES as $key => $label)
                        <option value="{{ $key }}" @selected(old('policy_type', $ruleSet->policy_type) === $key)>{{ $label }}</option>
                        @endforeach
                        <option value="other" @selected(! array_key_exists(old('policy_type', $ruleSet->policy_type), \App\Models\RuleSet::POLICY_TYPES))>Other</option>
                    </select>
                    @error('policy_type') <p class="field-err-msg">{{ $message }}</p> @enderror

                    <div id="policy-type-other-wrap" class="hidden mt-2">
                        <input id="policy_type_other" name="policy_type_other" type="text"
                               value="{{ old('policy_type_other', array_key_exists($ruleSet->policy_type, \App\Models\RuleSet::POLICY_TYPES) ? '' : $ruleSet->policy_type) }}"
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
                        <input type="hidden" id="effective_start_date" name="effective_start_date"
                               value="{{ old('effective_start_date', $ruleSet->effective_start_date?->format('Y-m-d')) }}">
                        @error('effective_start_date') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="effective_end_date_display" class="field-label">Effective Till</label>
                        <input id="effective_end_date_display" type="text" inputmode="numeric" placeholder="DD-MM-YYYY"
                               class="field-input" autocomplete="off">
                        <input type="hidden" id="effective_end_date" name="effective_end_date"
                               value="{{ old('effective_end_date', $ruleSet->effective_end_date?->format('Y-m-d')) }}">
                        @error('effective_end_date') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="border-t border-slate-100 dark:border-slate-700 my-5"></div>
            @endif

            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">{{ $isPolicy ? 'Policy Name' : 'Rule Set Name' }} <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name', $ruleSet->name) }}"
                        placeholder="e.g. U.P. Excise Act 1910"
                        class="field-input @error('name') field-error @enderror"
                        required autofocus>
                    <p class="field-hint">Letters, numbers, spaces, hyphens, dots, brackets allowed.</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="description" class="field-label">Description</label>
                    <textarea id="description" name="description" rows="3"
                        placeholder="Brief description (optional)"
                        class="field-input resize-none @error('description') field-error @enderror">{{ old('description', $ruleSet->description) }}</textarea>
                    <p class="field-hint">Optional. Maximum 500 characters.</p>
                    <p class="field-err-msg hidden" id="description-err"></p>
                    @error('description') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                {{-- Read-only slug info --}}
                <div>
                    <label class="field-label">Slug</label>
                    <p class="font-mono text-sm text-slate-500 dark:text-slate-400 px-3 py-2 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg">{{ $ruleSet->slug }}</p>
                    <p class="field-hint">Slug is set at creation and cannot be changed.</p>
                </div>

                @if($canManage)
                <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="requires_approval" value="0">
                        <input type="checkbox" name="requires_approval" value="1"
                            class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-amber-600 focus:ring-amber-500"
                            {{ old('requires_approval', $ruleSet->requires_approval) ? 'checked' : '' }}>
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Require approval for all uploads to this {{ $isPolicy ? 'policy' : 'rule set' }}</span>
                    </label>
                    <p class="mt-1 ml-7 text-xs text-slate-500 dark:text-slate-400">When enabled, any document uploaded here is held as "Pending Approval" until an approver reviews it.</p>
                </div>
                @endif

            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route(\"departments.{$ruleSet->kind}.show\", [$department->levelAlias(), $department, $ruleSet]) }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Cancel
            </a>
            <button type="submit"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-check"></i> Save Changes
            </button>
        </div>

    </div>
</form>

{{-- ── Danger zone ──────────────────────────────────────────────────────────── --}}
@if($canManage)
<div class="max-w-2xl mt-6 rounded-xl border border-red-200 dark:border-red-800/50 bg-white dark:bg-slate-800">
    <div class="px-6 py-4 border-b border-red-100 dark:border-red-800/40 flex items-center gap-2">
        <i class="ti ti-alert-triangle text-red-500 dark:text-red-400 text-base"></i>
        <span class="text-sm font-semibold text-red-700 dark:text-red-400">Danger Zone</span>
    </div>
    <div class="px-6 py-5 flex items-start justify-between gap-6">
        <div>
            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Delete this {{ $isPolicy ? 'policy' : 'rule set' }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                Permanently removes this container and soft-deletes all {{ $ruleSet->documents()->count() }} associated document(s) to trash.
                This action cannot be easily undone.
            </p>
        </div>
        <button type="button" id="delete-ruleset-btn"
                class="flex-shrink-0 inline-flex items-center gap-1.5 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-trash text-base"></i> Delete {{ $isPolicy ? 'Policy' : 'Rule Set' }}
        </button>
    </div>
</div>

<form id="delete-ruleset-form" method="POST"
      action="{{ route(\"departments.{$ruleSet->kind}.destroy\", [$department->levelAlias(), $department, $ruleSet]) }}"
      style="display:none">
    @csrf @method('DELETE')
</form>

@push('scripts')
<script>
try {
    document.getElementById('delete-ruleset-btn').addEventListener('click', function () {
        const isDark = document.documentElement.classList.contains('dark');
        const docCount = {{ $ruleSet->documents()->count() }};
        Swal.fire({
            title: 'Delete {{ $isPolicy ? "Policy" : "Rule Set" }}?',
            html: '<p class="text-sm text-gray-500 mb-2">You are about to delete <strong>{{ e($ruleSet->name) }}</strong>.</p>'
                + (docCount > 0
                    ? '<p class="text-sm text-red-500">This will also move <strong>' + docCount + ' document(s)</strong> to trash.</p>'
                    : '<p class="text-sm text-gray-400">No documents are associated with this container.</p>'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete it',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc2626',
            background: isDark ? '#1e293b' : '#ffffff',
            color: isDark ? '#f1f5f9' : '#0f172a',
        }).then(function (result) {
            if (result.isConfirmed) {
                document.getElementById('delete-ruleset-form').submit();
            }
        });
    });
} catch (e) { console.error('Delete rule set init failed:', e); }
</script>
@endpush
@endif

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
                    const parts = e.target.value.split('-');
                    hidden.value = (parts.length === 3 && parts[2].length === 4)
                        ? `${parts[2]}-${parts[1]}-${parts[0]}`
                        : '';
                },
            });
            if (hidden.value) {
                const [y, m, d] = hidden.value.split('-');
                if (y && m && d) display.value = `${d}-${m}-${y}`;
            }
        }
        bindDateMask('effective_start_date_display', 'effective_start_date');
        bindDateMask('effective_end_date_display', 'effective_end_date');

        const stateSelect = document.getElementById('state');
        stateSelect.addEventListener('change', function () {
            document.getElementById('state-other-wrap').classList.toggle('hidden', this.value !== 'other');
        });
        if (stateSelect.value === 'other') document.getElementById('state-other-wrap').classList.remove('hidden');

        const typeSelect = document.getElementById('policy_type');
        typeSelect.addEventListener('change', function () {
            document.getElementById('policy-type-other-wrap').classList.toggle('hidden', this.value !== 'other');
        });
        if (typeSelect.value === 'other') document.getElementById('policy-type-other-wrap').classList.remove('hidden');
    } catch (err) { console.error('Policy edit form init failed', err); }
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
            if (el.value.trim().length > 500) {
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

        document.getElementById('ruleSetForm').addEventListener('submit', function (e) {
            const valid = [validateName(), validateDescription()].every(Boolean);
            if (!valid) {
                e.preventDefault();
                document.querySelector('.field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    } catch (err) { console.error('Form init failed', err); }
})();
</script>
@endpush

</x-layout>

<x-layout
    title="Add Policy Period"
    page-title="Add Policy Period"
    page-subtitle="{{ $policy->name }} · {{ $policy->state }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $policy->name,             'url' => route('departments.policy.show', [$department->levelAlias(), $department, $policy])],
    ['name' => 'Add Period',              'url' => null],
]" />

<form id="periodForm" method="POST"
      action="{{ route('departments.policy.periods.store', [$department->levelAlias(), $department, $policy]) }}"
      novalidate class="max-w-2xl">
    @csrf

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-calendar-time text-slate-400 dark:text-slate-500"></i>
                Period Details
            </h3>

            <div class="mb-4 px-3 py-2.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <i class="ti ti-file-certificate text-slate-400 dark:text-slate-500"></i>
                <span>{{ $policy->name }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs font-mono text-slate-400 dark:text-slate-500">{{ $policy->state }} · {{ \App\Models\RuleSet::POLICY_TYPES[$policy->policy_type] ?? $policy->policy_type }}</span>
            </div>

            <div class="space-y-4">
                <div>
                    <label for="name" class="field-label">Period Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name') }}"
                        placeholder="e.g. 2025-26"
                        class="field-input @error('name') field-error @enderror"
                        required autofocus>
                    <p class="field-hint">Letters, numbers, spaces, hyphens, dots, brackets allowed.</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
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
            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('departments.policy.show', [$department->levelAlias(), $department, $policy]) }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back
            </a>
            <button type="submit"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-plus"></i> Create Period
            </button>
        </div>

    </div>
</form>

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

        function validateName() {
            const el = document.getElementById('name');
            const err = document.getElementById('name-err');
            const val = el.value.trim();
            const msg = !val ? 'Name is required.'
                       : !/^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]{2,150}$/u.test(val) ? 'Name contains invalid characters.'
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
        document.getElementById('name').addEventListener('blur', validateName);

        document.getElementById('periodForm').addEventListener('submit', function (e) {
            if (!validateName()) {
                e.preventDefault();
                document.querySelector('.field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    } catch (err) { console.error('Period form init failed', err); }
})();
</script>
@endpush

</x-layout>

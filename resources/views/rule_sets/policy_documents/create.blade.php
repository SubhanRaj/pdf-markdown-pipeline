<x-layout
    title="Add Policy"
    page-title="Add Policy"
    :page-subtitle="$policy->name . ' · ' . $policy->state"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ...\App\Models\RuleSet::policyBreadcrumb($department, $policy->state),
    ['name' => $policy->name,             'url' => route('departments.policy.show', [$department->levelAlias(), $department, $policy])],
    ['name' => 'Add Policy',              'url' => null],
]" />

<form id="policyDocForm" method="POST"
      action="{{ route('departments.policy.periods.store', [$department->levelAlias(), $department, $policy]) }}"
      novalidate enctype="multipart/form-data" class="max-w-2xl">
    @csrf

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-calendar-time text-slate-400 dark:text-slate-500"></i>
                Policy Details
            </h3>

            <div class="mb-4 px-3 py-2.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <i class="ti ti-file-certificate text-slate-400 dark:text-slate-500"></i>
                <span>{{ $policy->name }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs font-mono text-slate-400 dark:text-slate-500">{{ $policy->state }} · {{ \App\Models\RuleSet::POLICY_TYPES[$policy->policy_type] ?? $policy->policy_type }}</span>
            </div>

            <div class="space-y-4">
                <div>
                    <label for="name" class="field-label">Policy Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name') }}"
                        placeholder="e.g. 2025-26"
                        class="field-input @error('name') field-error @enderror"
                        required autofocus>
                    <p class="field-hint">Letters, numbers, spaces, hyphens, dots, brackets allowed. Use the actual policy year/cycle — this also works for backfilling old policies (e.g. "2021-22").</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="effective_start_date_display" class="field-label">Effective From</label>
                        <input id="effective_start_date_display" type="text" placeholder="DD-MM-YYYY" autocomplete="off"
                               class="field-input @error('effective_start_date') field-error @enderror">
                        <input type="hidden" id="effective_start_date" name="effective_start_date" value="{{ old('effective_start_date') }}">
                        @error('effective_start_date') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="effective_end_date_display" class="field-label">Effective Till</label>
                        <input id="effective_end_date_display" type="text" placeholder="DD-MM-YYYY" autocomplete="off"
                               class="field-input @error('effective_end_date') field-error @enderror">
                        <input type="hidden" id="effective_end_date" name="effective_end_date" value="{{ old('effective_end_date') }}">
                        @error('effective_end_date') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-file-upload text-slate-400 dark:text-slate-500"></i>
                Policy Document
            </h3>

            <div class="space-y-4">
                <div>
                    <label for="file" class="field-label">Original PDF</label>
                    <input id="file" name="file" type="file"
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv,.jpg,.jpeg,.png,.webp,.gif,.tiff,.tif,.bmp,.heic,.heif"
                        class="field-input @error('file') field-error @enderror">
                    <p class="field-hint">Optional — upload the policy document now, or add it later from the policy's page.</p>
                    @error('file') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Language</label>
                        <div class="flex items-center gap-4 mt-1.5">
                            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300 cursor-pointer">
                                <input type="radio" name="language" value="english" {{ old('language', 'english') === 'english' ? 'checked' : '' }}> English
                            </label>
                            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300 cursor-pointer">
                                <input type="radio" name="language" value="hindi" {{ old('language') === 'hindi' ? 'checked' : '' }}> Hindi
                            </label>
                            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300 cursor-pointer">
                                <input type="radio" name="language" value="both" {{ old('language') === 'both' ? 'checked' : '' }}> Both
                            </label>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Visibility</label>
                        <div class="flex items-center gap-4 mt-1.5">
                            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300 cursor-pointer">
                                <input type="radio" name="visibility" value="public" {{ old('visibility', 'public') === 'public' ? 'checked' : '' }}> Public
                            </label>
                            <label class="flex items-center gap-1.5 text-sm text-slate-600 dark:text-slate-300 cursor-pointer">
                                <input type="radio" name="visibility" value="authenticated" {{ old('visibility') === 'authenticated' ? 'checked' : '' }}> Signed-in only
                            </label>
                        </div>
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
                <i class="ti ti-plus"></i> Add Policy
            </button>
        </div>

    </div>
</form>

@push('scripts')
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/air-datepicker@3/air-datepicker.css">
<style>
    .dark .air-datepicker {
        --adp-background-color: #1e293b;
        --adp-background-color-hover: #334155;
        --adp-background-color-active: #475569;
        --adp-color: #e2e8f0;
        --adp-color-secondary: #94a3b8;
        --adp-accent-color: #6366f1;
        --adp-color-other-month: #475569;
        --adp-color-other-month-hover: #64748b;
        --adp-border-color: #334155;
        --adp-border-color-inner: #334155;
        --adp-day-name-color: #818cf8;
        --adp-cell-background-color-selected: #4f46e5;
        --adp-cell-background-color-selected-hover: #4338ca;
    }
</style>
<script src="https://cdn.jsdelivr.net/npm/air-datepicker@3/air-datepicker.js"></script>
<script>
(function () {
    try {
        // Air Datepicker's built-in default locale is Russian — always pass English explicitly.
        const EN_LOCALE = {
            days: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            daysShort: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            daysMin: ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'],
            months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            monthsShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            today: 'Today',
            clear: 'Clear',
            dateFormat: 'dd-MM-yyyy',
            timeFormat: 'hh:mm aa',
            firstDay: 0,
        };

        function bindDatePicker(displayId, hiddenId) {
            const display = document.getElementById(displayId);
            const hidden  = document.getElementById(hiddenId);
            const pad = n => String(n).padStart(2, '0');
            const toISO = d => d ? `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}` : '';

            let initialDate = null;
            if (hidden.value) {
                const [y, m, d] = hidden.value.split('-').map(Number);
                if (y && m && d) initialDate = new Date(y, m - 1, d);
            }

            new AirDatepicker(display, {
                locale: EN_LOCALE,
                dateFormat: 'dd-MM-yyyy',
                autoClose: true,
                selectedDates: initialDate ? [initialDate] : [],
                onSelect({ date }) {
                    hidden.value = toISO(Array.isArray(date) ? date[0] : date);
                },
            });
        }
        bindDatePicker('effective_start_date_display', 'effective_start_date');
        bindDatePicker('effective_end_date_display', 'effective_end_date');

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

        document.getElementById('policyDocForm').addEventListener('submit', function (e) {
            if (!validateName()) {
                e.preventDefault();
                document.querySelector('.field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    } catch (err) { console.error('Policy form init failed', err); }
})();
</script>
@endpush

</x-layout>

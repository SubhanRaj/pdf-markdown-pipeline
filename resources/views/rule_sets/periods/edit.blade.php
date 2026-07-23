<x-layout
    title="Edit Policy Period"
    page-title="Edit Policy Period"
    page-subtitle="{{ $period->name }} · {{ $policy->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $policy->name,             'url' => route('departments.policy.show', [$department->levelAlias(), $department, $policy])],
    ['name' => $period->name,             'url' => route('departments.policy.periods.show', [$department->levelAlias(), $department, $policy, $period])],
    ['name' => 'Edit',                    'url' => null],
]" />

<form id="periodForm" method="POST"
      action="{{ route('departments.policy.periods.update', [$department->levelAlias(), $department, $policy, $period]) }}"
      novalidate class="max-w-2xl">
    @csrf
    @method('PATCH')

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-calendar-time text-slate-400 dark:text-slate-500"></i>
                Period Details
            </h3>

            <div class="mb-4 px-3 py-2.5 rounded-lg flex items-center gap-2 text-sm
                {{ $period->policy_status === 'current' ? 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300' : 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300' }}">
                <i class="ti {{ $period->policy_status === 'current' ? 'ti-circle-check' : 'ti-clock-pause' }}"></i>
                {{ $period->policy_status === 'current' ? 'Current period' : 'Superseded — historical reference only' }}
            </div>

            <div class="space-y-4">
                <div>
                    <label for="name" class="field-label">Period Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name', $period->name) }}"
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
                        <input type="hidden" id="effective_start_date" name="effective_start_date"
                               value="{{ old('effective_start_date', $period->effective_start_date?->format('Y-m-d')) }}">
                        @error('effective_start_date') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="effective_end_date_display" class="field-label">Effective Till</label>
                        <input id="effective_end_date_display" type="text" inputmode="numeric" placeholder="DD-MM-YYYY"
                               class="field-input" autocomplete="off">
                        <input type="hidden" id="effective_end_date" name="effective_end_date"
                               value="{{ old('effective_end_date', $period->effective_end_date?->format('Y-m-d')) }}">
                        @error('effective_end_date') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="requires_approval" value="0">
                        <input type="checkbox" name="requires_approval" value="1"
                            class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-amber-600 focus:ring-amber-500"
                            {{ old('requires_approval', $period->requires_approval) ? 'checked' : '' }}>
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Require approval for all uploads to this period</span>
                    </label>
                </div>
            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('departments.policy.periods.show', [$department->levelAlias(), $department, $policy, $period]) }}"
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
<div class="max-w-2xl mt-6 rounded-xl border border-red-200 dark:border-red-800/50 bg-white dark:bg-slate-800">
    <div class="px-6 py-4 border-b border-red-100 dark:border-red-800/40 flex items-center gap-2">
        <i class="ti ti-alert-triangle text-red-500 dark:text-red-400 text-base"></i>
        <span class="text-sm font-semibold text-red-700 dark:text-red-400">Danger Zone</span>
    </div>
    <div class="px-6 py-5 flex items-start justify-between gap-6">
        <div>
            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Delete this period</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                Permanently removes this period and soft-deletes all {{ $period->documents()->count() }} associated document(s) to trash.
                This action cannot be easily undone.
            </p>
        </div>
        <button type="button" id="delete-period-btn"
                class="flex-shrink-0 inline-flex items-center gap-1.5 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-trash text-base"></i> Delete Period
        </button>
    </div>
</div>

<form id="delete-period-form" method="POST"
      action="{{ route('departments.policy.periods.destroy', [$department->levelAlias(), $department, $policy, $period]) }}"
      style="display:none">
    @csrf @method('DELETE')
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

        const docCount = {{ $period->documents()->count() }};
        document.getElementById('delete-period-btn').addEventListener('click', function () {
            const isDark = document.documentElement.classList.contains('dark');
            Swal.fire({
                title: 'Delete Period?',
                html: '<p class="text-sm text-gray-500 mb-2">You are about to delete <strong>{{ e($period->name) }}</strong>.</p>'
                    + (docCount > 0
                        ? '<p class="text-sm text-red-500">This will also move <strong>' + docCount + ' document(s)</strong> to trash.</p>'
                        : '<p class="text-sm text-gray-400">No documents are associated with this period.</p>'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc2626',
                background: isDark ? '#1e293b' : '#ffffff',
                color: isDark ? '#f1f5f9' : '#0f172a',
            }).then(function (result) {
                if (result.isConfirmed) document.getElementById('delete-period-form').submit();
            });
        });
    } catch (err) { console.error('Period edit form init failed', err); }
})();
</script>
@endpush

</x-layout>

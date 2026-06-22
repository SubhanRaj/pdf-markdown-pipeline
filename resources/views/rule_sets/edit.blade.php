<x-layout
    title="Edit Rule Set"
    page-title="Edit Rule Set"
    page-subtitle="{{ $ruleSet->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $ruleSet->name,            'url' => route('departments.rules.show', [$department->levelAlias(), $department, $ruleSet])],
    ['name' => 'Edit',                    'url' => null],
]" />

<form id="ruleSetForm" method="POST"
      action="{{ route('departments.rules.update', [$department->levelAlias(), $department, $ruleSet]) }}"
      novalidate class="max-w-2xl">
    @csrf
    @method('PATCH')

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-book text-slate-400 dark:text-slate-500"></i> Rule Set Details
            </h3>

            {{-- Department context --}}
            <div class="mb-4 px-3 py-2.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <i class="ti ti-building text-slate-400 dark:text-slate-500"></i>
                <span>{{ $department->name }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs font-mono text-slate-400 dark:text-slate-500">{{ $department->slug }}</span>
            </div>

            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">Rule Set Name <span class="text-red-500">*</span></label>
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
                        placeholder="Brief description of this rule set (optional)"
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

            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('departments.rules.show', [$department->levelAlias(), $department, $ruleSet]) }}"
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
@if(auth()->user()->isAdmin())
<div class="max-w-2xl mt-6 rounded-xl border border-red-200 dark:border-red-800/50 bg-white dark:bg-slate-800">
    <div class="px-6 py-4 border-b border-red-100 dark:border-red-800/40 flex items-center gap-2">
        <i class="ti ti-alert-triangle text-red-500 dark:text-red-400 text-base"></i>
        <span class="text-sm font-semibold text-red-700 dark:text-red-400">Danger Zone</span>
    </div>
    <div class="px-6 py-5 flex items-start justify-between gap-6">
        <div>
            <p class="text-sm font-medium text-slate-700 dark:text-slate-200">Delete this rule set</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">
                Permanently removes the rule set and soft-deletes all {{ $ruleSet->documents()->count() }} associated document(s) to trash.
                This action cannot be easily undone.
            </p>
        </div>
        <button type="button" id="delete-ruleset-btn"
                class="flex-shrink-0 inline-flex items-center gap-1.5 border border-red-300 dark:border-red-700 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-trash text-base"></i> Delete Rule Set
        </button>
    </div>
</div>

<form id="delete-ruleset-form" method="POST"
      action="{{ route('departments.rules.destroy', [$department->levelAlias(), $department, $ruleSet]) }}"
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
            title: 'Delete Rule Set?',
            html: '<p class="text-sm text-gray-500 mb-2">You are about to delete <strong>{{ e($ruleSet->name) }}</strong>.</p>'
                + (docCount > 0
                    ? '<p class="text-sm text-red-500">This will also move <strong>' + docCount + ' document(s)</strong> to trash.</p>'
                    : '<p class="text-sm text-gray-400">No documents are associated with this rule set.</p>'),
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

@push('scripts')
<script>
(function () {
    try {
        const NAME_PATTERN = /^[\p{L}0-9\s\(\)\-\.\/&']{2,150}$/u;

        function validateName() {
            const el = document.getElementById('name');
            const err = document.getElementById('name-err');
            const val = el.value.trim();
            const msg = !val ? 'Rule set name is required.'
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

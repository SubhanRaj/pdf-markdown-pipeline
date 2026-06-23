<x-layout
    title="Edit Division — {{ $division->name }}"
    page-title="Edit Division"
    page-subtitle="{{ $section->name }} · {{ $division->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                       'url' => route('home')],
    ['name' => 'Departments',                'url' => route('departments.index')],
    ['name' => $department->levelLabel(),    'url' => null],
    ['name' => $department->name,            'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $section->name,               'url' => route('departments.sections.show', [$department->levelAlias(), $department, $section])],
    ['name' => $division->name,              'url' => route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division])],
    ['name' => 'Edit',                       'url' => null],
]" />

<form id="divisionForm" method="POST"
      action="{{ route('departments.sections.divisions.update', [$department->levelAlias(), $department, $section, $division]) }}"
      novalidate class="max-w-2xl">
    @csrf
    @method('PATCH')

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-layout-sidebar text-slate-400 dark:text-slate-500"></i> Division Details
            </h3>

            {{-- Section context --}}
            <div class="mb-4 px-3 py-2.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <i class="ti ti-folder text-slate-400 dark:text-slate-500"></i>
                <span>{{ $department->name }}</span>
                <span class="text-slate-300 dark:text-slate-600">›</span>
                <span>{{ $section->name }}</span>
            </div>

            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">Division Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name', $division->name) }}"
                        class="field-input @error('name') field-error @enderror"
                        required autofocus>
                    <p class="field-hint">Letters, numbers, spaces, hyphens, dots, brackets allowed.</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label">Slug <span class="text-slate-400 font-normal">(read-only)</span></label>
                    <input type="text" value="{{ $division->slug }}" disabled
                           class="field-input opacity-50 cursor-not-allowed bg-slate-50 dark:bg-slate-900/40">
                    <p class="field-hint">Slug is set at creation and cannot be changed — it is part of vault file paths.</p>
                </div>

                <div>
                    <label for="description" class="field-label">Description</label>
                    <textarea id="description" name="description" rows="3"
                        class="field-input resize-none @error('description') field-error @enderror">{{ old('description', $division->description) }}</textarea>
                    <p class="field-hint">Optional. Maximum 500 characters.</p>
                    <p class="field-err-msg hidden" id="description-err"></p>
                    @error('description') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]) }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back
            </a>
            <button type="submit"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-device-floppy"></i> Save Changes
            </button>
        </div>

    </div>
</form>

@push('scripts')
<script>
(function () {
    try {
        const NAME_PATTERN = /^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]{2,150}$/u;

        function validateName() {
            const el = document.getElementById('name');
            const err = document.getElementById('name-err');
            const val = el.value.trim();
            const msg = !val ? 'Division name is required.'
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

        document.getElementById('divisionForm').addEventListener('submit', function (e) {
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

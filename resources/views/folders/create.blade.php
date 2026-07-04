@php
    $isDivisionFolder = isset($division) && $division !== null;
    $storeUrl = $isDivisionFolder
        ? route('departments.sections.divisions.folders.store', [$department->levelAlias(), $department, $section, $division])
        : route('departments.sections.folders.store', [$department->levelAlias(), $department, $section]);
    $backUrl = $isDivisionFolder
        ? route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division])
        : route('departments.sections.show', [$department->levelAlias(), $department, $section]);
@endphp
<x-layout
    title="Add Folder"
    page-title="Add Folder"
    page-subtitle="Create a new folder (patravali) under {{ $isDivisionFolder ? $division->name : $section->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                       'url' => route('home')],
    ['name' => 'Departments',                'url' => route('departments.index')],
    ['name' => $department->levelLabel(),    'url' => null],
    ['name' => $department->name,            'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => $section->name,               'url' => route('departments.sections.show', [$department->levelAlias(), $department, $section])],
    ['name' => 'Add Folder',                 'url' => null],
]" />

<form id="folderForm" method="POST" action="{{ $storeUrl }}" novalidate class="max-w-2xl">
    @csrf

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-folder-star text-slate-400 dark:text-slate-500"></i> Folder Details
            </h3>

            <div class="mb-4 px-3 py-2.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <i class="ti ti-folder text-slate-400 dark:text-slate-500"></i>
                <span>{{ $department->name }}</span>
                <span class="text-slate-300 dark:text-slate-600">›</span>
                <span>{{ $section->name }}</span>
                @if($isDivisionFolder)
                <span class="text-slate-300 dark:text-slate-600">›</span>
                <span>{{ $division->name }}</span>
                @endif
            </div>

            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">Folder Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name') }}"
                        placeholder="e.g. Court Case — Liquor License Appeal 2024"
                        class="field-input @error('name') field-error @enderror"
                        required autofocus>
                    <p class="field-hint">Name of the physical dossier / case file. Letters, numbers, spaces, hyphens, dots, brackets allowed.</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="description" class="field-label">Description</label>
                    <textarea id="description" name="description" rows="3"
                        placeholder="Brief summary of this matter (optional)"
                        class="field-input resize-none @error('description') field-error @enderror">{{ old('description') }}</textarea>
                    <p class="field-hint">Optional. Maximum 500 characters.</p>
                    <p class="field-err-msg hidden" id="description-err"></p>
                    @error('description') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label">Visibility</label>
                    <div class="flex gap-3 mt-1">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="visibility" value="public" checked class="text-cyan-600 focus:ring-cyan-500">
                            <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-world text-sm text-green-500"></i> Public</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="radio" name="visibility" value="authenticated" class="text-cyan-600 focus:ring-cyan-500">
                            <span class="text-sm text-slate-700 dark:text-slate-200 flex items-center gap-1"><i class="ti ti-lock text-sm text-amber-500"></i> Authenticated Only</span>
                        </label>
                    </div>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Gates the folder page itself. Documents inside keep their own visibility.</p>
                </div>

            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ $backUrl }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back
            </a>
            <button type="submit"
                class="inline-flex items-center gap-2 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-plus"></i> Create Folder
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
            const msg = !val ? 'Folder name is required.'
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

        document.getElementById('folderForm').addEventListener('submit', function (e) {
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

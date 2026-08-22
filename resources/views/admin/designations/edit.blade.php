<x-layout
    title="Edit Designation"
    page-title="Edit Designation"
    page-subtitle="Update the preset for {{ $designation->name }}"
>

<form method="POST" action="{{ route('admin.designations.update', $designation) }}" class="max-w-3xl">
    @csrf
    @method('PATCH')

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <p class="text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/50 rounded-lg px-4 py-3 mb-4 flex items-center gap-2">
                <i class="ti ti-info-circle flex-shrink-0"></i>
                Changes here only affect the preset applied to <em>new</em> selections — existing users keep whatever privileges they already have.
            </p>
            <div class="grid grid-cols-2 gap-4">

                <div class="col-span-2 sm:col-span-1">
                    <label for="name" class="field-label">Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name', $designation->name) }}"
                        class="field-input @error('name') field-error @enderror"
                        required>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="department_id" class="field-label">Department</label>
                    <select id="department_id" name="department_id"
                        class="field-input @error('department_id') field-error @enderror">
                        <option value="">— Generic, any department —</option>
                        @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" {{ old('department_id', $designation->department_id) == $dept->id ? 'selected' : '' }}>
                            {{ $dept->name }} ({{ $dept->level === 'secretariat_level' ? 'Secretariat' : 'Department' }})
                        </option>
                        @endforeach
                    </select>
                    @error('department_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2">
                    <label for="default_scope" class="field-label">Default Scope <span class="text-red-500">*</span></label>
                    <select id="default_scope" name="default_scope"
                        class="field-input @error('default_scope') field-error @enderror" required>
                        @foreach(['none' => 'None', 'section' => 'Section', 'department' => 'Department', 'division' => 'Division', 'global' => 'Global (organisation-wide)'] as $val => $label)
                        <option value="{{ $val }}" {{ old('default_scope', $designation->default_scope) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('default_scope') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>

            <div class="mt-4">
                <label class="field-label">Default Privileges</label>
                <p class="text-xs text-slate-400 dark:text-slate-500 mb-3">Pre-checked on the user form when this designation is selected.</p>
                @include('admin._privilege_checkboxes', ['name' => 'default_privileges', 'checked' => old('default_privileges', $designation->default_privileges ?? [])])
            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('admin.designations.index') }}" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back to Designations
            </a>
            <button type="submit" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-device-floppy"></i> Save Changes
            </button>
        </div>

    </div>
</form>

</x-layout>

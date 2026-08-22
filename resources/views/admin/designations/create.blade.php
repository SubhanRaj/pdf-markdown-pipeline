<x-layout
    title="Add Designation"
    page-title="Add New Designation"
    page-subtitle="Define a real-world post preset for the user creation form"
>

<form method="POST" action="{{ route('admin.designations.store') }}" class="max-w-3xl">
    @csrf

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <div class="grid grid-cols-2 gap-4">

                <div class="col-span-2 sm:col-span-1">
                    <label for="name" class="field-label">Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name') }}"
                        placeholder="e.g. Additional Excise Commissioner"
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
                        <option value="{{ $dept->id }}" {{ old('department_id') == $dept->id ? 'selected' : '' }}>
                            {{ $dept->name }} ({{ $dept->level === 'secretariat_level' ? 'Secretariat' : 'Department' }})
                        </option>
                        @endforeach
                    </select>
                    <p class="field-hint">Leave as Generic so it's selectable under any department.</p>
                    @error('department_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2">
                    <label for="default_scope" class="field-label">Default Scope <span class="text-red-500">*</span></label>
                    <select id="default_scope" name="default_scope"
                        class="field-input @error('default_scope') field-error @enderror" required>
                        @foreach(['none' => 'None', 'section' => 'Section', 'department' => 'Department', 'division' => 'Division', 'global' => 'Global (organisation-wide)'] as $val => $label)
                        <option value="{{ $val }}" {{ old('default_scope') === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    <p class="field-hint">Informational only — the actual scope still comes from the privileges below + the user's assigned Department/Section/Division.</p>
                    @error('default_scope') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>

            <div class="mt-4">
                <label class="field-label">Default Privileges</label>
                <p class="text-xs text-slate-400 dark:text-slate-500 mb-3">Pre-checked on the user form when this designation is selected — a one-time preset, not a permanently synced rule.</p>
                @include('admin._privilege_checkboxes', ['name' => 'default_privileges', 'checked' => old('default_privileges', [])])
            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('admin.designations.index') }}" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back to Designations
            </a>
            <button type="submit" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-id-badge-2"></i> Create Designation
            </button>
        </div>

    </div>
</form>

</x-layout>

<x-layout
    title="Edit Department"
    page-title="Edit Department"
    page-subtitle="Update details for {{ $department->name }}"
>

<x-slot:breadcrumb>
    <a href="{{ route('home') }}" class="hover:text-slate-600 dark:hover:text-slate-300">Home</a>
    <i class="ti ti-chevron-right"></i>
    <a href="{{ route('vault.departments.index') }}" class="hover:text-slate-600 dark:hover:text-slate-300">Departments</a>
    <i class="ti ti-chevron-right"></i>
    <a href="{{ route('vault.departments.show', $department) }}" class="hover:text-slate-600 dark:hover:text-slate-300">{{ $department->name }}</a>
    <i class="ti ti-chevron-right"></i>
    <span>Edit</span>
</x-slot:breadcrumb>

<form id="deptForm" method="POST" action="{{ route('vault.departments.update', $department) }}" novalidate class="max-w-2xl">
    @csrf
    @method('PATCH')

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-building text-slate-400 dark:text-slate-500"></i> Department Details
            </h3>
            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">Department Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name', $department->name) }}"
                        placeholder="e.g. Excise Department"
                        class="field-input @error('name') field-error @enderror"
                        data-rule="name" required autofocus>
                    <p class="field-hint">Letters, spaces, ampersands, hyphens and dots only.</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="slug" class="field-label">Slug <span class="text-red-500">*</span></label>
                    <input id="slug" name="slug" type="text"
                        value="{{ old('slug', $department->slug) }}"
                        placeholder="e.g. excise"
                        class="field-input font-mono @error('slug') field-error @enderror"
                        data-rule="slug">
                    <p class="field-hint">Changing the slug affects vault storage paths — do with care.</p>
                    <p class="field-err-msg hidden" id="slug-err"></p>
                    @error('slug') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="level" class="field-label">Level <span class="text-red-500">*</span></label>
                    <select id="level" name="level"
                        class="field-input @error('level') field-error @enderror" required>
                        <option value="">— Select level —</option>
                        <option value="department_level"  {{ old('level', $department->level) === 'department_level'  ? 'selected' : '' }}>Department Level (HQ / Field)</option>
                        <option value="secretariat_level" {{ old('level', $department->level) === 'secretariat_level' ? 'selected' : '' }}>Secretariat Level (JS / DS Wing)</option>
                    </select>
                    @error('level') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('vault.departments.show', $department) }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back
            </a>
            <div class="flex items-center gap-3">
                <form method="POST" action="{{ route('vault.departments.destroy', $department) }}"
                      onsubmit="return confirm('Delete {{ addslashes($department->name) }}? This cannot be undone.')">
                    @csrf @method('DELETE')
                    <button type="submit"
                        class="text-sm text-red-500 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 flex items-center gap-1 transition-colors">
                        <i class="ti ti-trash"></i> Delete
                    </button>
                </form>
                <button type="submit"
                    class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                    <i class="ti ti-device-floppy"></i> Save Changes
                </button>
            </div>
        </div>

    </div>
</form>

@push('scripts')
<script>
(function () {
    const RULES = {
        name: { pattern: /^[\p{L}\s\&'\-\.]{2,100}$/u, msg: 'Name contains invalid characters.' },
        slug: { pattern: /^[a-z0-9\-_]{1,80}$/, msg: 'Slug: lowercase letters, numbers and hyphens only.' },
    };

    function validateField(id) {
        const el   = document.getElementById(id);
        const rule = RULES[id];
        if (!el || !rule) return true;
        const val = el.value.trim();
        const err = document.getElementById(`${id}-err`);
        let msg = !val ? 'This field is required.' : (rule.pattern && !rule.pattern.test(val) ? rule.msg : null);
        if (msg) {
            el.classList.remove('field-valid'); el.classList.add('field-error');
            if (err) { err.textContent = msg; err.classList.remove('hidden'); }
            return false;
        }
        el.classList.remove('field-error'); if (val) el.classList.add('field-valid');
        if (err) { err.textContent = ''; err.classList.add('hidden'); }
        return true;
    }

    Object.keys(RULES).forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('blur', () => validateField(id));
            el.addEventListener('input', () => { if (el.classList.contains('field-error')) validateField(id); });
        }
    });

    document.getElementById('deptForm').addEventListener('submit', function (e) {
        const valid = ['name', 'slug'].map(validateField).every(Boolean);
        if (!document.getElementById('level').value) { e.preventDefault(); document.getElementById('level').focus(); return; }
        if (!valid) { e.preventDefault(); document.querySelector('.field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
})();
</script>
@endpush

</x-layout>

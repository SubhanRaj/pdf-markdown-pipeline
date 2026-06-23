<x-layout
    title="Add Department"
    page-title="Add Department"
    page-subtitle="Create a new department in the vault hierarchy"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'Departments', 'url' => route('departments.index')],
    ['name' => 'Add', 'url' => null],
]" />

<form id="deptForm" method="POST" action="{{ route('departments.store') }}" novalidate class="max-w-2xl">
    @csrf

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-building text-slate-400 dark:text-slate-500"></i> Department Details
            </h3>
            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">Department Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name') }}"
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
                        value="{{ old('slug') }}"
                        placeholder="e.g. excise"
                        class="field-input font-mono @error('slug') field-error @enderror"
                        data-rule="slug">
                    <p class="field-hint">Lowercase letters, numbers and hyphens. Auto-generated from name — edit only if needed.</p>
                    <p class="field-err-msg hidden" id="slug-err"></p>
                    @error('slug') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="level" class="field-label">Level <span class="text-red-500">*</span></label>
                    <select id="level" name="level"
                        class="field-input @error('level') field-error @enderror" required>
                        <option value="">— Select level —</option>
                        <option value="department_level"   {{ old('level') === 'department_level'   ? 'selected' : '' }}>Department Level (HQ / Field)</option>
                        <option value="secretariat_level"  {{ old('level') === 'secretariat_level'  ? 'selected' : '' }}>Secretariat Level (JS / DS Wing)</option>
                    </select>
                    @error('level') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('departments.index') }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back
            </a>
            <button type="submit"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-plus"></i> Create Department
            </button>
        </div>

    </div>
</form>

@push('scripts')
<script>
(function () {
    const RULES = {
        name: { pattern: /^[\p{L}\p{M}\p{N}\p{P}\p{Z}\s]{2,100}$/u, msg: 'Name contains invalid characters.' },
        slug: { pattern: /^[a-z0-9\-_]{1,80}$/, msg: 'Slug: lowercase letters, numbers and hyphens only.' },
    };

    function slugify(str) {
        return str.toLowerCase().trim()
            .replace(/[\s&]+/g, '-')
            .replace(/[^a-z0-9\-_]/g, '')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');
    }

    function validateField(id) {
        const el   = document.getElementById(id);
        const rule = RULES[id];
        if (!el || !rule) return true;
        const val = el.value.trim();
        const err = document.getElementById(`${id}-err`);
        let msg = null;
        if (!val) { msg = 'This field is required.'; }
        else if (rule.pattern && !rule.pattern.test(val)) { msg = rule.msg; }
        if (msg) {
            el.classList.remove('field-valid'); el.classList.add('field-error');
            if (err) { err.textContent = msg; err.classList.remove('hidden'); }
            return false;
        }
        el.classList.remove('field-error'); if (val) el.classList.add('field-valid');
        if (err) { err.textContent = ''; err.classList.add('hidden'); }
        return true;
    }

    // Auto-generate slug from name (only if slug hasn't been manually edited)
    let slugEdited = {{ old('slug') ? 'true' : 'false' }};
    document.getElementById('slug').addEventListener('input', () => { slugEdited = true; });
    document.getElementById('name').addEventListener('input', function () {
        if (!slugEdited) document.getElementById('slug').value = slugify(this.value);
        if (this.classList.contains('field-error')) validateField('name');
    });

    Object.keys(RULES).forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('blur', () => validateField(id));
            el.addEventListener('input', () => { if (el.classList.contains('field-error')) validateField(id); });
        }
    });

    document.getElementById('deptForm').addEventListener('submit', function (e) {
        const valid = ['name', 'slug'].map(validateField).every(Boolean);
        if (!document.getElementById('level').value) {
            e.preventDefault(); document.getElementById('level').focus(); return;
        }
        if (!valid) {
            e.preventDefault();
            document.querySelector('.field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();
</script>
@endpush

</x-layout>

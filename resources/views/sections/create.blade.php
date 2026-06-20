<x-layout
    title="Add Section"
    page-title="Add Section"
    page-subtitle="Create a new section under {{ $department->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'Departments', 'url' => route('departments.index')],
    ['name' => $department->name, 'url' => route('departments.show', $department)],
    ['name' => 'Add Section', 'url' => null],
]" />

<form id="sectionForm" method="POST"
      action="{{ route('departments.sections.store', $department) }}"
      novalidate class="max-w-2xl">
    @csrf

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-layout-list text-slate-400 dark:text-slate-500"></i> Section Details
            </h3>

            {{-- Department (read-only context) --}}
            <div class="mb-4 px-3 py-2.5 bg-slate-50 dark:bg-slate-900/50 border border-slate-200 dark:border-slate-700 rounded-lg flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                <i class="ti ti-building text-slate-400 dark:text-slate-500"></i>
                <span>{{ $department->name }}</span>
                <span class="text-slate-300 dark:text-slate-600">·</span>
                <span class="text-xs font-mono text-slate-400 dark:text-slate-500">{{ $department->slug }}</span>
            </div>

            <div class="space-y-4">

                <div>
                    <label for="name" class="field-label">Section Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name') }}"
                        placeholder="e.g. Establishment Section"
                        class="field-input @error('name') field-error @enderror"
                        data-rule="name" required autofocus>
                    <p class="field-hint">Letters, spaces, ampersands, hyphens, dots, slashes only.</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="slug" class="field-label">Slug <span class="text-red-500">*</span></label>
                    <input id="slug" name="slug" type="text"
                        value="{{ old('slug') }}"
                        placeholder="e.g. establishment_section"
                        class="field-input font-mono @error('slug') field-error @enderror"
                        data-rule="slug">
                    <p class="field-hint">Lowercase, numbers, hyphens, underscores. Auto-generated from name.</p>
                    <p class="field-err-msg hidden" id="slug-err"></p>
                    @error('slug') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="wing" class="field-label">Wing / Sub-group</label>
                    <select id="wing" name="wing"
                        class="field-input @error('wing') field-error @enderror">
                        <option value="">— None —</option>
                        <option value="headquarter"           {{ old('wing') === 'headquarter'           ? 'selected' : '' }}>Headquarter</option>
                        <option value="joint_secretary_wing"  {{ old('wing') === 'joint_secretary_wing'  ? 'selected' : '' }}>Joint Secretary Wing</option>
                        <option value="deputy_secretary_wing" {{ old('wing') === 'deputy_secretary_wing' ? 'selected' : '' }}>Deputy Secretary Wing</option>
                        <option value="field_office"          {{ old('wing') === 'field_office'          ? 'selected' : '' }}>Field Office</option>
                    </select>
                    <p class="field-hint">Optional — groups sections within the department.</p>
                    @error('wing') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('departments.show', $department) }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back
            </a>
            <button type="submit"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-plus"></i> Create Section
            </button>
        </div>

    </div>
</form>

@push('scripts')
<script>
(function () {
    const RULES = {
        name: { pattern: /^[\p{L}\s\&'\-\.\/]{2,120}$/u, msg: 'Name contains invalid characters.' },
        slug: { pattern: /^[a-z0-9\-_]{1,80}$/, msg: 'Slug: lowercase letters, numbers, hyphens and underscores only.' },
    };

    function slugify(str) {
        return str.toLowerCase().trim()
            .replace(/[\s\/]+/g, '_')
            .replace(/[^a-z0-9\-_]/g, '')
            .replace(/_+/g, '_')
            .replace(/^_|_$/g, '');
    }

    function validateField(id) {
        const el = document.getElementById(id), rule = RULES[id];
        if (!el || !rule) return true;
        const val = el.value.trim(), err = document.getElementById(`${id}-err`);
        const msg = !val ? 'This field is required.' : (rule.pattern && !rule.pattern.test(val) ? rule.msg : null);
        if (msg) {
            el.classList.remove('field-valid'); el.classList.add('field-error');
            if (err) { err.textContent = msg; err.classList.remove('hidden'); }
            return false;
        }
        el.classList.remove('field-error'); if (val) el.classList.add('field-valid');
        if (err) { err.textContent = ''; err.classList.add('hidden'); }
        return true;
    }

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

    document.getElementById('sectionForm').addEventListener('submit', function (e) {
        const valid = ['name', 'slug'].map(validateField).every(Boolean);
        if (!valid) {
            e.preventDefault();
            document.querySelector('.field-error')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();
</script>
@endpush

</x-layout>

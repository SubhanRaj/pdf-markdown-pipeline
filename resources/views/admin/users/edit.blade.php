<x-layout
    title="Edit User"
    page-title="Edit User"
    page-subtitle="Update account details for {{ $user->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'Users', 'url' => route('admin.users.index')],
    ['name' => 'Edit · ' . $user->name, 'url' => null],
]" />

<form
    id="editUserForm"
    method="POST"
    action="{{ route('admin.users.update', $user) }}"
    novalidate
    class="max-w-5xl"
>
    @csrf
    @method('PATCH')

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        {{-- ── Section: Identity ── --}}
        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-user text-slate-400 dark:text-slate-500"></i> Identity
            </h3>
            <div class="grid grid-cols-2 gap-4">

                <div class="col-span-2 sm:col-span-1">
                    <label for="name" class="field-label">Full Name <span class="text-red-500">*</span></label>
                    <input id="name" name="name" type="text"
                        value="{{ old('name', $user->name) }}"
                        placeholder="e.g. Ramesh Kumar Sharma"
                        class="field-input @error('name') field-error @enderror"
                        data-rule="name" required>
                    <p class="field-hint">Letters, spaces, hyphens and dots only.</p>
                    <p class="field-err-msg hidden" id="name-err"></p>
                    @error('name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="username" class="field-label">Username <span class="text-red-500">*</span></label>
                    <input id="username" name="username" type="text"
                        value="{{ old('username', $user->username) }}"
                        placeholder="e.g. ramesh_sharma"
                        class="field-input @error('username') field-error @enderror"
                        data-rule="username" required>
                    <p class="field-hint">3–30 chars. Letters, numbers, underscores only.</p>
                    <p class="field-err-msg hidden" id="username-err"></p>
                    @error('username') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="email" class="field-label">Email Address <span class="text-red-500">*</span></label>
                    <input id="email" name="email" type="email"
                        value="{{ old('email', $user->email) }}"
                        placeholder="ramesh@example.gov.in"
                        class="field-input @error('email') field-error @enderror"
                        data-rule="email" required>
                    <p class="field-err-msg hidden" id="email-err"></p>
                    @error('email') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="mobile" class="field-label">Mobile Number</label>
                    <input id="mobile" name="mobile" type="tel"
                        value="{{ old('mobile', $user->mobile) }}"
                        placeholder="9876543210 or +91-9876543210"
                        maxlength="14"
                        class="field-input @error('mobile') field-error @enderror"
                        data-rule="mobile">
                    <p class="field-hint">10-digit mobile number (optional). +91 prefix stripped automatically.</p>
                    <p class="field-err-msg hidden" id="mobile-err"></p>
                    @error('mobile') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="landline" class="field-label">Landline Number</label>
                    <input id="landline" name="landline" type="tel"
                        value="{{ old('landline', $user->landline) }}"
                        placeholder="0522-223456"
                        maxlength="20"
                        class="field-input @error('landline') field-error @enderror"
                        data-rule="landline">
                    <p class="field-hint">STD code + number (optional), e.g. 0522-223456 or 0522 223456.</p>
                    <p class="field-err-msg hidden" id="landline-err"></p>
                    @error('landline') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>
        </div>

        {{-- ── Section: Password ── --}}
        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-1 flex items-center gap-2">
                <i class="ti ti-lock text-slate-400 dark:text-slate-500"></i> Password
            </h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mb-4">Leave both fields blank to keep the current password.</p>
            <div class="grid grid-cols-2 gap-4">

                <div class="col-span-2 sm:col-span-1">
                    <label for="password" class="field-label">New Password</label>
                    <div class="relative">
                        <input id="password" name="password" type="password"
                            placeholder="••••••••"
                            class="field-input pr-10 @error('password') field-error @enderror"
                            data-rule="password">
                        <button type="button" onclick="toggleField('password','eye-pw')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                            <i id="eye-pw" class="ti ti-eye text-sm"></i>
                        </button>
                    </div>
                    <p class="field-hint">Min 8 chars · uppercase · lowercase · number · symbol.</p>
                    <p class="field-err-msg hidden" id="password-err"></p>
                    @error('password') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="password_confirmation" class="field-label">Confirm New Password</label>
                    <div class="relative">
                        <input id="password_confirmation" name="password_confirmation" type="password"
                            placeholder="••••••••"
                            class="field-input pr-10"
                            data-rule="password_confirmation">
                        <button type="button" onclick="toggleField('password_confirmation','eye-pw2')"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300">
                            <i id="eye-pw2" class="ti ti-eye text-sm"></i>
                        </button>
                    </div>
                    <p class="field-err-msg hidden" id="password_confirmation-err"></p>
                </div>

            </div>

            <div class="mt-3">
                <div class="flex gap-1 h-1.5">
                    <div id="str-1" class="flex-1 rounded-full bg-slate-200 dark:bg-slate-700 transition-colors duration-300"></div>
                    <div id="str-2" class="flex-1 rounded-full bg-slate-200 dark:bg-slate-700 transition-colors duration-300"></div>
                    <div id="str-3" class="flex-1 rounded-full bg-slate-200 dark:bg-slate-700 transition-colors duration-300"></div>
                    <div id="str-4" class="flex-1 rounded-full bg-slate-200 dark:bg-slate-700 transition-colors duration-300"></div>
                </div>
                <p id="str-label" class="text-xs text-slate-400 mt-1"></p>
            </div>
        </div>

        {{-- ── Section: Role & Assignment ── --}}
        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-shield-check text-slate-400 dark:text-slate-500"></i> Role & Assignment
            </h3>
            <div class="grid grid-cols-2 gap-4">

                <div class="col-span-2 sm:col-span-1">
                    <label for="post" class="field-label">Post / Designation</label>
                    <input id="post" name="post" type="text"
                        value="{{ old('post', $user->post) }}"
                        placeholder="e.g. Section Officer"
                        class="field-input @error('post') field-error @enderror"
                        data-rule="post">
                    @error('post') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="role" class="field-label">Role <span class="text-red-500">*</span></label>
                    <select id="role" name="role"
                        class="field-input @error('role') field-error @enderror" required>
                        <option value="">— Select role —</option>
                        @foreach(['admin' => 'Admin — Full access', 'operator' => 'Operator — Upload & convert', 'viewer' => 'Viewer — Read only'] as $val => $label)
                        <option value="{{ $val }}" {{ old('role', $user->role) === $val ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('role') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="department_id" class="field-label">Department</label>
                    <select id="department_id" name="department_id"
                        class="field-input @error('department_id') field-error @enderror"
                        onchange="filterSections(this.value)">
                        <option value="">— None —</option>
                        @foreach($departments as $dept)
                        <option value="{{ $dept->id }}"
                            {{ old('department_id', $user->department_id) == $dept->id ? 'selected' : '' }}>
                            {{ $dept->name }} ({{ $dept->level === 'secretariat_level' ? 'Secretariat' : 'Department' }})
                        </option>
                        @endforeach
                    </select>
                    @error('department_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="section_id" class="field-label">Section</label>
                    <select id="section_id" name="section_id"
                        class="field-input @error('section_id') field-error @enderror"
                        onchange="filterDivisions(this.value)">
                        <option value="">— None —</option>
                        @foreach($sections as $sec)
                        <option value="{{ $sec->id }}"
                            data-dept="{{ $sec->department_id }}"
                            {{ old('section_id', $user->section_id) == $sec->id ? 'selected' : '' }}>
                            {{ $sec->name }}
                        </option>
                        @endforeach
                    </select>
                    @error('section_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label for="division_id" class="field-label">Division <span class="text-xs font-normal text-slate-400">(optional — smallest scope)</span></label>
                    <select id="division_id" name="division_id"
                        class="field-input @error('division_id') field-error @enderror">
                        <option value="">— None —</option>
                        @foreach($divisions as $div)
                        <option value="{{ $div->id }}"
                            data-section="{{ $div->section_id }}"
                            {{ old('division_id', $user->division_id) == $div->id ? 'selected' : '' }}>
                            {{ $div->name }}
                        </option>
                        @endforeach
                    </select>
                    @error('division_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>

            @php
            $privilegeLabels = [
                'documents.upload'       => ['label' => 'Upload documents',           'group' => 'Documents'],
                'documents.edit'         => ['label' => 'Edit document metadata',     'group' => 'Documents'],
                'documents.delete'       => ['label' => 'Archive (soft-delete) docs', 'group' => 'Documents'],
                'documents.restore'      => ['label' => 'Restore from archive',       'group' => 'Documents'],
                'documents.force-delete' => ['label' => 'Permanently delete (requires letter)', 'group' => 'Documents'],
                'documents.verify'       => ['label' => 'Verify / approve docs',      'group' => 'Documents'],
                'section.head'           => ['label' => 'Section Head (create divisions in own section)', 'group' => 'Organisational'],
                'department.head'        => ['label' => 'Department Head (create sections/divisions in own dept)', 'group' => 'Organisational'],
                'organization.head'      => ['label' => 'Organisation Head (full access across all depts)', 'group' => 'Organisational'],
            ];
            $privGroups     = collect($privilegeLabels)->groupBy(fn($v) => $v['group']);
            $userPrivileges = old('privileges', $user->privileges ?? []);
            @endphp
            <div class="mt-4">
                <label class="field-label">Granular Privileges</label>
                <p class="text-xs text-slate-400 dark:text-slate-500 mb-3">Applies on top of role. Admin always has all. Privileges control what actions and scopes the user can access.</p>
                @foreach($privGroups as $group => $privs)
                <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1.5 mt-3">{{ $group }}</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                    @foreach($privs as $key => $meta)
                    <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300 cursor-pointer select-none p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
                        <input type="checkbox" name="privileges[]" value="{{ $key }}"
                            {{ in_array($key, $userPrivileges) ? 'checked' : '' }}
                            class="mt-0.5 w-4 h-4 rounded border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-400 flex-shrink-0">
                        <span>
                            <span class="font-medium">{{ $meta['label'] }}</span>
                            <span class="block text-[10px] text-slate-400 font-mono">{{ $key }}</span>
                        </span>
                    </label>
                    @endforeach
                </div>
                @endforeach
            </div>
        </div>

        {{-- ── Footer actions ── --}}
        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('admin.users.index') }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back to Users
            </a>
            <button type="submit" id="submitBtn"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-device-floppy"></i>
                Save Changes
            </button>
        </div>

    </div>
</form>

@push('scripts')
<script id="sections-data" type="application/json">@json($sections->keyBy('id'))</script>
<script>
(function () {

    const RULES = {
        name:     { pattern: /^[\p{L}\s'\-\.]{2,100}$/u, msg: 'Name must be 2–100 letters only (spaces, hyphens, dots allowed).' },
        username: { pattern: /^[a-zA-Z0-9_]{3,30}$/, msg: 'Username: 3–30 chars, letters/numbers/underscores only.' },
        email:    { pattern: /^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/, msg: 'Enter a valid email address.' },
        mobile: {
            custom: () => {
                const raw = document.getElementById('mobile').value.trim();
                if (!raw) return null;
                const digits = raw.replace(/\D/g, '');
                const n = (digits.length === 12 && digits.startsWith('91')) ? digits.slice(2) : digits;
                return /^\d{10}$/.test(n) ? null : 'Must be 10 digits. +91 or +91- prefix is stripped automatically.';
            },
            optional: true
        },
        landline: {
            pattern: /^[\d\s\-\+\(\)]{7,20}$/,
            msg: 'Enter STD code + number (e.g. 0522-223456). Digits, spaces, hyphens, and parentheses only.',
            optional: true
        },
        password: { pattern: /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&\#^()\[\]{}|;:,.<>?\/\\`~"'\-_=+])[^\s]{8,}$/, msg: 'Min 8 chars with uppercase, lowercase, number, and symbol.', optional: true },
        password_confirmation: {
            custom: () => {
                const pw = document.getElementById('password').value;
                const cf = document.getElementById('password_confirmation').value;
                if (!pw && !cf) return null;
                return pw === cf ? null : 'Passwords do not match.';
            }
        },
        post: { pattern: /^[\p{L}\s'\-\.&\/\(\)]{0,100}$/u, msg: 'Designation contains invalid characters.', optional: true },
    };

    const strChecks = [(v) => v.length >= 8, (v) => /[A-Z]/.test(v) && /[a-z]/.test(v), (v) => /\d/.test(v), (v) => /[@$!%*?&\#^()\[\]{}|;:,.<>?\/\\`~"'\-_=+]/.test(v)];
    const strColors  = ['bg-red-400', 'bg-amber-400', 'bg-yellow-400', 'bg-emerald-500'];
    const strLabels  = ['Weak', 'Fair', 'Good', 'Strong'];

    function updateStrength(val) {
        const score = strChecks.filter(fn => fn(val)).length;
        for (let i = 1; i <= 4; i++) {
            document.getElementById(`str-${i}`).className =
                `flex-1 rounded-full transition-colors duration-300 ${i <= score ? strColors[score - 1] : 'bg-slate-200 dark:bg-slate-700'}`;
        }
        const label = document.getElementById('str-label');
        label.textContent = val.length ? strLabels[score - 1] ?? '' : '';
        label.className = `text-xs mt-1 ${score >= 4 ? 'text-emerald-600' : score >= 2 ? 'text-amber-600' : 'text-red-500'}`;
    }

    function validateField(id) {
        const el   = document.getElementById(id);
        const rule = RULES[id];
        if (!el || !rule) return true;
        const val = el.value.trim();
        const err = document.getElementById(`${id}-err`);
        let message = null;
        if (rule.custom) {
            message = rule.custom();
        } else if (!val && !rule.optional) {
            message = 'This field is required.';
        } else if (val && rule.pattern && !rule.pattern.test(val)) {
            message = rule.msg;
        }
        if (message) {
            el.classList.remove('field-valid'); el.classList.add('field-error');
            if (err) { err.textContent = message; err.classList.remove('hidden'); }
            return false;
        } else {
            el.classList.remove('field-error');
            if (val) el.classList.add('field-valid');
            if (err) { err.textContent = ''; err.classList.add('hidden'); }
            return true;
        }
    }

    Object.keys(RULES).forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('blur',  () => validateField(id));
        el.addEventListener('input', () => {
            if (el.classList.contains('field-error')) validateField(id);
            if (id === 'password') {
                updateStrength(el.value);
                if (document.getElementById('password_confirmation').value) validateField('password_confirmation');
            }
        });
    });

    document.getElementById('editUserForm').addEventListener('submit', function (e) {
        const fields = Object.keys(RULES);
        const valid  = fields.map(validateField).every(Boolean);
        const role = document.getElementById('role').value;
        if (!role) { e.preventDefault(); document.getElementById('role').focus(); return; }
        if (!valid) {
            e.preventDefault();
            const firstError = document.querySelector('.field-error');
            firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstError?.focus();
        }
    });

    window.filterSections = function (deptId) {
        const select  = document.getElementById('section_id');
        const options = select.querySelectorAll('option[data-dept]');
        options.forEach(opt => {
            opt.hidden   = deptId && opt.dataset.dept !== deptId;
            opt.disabled = deptId && opt.dataset.dept !== deptId;
        });
        if (deptId) { select.value = ''; filterDivisions(''); }
    };

    window.filterDivisions = function (sectionId) {
        const select  = document.getElementById('division_id');
        if (!select) return;
        const options = select.querySelectorAll('option[data-section]');
        options.forEach(opt => {
            opt.hidden   = sectionId && opt.dataset.section !== String(sectionId);
            opt.disabled = sectionId && opt.dataset.section !== String(sectionId);
        });
        if (sectionId) select.value = '';
    };

    window.toggleField = function (fieldId, iconId) {
        const el = document.getElementById(fieldId);
        const icon = document.getElementById(iconId);
        el.type = el.type === 'password' ? 'text' : 'password';
        icon.className = el.type === 'password' ? 'ti ti-eye text-sm' : 'ti ti-eye-off text-sm';
    };

    const deptSel = document.getElementById('department_id');
    if (deptSel.value) filterSections(deptSel.value);
    const sectSel = document.getElementById('section_id');
    if (sectSel && sectSel.value) filterDivisions(sectSel.value);

})();
</script>
@endpush

</x-layout>

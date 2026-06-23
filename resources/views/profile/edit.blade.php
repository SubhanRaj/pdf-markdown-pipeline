<x-layout
    title="My Profile"
    page-title="My Profile"
    page-subtitle="Update your account details and password"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'My Profile', 'url' => null],
]" />

<form
    id="profileForm"
    method="POST"
    action="{{ route('profile.update') }}"
    novalidate
    class="max-w-3xl"
>
    @csrf
    @method('PATCH')

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        {{-- ── Identity ── --}}
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

                <div class="col-span-2">
                    <label for="post" class="field-label">Post / Designation</label>
                    <input id="post" name="post" type="text"
                        value="{{ old('post', $user->post) }}"
                        placeholder="e.g. Section Officer"
                        class="field-input @error('post') field-error @enderror"
                        data-rule="post">
                    @error('post') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>
        </div>

        {{-- ── Role & Department (read-only) ── --}}
        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-shield-check text-slate-400 dark:text-slate-500"></i> Role & Assignment
                <span class="text-xs font-normal text-slate-400 dark:text-slate-500">(set by administrator)</span>
            </h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="field-label">Role</p>
                    <p class="text-sm text-slate-700 dark:text-slate-200 font-medium mt-1">{{ ucfirst($user->role) }}</p>
                </div>
                <div>
                    <p class="field-label">Department</p>
                    <p class="text-sm text-slate-700 dark:text-slate-200 font-medium mt-1">{{ $user->department?->name ?? '—' }}</p>
                </div>
                <div>
                    <p class="field-label">Section</p>
                    <p class="text-sm text-slate-700 dark:text-slate-200 font-medium mt-1">{{ $user->section?->name ?? '—' }}</p>
                </div>
            </div>
        </div>

        {{-- ── Password ── --}}
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

        {{-- ── Footer ── --}}
        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('home') }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back to Home
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
    const strColors = ['bg-red-400', 'bg-amber-400', 'bg-yellow-400', 'bg-emerald-500'];
    const strLabels = ['Weak', 'Fair', 'Good', 'Strong'];

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

    document.getElementById('profileForm').addEventListener('submit', function (e) {
        const fields = Object.keys(RULES);
        const valid  = fields.map(validateField).every(Boolean);
        if (!valid) {
            e.preventDefault();
            const firstError = document.querySelector('.field-error');
            firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            firstError?.focus();
        }
    });

    window.toggleField = function (fieldId, iconId) {
        const el   = document.getElementById(fieldId);
        const icon = document.getElementById(iconId);
        el.type    = el.type === 'password' ? 'text' : 'password';
        icon.className = el.type === 'password' ? 'ti ti-eye text-sm' : 'ti ti-eye-off text-sm';
    };

})();
</script>
@endpush

</x-layout>

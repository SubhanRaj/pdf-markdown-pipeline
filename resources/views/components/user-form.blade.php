<?php

use App\Http\Controllers\Admin\UserManagementController;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

// Serves both admin/users/create.blade.php (no user-id) and edit.blade.php (:user-id passed).
// Reuses StoreUserRequest/UpdateUserRequest's authorize()/rules() verbatim (same
// createFrom()+validateResolved() path approval-actions/designation-manager established) and
// UserManagementController's store()/update()/resendActivation() for the actual writes.
//
// The department/section/division cascade and the designation privilege-preset — previously
// hand-rolled JS filtering hidden <option>s — become reactive computed properties instead.
// Skipped: the old inline regex validation-on-blur and the live username-preview placeholder
// (both purely decorative — the server already enforces every pattern via the FormRequests);
// errors now surface on submit instead of on blur, same tier as designation-manager.
new class extends Component
{
    public ?int $userId = null;

    public array $form = [
        'name' => '', 'username' => '', 'email' => '', 'mobile' => '', 'landline' => '',
        'designation_id' => null, 'post' => '', 'role' => '', 'uploads_require_approval' => false,
        'privileges' => [], 'department_id' => null, 'section_id' => null, 'division_id' => null,
    ];

    public function mount(?int $userId = null): void
    {
        $this->userId = $userId;

        if (! $userId) return;

        $user = User::findOrFail($userId);
        $this->form = [
            'name' => $user->name, 'username' => $user->username, 'email' => $user->email,
            'mobile' => $user->mobile, 'landline' => $user->landline,
            'designation_id' => $user->designation_id, 'post' => $user->post,
            'role' => $user->role, 'uploads_require_approval' => (bool) $user->uploads_require_approval,
            'privileges' => $user->privileges ?? [], 'department_id' => $user->department_id,
            'section_id' => $user->section_id, 'division_id' => $user->division_id,
        ];
    }

    #[Computed]
    public function editingUser(): ?User
    {
        return $this->userId ? User::findOrFail($this->userId) : null;
    }

    #[Computed]
    public function departments()
    {
        return Department::orderBy('name')->get(['id', 'name', 'level']);
    }

    #[Computed]
    public function designations()
    {
        return Designation::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'department_id', 'default_scope', 'default_privileges']);
    }

    #[Computed]
    public function sections()
    {
        return Section::when($this->form['department_id'], fn ($q) => $q->where('department_id', $this->form['department_id']))
            ->orderBy('name')->get(['id', 'name', 'department_id']);
    }

    #[Computed]
    public function divisions()
    {
        return Division::when($this->form['section_id'], fn ($q) => $q->where('section_id', $this->form['section_id']))
            ->orderBy('name')->get(['id', 'name', 'section_id']);
    }

    public function updated(string $name): void
    {
        if ($name === 'form.department_id') {
            $this->form['section_id'] = null;
            $this->form['division_id'] = null;
        }

        if ($name === 'form.section_id') {
            $this->form['division_id'] = null;
        }

        if ($name === 'form.designation_id') {
            $this->applyDesignationPreset();
        }
    }

    // Mirrors the old applyDesignationPreset() JS: a one-time, non-locking suggestion, not a
    // permanently synced rule — the admin can still freely edit department/privileges after.
    private function applyDesignationPreset(): void
    {
        $designation = $this->form['designation_id'] ? Designation::find($this->form['designation_id']) : null;
        if (! $designation) return;

        if ($designation->department_id) {
            $this->form['department_id'] = $designation->department_id;
            $this->form['section_id'] = null;
            $this->form['division_id'] = null;
        }

        $this->form['privileges'] = $designation->default_privileges ?? [];
    }

    public function resendActivation(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        app(UserManagementController::class)->resendActivation($this->editingUser);
    }

    public function save(): void
    {
        try {
            if ($this->userId) {
                $user = User::findOrFail($this->userId);
                $request = $this->formRequest(UpdateUserRequest::class, $this->form + ['id' => $user->id]);
                app(UserManagementController::class)->update($request, $user);
            } else {
                $request = $this->formRequest(StoreUserRequest::class, $this->form);
                app(UserManagementController::class)->store($request);
            }
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError("form.$field", $messages[0]);
            }
        }
    }

    private function formRequest(string $class, array $data): FormRequest
    {
        $request = $class::createFrom(request(), new $class());
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->merge($data);
        $request->validateResolved();

        return $request;
    }
}; ?>

<form wire:submit="save" class="max-w-5xl">

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        {{-- ── Section: Identity ── --}}
        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-user text-slate-400 dark:text-slate-500"></i> Identity
            </h3>
            <div class="grid grid-cols-2 gap-4">

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Full Name <span class="text-red-500">*</span></label>
                    <input type="text" wire:model="form.name"
                        placeholder="e.g. Ramesh Kumar Sharma"
                        class="field-input @error('form.name') field-error @enderror">
                    <p class="field-hint">Letters, spaces, hyphens and dots only.</p>
                    @error('form.name') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Username @unless($userId)<span class="text-xs font-normal text-slate-400">(optional)</span>@else<span class="text-red-500">*</span>@endunless</label>
                    <input type="text" wire:model="form.username"
                        placeholder="{{ $userId ? 'e.g. ramesh_sharma' : 'Auto-generated from name & post if left blank' }}"
                        class="field-input @error('form.username') field-error @enderror">
                    <p class="field-hint">3–30 chars. Letters, numbers, underscores only.</p>
                    @error('form.username') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Email Address <span class="text-red-500">*</span></label>
                    <input type="email" wire:model="form.email"
                        placeholder="ramesh@example.gov.in"
                        class="field-input @error('form.email') field-error @enderror">
                    @error('form.email') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Mobile Number</label>
                    <input type="tel" wire:model="form.mobile"
                        placeholder="9876543210 or +91-9876543210" maxlength="14"
                        class="field-input @error('form.mobile') field-error @enderror">
                    <p class="field-hint">10-digit mobile number (optional). +91 prefix stripped automatically.</p>
                    @error('form.mobile') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="field-label">Landline Number</label>
                    <input type="tel" wire:model="form.landline"
                        placeholder="0522-223456" maxlength="20"
                        class="field-input @error('form.landline') field-error @enderror">
                    <p class="field-hint">STD code + number (optional), e.g. 0522-223456 or 0522 223456.</p>
                    @error('form.landline') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>
        </div>

        {{-- ── Section: Activation ── --}}
        @if($userId && $this->editingUser->email_verified_at === null)
        <div class="px-6 py-5">
            <div class="flex items-center justify-between gap-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/50 rounded-lg px-4 py-3">
                <p class="text-sm text-amber-700 dark:text-amber-300 flex items-center gap-2">
                    <i class="ti ti-mail-exclamation text-base flex-shrink-0"></i>
                    This account has not been activated yet — the officer sets their own password via the emailed link.
                </p>
                <button type="button" wire:click="resendActivation" wire:loading.attr="disabled" wire:target="resendActivation"
                    class="text-xs font-semibold text-amber-700 dark:text-amber-300 hover:text-amber-900 dark:hover:text-amber-100 underline whitespace-nowrap disabled:opacity-50">
                    <span wire:loading.remove wire:target="resendActivation">Resend activation link</span>
                    <span wire:loading wire:target="resendActivation">Sending…</span>
                </button>
            </div>
        </div>
        @endif

        {{-- ── Section: Role & Assignment ── --}}
        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-4 flex items-center gap-2">
                <i class="ti ti-shield-check text-slate-400 dark:text-slate-500"></i> Role & Assignment
            </h3>
            <div class="grid grid-cols-2 gap-4">

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Designation</label>
                    <select wire:model.live="form.designation_id" class="field-input @error('form.designation_id') field-error @enderror">
                        <option value="">— None / see "Other post" —</option>
                        @foreach($this->designations as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @endforeach
                    </select>
                    <p class="field-hint">Selecting one pre-fills Department + Privileges below. Nothing is locked — adjust freely afterward.</p>
                    @error('form.designation_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Other post <span class="text-xs font-normal text-slate-400">(if not listed above)</span></label>
                    <input type="text" wire:model="form.post"
                        placeholder="e.g. Section Officer"
                        class="field-input @error('form.post') field-error @enderror">
                    @error('form.post') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Role <span class="text-red-500">*</span></label>
                    <select wire:model="form.role" class="field-input @error('form.role') field-error @enderror">
                        <option value="">— Select role —</option>
                        @foreach(['admin' => 'Admin — Full access', 'operator' => 'Operator — Upload & convert', 'viewer' => 'Viewer — Read only'] as $val => $label)
                        <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('form.role') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="checkbox" wire:model="form.uploads_require_approval"
                            class="w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-amber-600 focus:ring-amber-500">
                        <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Bulk Upload Mode — all uploads held for approval</span>
                    </label>
                    <p class="mt-1 ml-7 text-xs text-slate-500 dark:text-slate-400">Enable for bulk-onboarding operators. Every document they upload will go to "Pending Approval" rather than becoming immediately active.</p>
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Department</label>
                    <select wire:model.live="form.department_id" class="field-input @error('form.department_id') field-error @enderror">
                        <option value="">— None —</option>
                        @foreach($this->departments as $dept)
                        <option value="{{ $dept->id }}">{{ $dept->name }} ({{ $dept->level === 'secretariat_level' ? 'Secretariat' : 'Department' }})</option>
                        @endforeach
                    </select>
                    @error('form.department_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Section</label>
                    <select wire:model.live="form.section_id" class="field-input @error('form.section_id') field-error @enderror">
                        <option value="">— None —</option>
                        @foreach($this->sections as $sec)
                        <option value="{{ $sec->id }}">{{ $sec->name }}</option>
                        @endforeach
                    </select>
                    @error('form.section_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

                <div class="col-span-2 sm:col-span-1">
                    <label class="field-label">Division <span class="text-xs font-normal text-slate-400">(optional — smallest scope)</span></label>
                    <select wire:model="form.division_id" class="field-input @error('form.division_id') field-error @enderror">
                        <option value="">— None —</option>
                        @foreach($this->divisions as $div)
                        <option value="{{ $div->id }}">{{ $div->name }}</option>
                        @endforeach
                    </select>
                    @error('form.division_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                </div>

            </div>

            <div class="mt-4">
                <label class="field-label">Granular Privileges</label>
                <p class="text-xs text-slate-400 dark:text-slate-500 mb-3">Applies on top of role. Admin always has all. Privileges control what actions and scopes the user can access.</p>
                @include('admin._privilege_checkboxes', ['name' => 'privileges', 'wireModel' => 'form.privileges', 'checked' => $form['privileges']])
            </div>
        </div>

        {{-- ── Footer actions ── --}}
        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl flex items-center justify-between">
            <a href="{{ route('admin.users.index') }}" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back to Users
            </a>
            <button type="submit" wire:loading.attr="disabled" wire:target="save"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors disabled:opacity-50">
                <i class="ti {{ $userId ? 'ti-device-floppy' : 'ti-user-plus' }}"></i>
                <span wire:loading.remove wire:target="save">{{ $userId ? 'Save Changes' : 'Create Account' }}</span>
                <span wire:loading wire:target="save">{{ $userId ? 'Saving…' : 'Creating…' }}</span>
            </button>
        </div>

    </div>
</form>

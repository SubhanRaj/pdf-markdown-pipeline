<?php

use App\Http\Controllers\Admin\DesignationController;
use App\Http\Requests\Admin\StoreDesignationRequest;
use App\Http\Requests\Admin\UpdateDesignationRequest;
use App\Models\Department;
use App\Models\Designation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

// Full page — list + inline create/edit modal + delete — invoked from
// admin/designations/index.blade.php. Reuses StoreDesignationRequest/UpdateDesignationRequest's
// authorize()/rules() verbatim (same createFrom()+validateResolved() path approval-actions
// established) and DesignationController's store()/update()/destroy() for the actual writes.
new class extends Component
{
    public ?int $editingId = null;
    public bool $showModal = false;

    public array $form = [
        'name'               => '',
        'department_id'      => null,
        'default_scope'      => 'none',
        'sort_order'         => 0,
        'default_privileges' => [],
    ];

    #[Computed]
    public function designations()
    {
        return Designation::with('department')
            ->orderBy('sort_order')->orderBy('name')->get()
            ->groupBy(fn (Designation $d) => $d->department?->name ?? 'Generic (any department)');
    }

    #[Computed]
    public function departments()
    {
        return Department::orderBy('name')->get(['id', 'name', 'level']);
    }

    public function openCreate(): void
    {
        $this->editingId = null;
        $this->form = ['name' => '', 'department_id' => null, 'default_scope' => 'none', 'sort_order' => 0, 'default_privileges' => []];
        $this->showModal = true;
    }

    public function openEdit(int $id): void
    {
        $designation = Designation::findOrFail($id);
        $this->editingId = $designation->id;
        $this->form = [
            'name'               => $designation->name,
            'department_id'      => $designation->department_id,
            'default_scope'      => $designation->default_scope,
            'sort_order'         => $designation->sort_order,
            'default_privileges' => $designation->default_privileges ?? [],
        ];
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function save(): void
    {
        try {
            if ($this->editingId) {
                $designation = Designation::findOrFail($this->editingId);
                $request = $this->formRequest(UpdateDesignationRequest::class, $this->form + ['id' => $designation->id]);
                app(DesignationController::class)->update($request, $designation);
            } else {
                $request = $this->formRequest(StoreDesignationRequest::class, $this->form);
                app(DesignationController::class)->store($request);
            }
        } catch (ValidationException $e) {
            // Re-key onto "form.*" so @error() in the blade — which binds to the $form array
            // property, not the FormRequest's flat field names — actually picks them up
            // (Livewire only persists errors tied to a real component property).
            foreach ($e->errors() as $field => $messages) {
                $this->addError("form.$field", $messages[0]);
            }
            return;
        }

        $this->showModal = false;
    }

    // destroy() has no FormRequest of its own — the "is_admin" route middleware is its only
    // guard today, and that middleware doesn't apply to Livewire's own /livewire/update
    // endpoint, so the same check is replicated here directly.
    public function delete(int $id): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        app(DesignationController::class)->destroy(Designation::findOrFail($id));
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

<div>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">All Designations</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Picking one on the user form pre-fills scope and privileges — the admin can still adjust afterward.</p>
        </div>
        <button type="button" wire:click="openCreate"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-id-badge-2 text-base"></i> Add Designation
        </button>
    </div>

    @if($this->designations->isEmpty())
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-id-badge-2 text-4xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No designations yet</p>
        <button type="button" wire:click="openCreate" class="mt-3 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
            Create the first designation
        </button>
    </div>
    @else
    @foreach($this->designations as $groupName => $group)
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 last:border-b-0">
        <p class="text-[11px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-3">{{ $groupName }}</p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide border-b border-slate-100 dark:border-slate-700">
                        <th class="py-2 text-left">Name</th>
                        <th class="py-2 text-left">Default Scope</th>
                        <th class="py-2 text-left">Default Privileges</th>
                        <th class="py-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                    @foreach($group as $designation)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors" wire:key="designation-{{ $designation->id }}">
                        <td class="py-2.5 font-medium text-slate-800 dark:text-slate-100">{{ $designation->name }}</td>
                        <td class="py-2.5">
                            <span class="badge bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300">{{ ucfirst($designation->default_scope) }}</span>
                        </td>
                        <td class="py-2.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ count($designation->default_privileges ?? []) }} selected
                        </td>
                        <td class="py-2.5">
                            <div class="flex items-center justify-end gap-2">
                                <button type="button" wire:click="openEdit({{ $designation->id }})"
                                   class="text-slate-400 dark:text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors" title="Edit">
                                    <i class="ti ti-pencil text-base"></i>
                                </button>
                                <button type="button"
                                        onclick="if (confirm('Delete designation {{ addslashes($designation->name) }}? Existing users keep this designation for display, but it will no longer be selectable.')) { $wire.delete({{ $designation->id }}) }"
                                        class="text-slate-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                                        title="Delete">
                                    <i class="ti ti-trash text-base"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach
    @endif

</div>

{{-- ── Create / Edit modal ── --}}
@if($showModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" wire:click="closeModal"></div>
    <div class="relative bg-white dark:bg-slate-900 rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <form wire:submit="save">
            <div class="px-6 py-5">
                <h3 class="text-base font-semibold text-slate-900 dark:text-white mb-4">
                    {{ $editingId ? 'Edit Designation' : 'Add New Designation' }}
                </h3>

                @if($editingId)
                <p class="text-xs text-amber-600 dark:text-amber-400 bg-amber-50 dark:bg-amber-900/20 border border-amber-100 dark:border-amber-800/50 rounded-lg px-4 py-3 mb-4 flex items-center gap-2">
                    <i class="ti ti-info-circle flex-shrink-0"></i>
                    Changes here only affect the preset applied to <em>new</em> selections — existing users keep whatever privileges they already have.
                </p>
                @endif

                <div class="grid grid-cols-2 gap-4">
                    <div class="col-span-2 sm:col-span-1">
                        <label class="field-label">Name <span class="text-red-500">*</span></label>
                        <input type="text" wire:model="form.name"
                            placeholder="e.g. Additional Excise Commissioner"
                            class="field-input @error('form.name') field-error @enderror @error('form.slug') field-error @enderror">
                        @error('form.name') <p class="field-err-msg">{{ $message }}</p> @enderror
                        @error('form.slug') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>

                    <div class="col-span-2 sm:col-span-1">
                        <label class="field-label">Department</label>
                        <select wire:model="form.department_id" class="field-input @error('form.department_id') field-error @enderror">
                            <option value="">— Generic, any department —</option>
                            @foreach($this->departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->name }} ({{ $dept->level === 'secretariat_level' ? 'Secretariat' : 'Department' }})</option>
                            @endforeach
                        </select>
                        <p class="field-hint">Leave as Generic so it's selectable under any department.</p>
                        @error('form.department_id') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>

                    <div class="col-span-2 sm:col-span-1">
                        <label class="field-label">Default Scope <span class="text-red-500">*</span></label>
                        <select wire:model="form.default_scope" class="field-input @error('form.default_scope') field-error @enderror">
                            @foreach(['none' => 'None', 'section' => 'Section', 'department' => 'Department', 'division' => 'Division', 'global' => 'Global (organisation-wide)'] as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="field-hint">Informational only — the actual scope still comes from the privileges below + the user's assigned Department/Section/Division.</p>
                        @error('form.default_scope') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>

                    <div class="col-span-2 sm:col-span-1">
                        <label class="field-label">Sort Order</label>
                        <input type="number" wire:model="form.sort_order" class="field-input @error('form.sort_order') field-error @enderror">
                        @error('form.sort_order') <p class="field-err-msg">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div class="mt-4">
                    <label class="field-label">Default Privileges</label>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mb-3">Pre-checked on the user form when this designation is selected — a one-time preset, not a permanently synced rule.</p>
                    @include('admin._privilege_checkboxes', ['name' => 'default_privileges', 'wireModel' => 'form.default_privileges', 'checked' => $form['default_privileges']])
                </div>
            </div>

            <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-2xl flex items-center justify-end gap-3">
                <button type="button" wire:click="closeModal" class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">
                    Cancel
                </button>
                <button type="submit" wire:loading.attr="disabled" wire:target="save"
                    class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-5 py-2.5 rounded-lg transition-colors disabled:opacity-50">
                    <i class="ti ti-device-floppy"></i>
                    <span wire:loading.remove wire:target="save">{{ $editingId ? 'Save Changes' : 'Create Designation' }}</span>
                    <span wire:loading wire:target="save">Saving…</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endif

</div>

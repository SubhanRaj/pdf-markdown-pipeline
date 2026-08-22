<x-layout
    title="Designations"
    page-title="Designations"
    page-subtitle="Manage the real-world post presets used when creating user accounts"
>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">All Designations</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">Picking one on the user form pre-fills scope and privileges — the admin can still adjust afterward.</p>
        </div>
        <a href="{{ route('admin.designations.create') }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-id-badge-2 text-base"></i> Add Designation
        </a>
    </div>

    @if($designations->isEmpty())
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-id-badge-2 text-4xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No designations yet</p>
        <a href="{{ route('admin.designations.create') }}" class="mt-3 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
            Create the first designation
        </a>
    </div>
    @else
    @foreach($designations as $groupName => $group)
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
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
                        <td class="py-2.5 font-medium text-slate-800 dark:text-slate-100">{{ $designation->name }}</td>
                        <td class="py-2.5">
                            <span class="badge bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300">{{ ucfirst($designation->default_scope) }}</span>
                        </td>
                        <td class="py-2.5 text-xs text-slate-500 dark:text-slate-400">
                            {{ count($designation->default_privileges ?? []) }} selected
                        </td>
                        <td class="py-2.5">
                            <div class="flex items-center justify-end gap-2">
                                <a href="{{ route('admin.designations.edit', $designation) }}"
                                   class="text-slate-400 dark:text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors" title="Edit">
                                    <i class="ti ti-pencil text-base"></i>
                                </a>
                                <form id="delete-designation-{{ $designation->id }}" method="POST" action="{{ route('admin.designations.destroy', $designation) }}">
                                    @csrf @method('DELETE')
                                </form>
                                <button type="button"
                                        onclick="confirmDeleteDesignation('{{ $designation->id }}', '{{ addslashes($designation->name) }}')"
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

@push('scripts')
<script>
function confirmDeleteDesignation(id, name) {
    Swal.fire({
        icon: 'warning',
        title: 'Delete designation?',
        text: `Delete designation "${name}"? Existing users keep this designation for display, but it will no longer be selectable.`,
        showCancelButton: true,
        confirmButtonText: 'Delete',
        confirmButtonColor: '#dc2626',
        cancelButtonText: 'Cancel',
    }).then((result) => {
        if (result.isConfirmed) document.getElementById(`delete-designation-${id}`).submit();
    });
}
</script>
@endpush

</x-layout>

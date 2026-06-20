<x-layout
    title="User Management"
    page-title="User Management"
    page-subtitle="Admin-managed accounts for vault staff"
>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">

    {{-- Header --}}
    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">All Accounts</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $users->total() }} total · Soft-deleted accounts are hidden</p>
        </div>
        @if(auth()->user()->isAdmin())
        <a href="{{ route('admin.users.create') }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-user-plus text-base"></i> Add User
        </a>
        @endif
    </div>

    @if($users->isEmpty())
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-users text-4xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No users yet</p>
        <a href="{{ route('admin.users.create') }}" class="mt-3 text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
            Create the first account
        </a>
    </div>
    @else
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40">
                    <th class="px-5 py-3 text-left">User</th>
                    <th class="px-5 py-3 text-left">Contact</th>
                    <th class="px-5 py-3 text-left">Role</th>
                    <th class="px-5 py-3 text-left">Department / Section</th>
                    <th class="px-5 py-3 text-left">Joined</th>
                    <th class="px-5 py-3 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($users as $user)
                @php
                    $roleMap = [
                        'admin'    => 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400',
                        'operator' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
                        'viewer'   => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
                    ];
                @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-xs font-bold text-indigo-700 dark:text-indigo-400 flex-shrink-0">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </div>
                            <div class="min-w-0">
                                <p class="font-medium text-slate-800 dark:text-slate-100 truncate">{{ $user->name }}</p>
                                <p class="text-xs text-slate-400 dark:text-slate-500 truncate">{{ '@' . $user->username }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-5 py-3 text-slate-600 dark:text-slate-300">
                        <p class="truncate max-w-[180px]">{{ $user->email }}</p>
                        @if($user->mobile)
                        <p class="text-xs text-slate-400 dark:text-slate-500">{{ $user->mobile }}</p>
                        @endif
                    </td>
                    <td class="px-5 py-3">
                        <span class="badge {{ $roleMap[$user->role] ?? 'bg-slate-100 text-slate-600' }}">
                            {{ ucfirst($user->role) }}
                        </span>
                        @if($user->post)
                        <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $user->post }}</p>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-xs text-slate-600 dark:text-slate-300">
                        {{ $user->department?->name ?? '—' }}
                        @if($user->section)
                        <p class="text-slate-400 dark:text-slate-500">{{ $user->section->name }}</p>
                        @endif
                    </td>
                    <td class="px-5 py-3 text-xs text-slate-400 dark:text-slate-500">
                        {{ $user->created_at->format('d M Y') }}
                    </td>
                    <td class="px-5 py-3">
                        <div class="flex items-center justify-end gap-2">
                            <a href="{{ route('admin.users.edit', $user) }}"
                               class="text-slate-400 dark:text-slate-500 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors" title="Edit">
                                <i class="ti ti-pencil text-base"></i>
                            </a>
                            @if($user->id !== auth()->user()?->id)
                            <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                                  onsubmit="return confirm('Deactivate {{ addslashes($user->name) }}\'s account?')">
                                @csrf @method('DELETE')
                                <button type="submit"
                                        class="text-slate-400 dark:text-slate-500 hover:text-red-500 dark:hover:text-red-400 transition-colors"
                                        title="Deactivate">
                                    <i class="ti ti-user-x text-base"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($users->hasPages())
    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-700">
        {{ $users->links() }}
    </div>
    @endif
    @endif

</div>

</x-layout>

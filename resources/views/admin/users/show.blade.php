<x-layout
    title="User Profile"
    page-title="{{ $user->name }}"
    page-subtitle="{{ '@' . $user->username }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'Users', 'url' => route('admin.users.index')],
    ['name' => $user->name, 'url' => null],
]" />

@php
$roleMap = [
    'system_admin' => 'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-400',
    'admin'        => 'bg-indigo-100 dark:bg-indigo-900/40 text-indigo-700 dark:text-indigo-400',
    'operator'     => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
    'viewer'       => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
];
@endphp

<div class="max-w-5xl">
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 divide-y divide-slate-100 dark:divide-slate-700">

        <div class="px-6 py-5 flex items-start justify-between gap-4">
            <div class="flex items-center gap-4 min-w-0">
                <div class="w-12 h-12 rounded-full bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-lg font-bold text-indigo-700 dark:text-indigo-400 flex-shrink-0">
                    {{ strtoupper(substr($user->name, 0, 1)) }}
                </div>
                <div class="min-w-0">
                    <p class="text-base font-semibold text-slate-800 dark:text-slate-100 truncate">{{ $user->name }}</p>
                    <span class="badge {{ $roleMap[$user->role] ?? 'bg-slate-100 text-slate-600' }}">{{ $user->roleLabel() }}</span>
                    @if(! $user->email_verified_at)
                    <span class="badge bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400">Not activated</span>
                    @endif
                </div>
            </div>
            <a href="{{ route('admin.users.edit', $user) }}"
               class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition-colors flex-shrink-0">
                <i class="ti ti-pencil"></i> Edit
            </a>
        </div>

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
                <i class="ti ti-id-badge-2 text-slate-400 dark:text-slate-500"></i> Contact & Post
            </h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div><dt class="text-slate-400 dark:text-slate-500">Email</dt><dd class="text-slate-700 dark:text-slate-200">{{ $user->email }}</dd></div>
                <div><dt class="text-slate-400 dark:text-slate-500">Mobile</dt><dd class="text-slate-700 dark:text-slate-200">{{ $user->mobile ?? '—' }}</dd></div>
                <div><dt class="text-slate-400 dark:text-slate-500">Landline</dt><dd class="text-slate-700 dark:text-slate-200">{{ $user->landline ?? '—' }}</dd></div>
                <div><dt class="text-slate-400 dark:text-slate-500">Designation / Post</dt><dd class="text-slate-700 dark:text-slate-200">{{ $user->designation?->name ?? $user->post ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
                <i class="ti ti-building text-slate-400 dark:text-slate-500"></i> Scope
            </h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                <div><dt class="text-slate-400 dark:text-slate-500">Department</dt><dd class="text-slate-700 dark:text-slate-200">{{ $user->department?->name ?? '—' }}</dd></div>
                <div><dt class="text-slate-400 dark:text-slate-500">Section</dt><dd class="text-slate-700 dark:text-slate-200">{{ $user->section?->name ?? '—' }}</dd></div>
                <div><dt class="text-slate-400 dark:text-slate-500">Bulk Upload Mode</dt><dd class="text-slate-700 dark:text-slate-200">{{ $user->uploads_require_approval ? 'Enabled' : 'Disabled' }}</dd></div>
                <div><dt class="text-slate-400 dark:text-slate-500">Joined</dt><dd class="text-slate-700 dark:text-slate-200">{{ $user->created_at->format('d M Y') }}</dd></div>
            </dl>
        </div>

        <div class="px-6 py-5">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 mb-3 flex items-center gap-2">
                <i class="ti ti-shield-check text-slate-400 dark:text-slate-500"></i> Granular Privileges
            </h3>
            @include('admin._privilege_checkboxes', ['checked' => $user->privileges ?? [], 'readonly' => true])
        </div>

        <div class="px-6 py-4 bg-slate-50 dark:bg-slate-900/40 rounded-b-xl">
            <a href="{{ route('admin.users.index') }}"
               class="text-sm text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200 flex items-center gap-1">
                <i class="ti ti-arrow-left"></i> Back to Users
            </a>
        </div>

    </div>
</div>

</x-layout>

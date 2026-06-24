<x-layout
    title="Activity Log"
    page-title="Activity Log"
    page-subtitle="Authenticated user actions — logins, uploads, and all mutations"
>

<x-slot:breadcrumb>
    <a href="{{ route('home') }}" class="hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">Home</a>
    <i class="ti ti-chevron-right text-xs"></i>
    <span>Activity Log</span>
</x-slot:breadcrumb>

{{-- Filters --}}
<form method="GET" action="{{ route('admin.activity.index') }}" class="mb-4 flex flex-wrap gap-3 items-end">
    <div class="flex flex-col gap-1">
        <label class="field-label">User</label>
        <select name="user_id" class="field-input py-1.5 pr-8 text-sm">
            <option value="">All users</option>
            @foreach($users as $u)
                <option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>
                    {{ $u->name }} ({{ $u->username }})
                </option>
            @endforeach
        </select>
    </div>
    <div class="flex flex-col gap-1">
        <label class="field-label">Action</label>
        <select name="action" class="field-input py-1.5 pr-8 text-sm">
            <option value="">All actions</option>
            @foreach($actions as $act)
                <option value="{{ $act }}" @selected(request('action') === $act)>
                    {{ $labels[$act] ?? $act }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="flex flex-col gap-1">
        <label class="field-label">IP Address</label>
        <input type="text" name="ip" value="{{ request('ip') }}"
               placeholder="e.g. 192.168.1.1"
               class="field-input py-1.5 text-sm w-44">
    </div>
    <div class="flex gap-2">
        <button type="submit"
                class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-filter text-base"></i> Filter
        </button>
        @if(request()->hasAny(['user_id', 'action', 'ip']))
        <a href="{{ route('admin.activity.index') }}"
           class="inline-flex items-center gap-1.5 bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-slate-200 text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-x text-base"></i> Clear
        </a>
        @endif
    </div>
</form>

{{-- Table --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">

    <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200">Log Entries</h3>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ number_format($logs->total()) }} total · newest first · 50 per page</p>
        </div>
        <i class="ti ti-shield-lock text-2xl text-slate-200 dark:text-slate-600"></i>
    </div>

    @if($logs->isEmpty())
    <div class="flex flex-col items-center justify-center py-16 text-center">
        <i class="ti ti-activity text-4xl text-slate-200 dark:text-slate-600 mb-3"></i>
        <p class="text-sm font-medium text-slate-500 dark:text-slate-400">No activity recorded yet</p>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Actions will appear here once users log in or make changes</p>
    </div>
    @else
    <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-slate-100 dark:border-slate-700 bg-slate-50 dark:bg-slate-900/40 text-left">
                    <th class="px-4 py-3 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Timestamp</th>
                    <th class="px-4 py-3 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">User</th>
                    <th class="px-4 py-3 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">Action</th>
                    <th class="px-4 py-3 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">IP Address</th>
                    <th class="px-4 py-3 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide">URL</th>
                    <th class="px-4 py-3 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide w-16 text-center">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                @foreach($logs as $log)
                @php
                    $meta   = $log->metadata ?? [];
                    $url    = $meta['url'] ?? '—';
                    $status = $meta['status'] ?? null;
                    $color  = $colors[$log->action] ?? $defaultColor;
                    $label  = $labels[$log->action] ?? $log->action;
                    $statusColor = match(true) {
                        $status >= 500 => 'text-red-600 dark:text-red-400',
                        $status >= 400 => 'text-amber-600 dark:text-amber-400',
                        $status >= 300 => 'text-sky-600 dark:text-sky-400',
                        $status >= 200 => 'text-green-600 dark:text-green-400',
                        default        => 'text-slate-400',
                    };
                @endphp
                <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
                    {{-- Timestamp --}}
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="text-slate-700 dark:text-slate-200 font-medium">
                            {{ $log->created_at->format('d M Y') }}
                        </span>
                        <span class="block text-xs text-slate-400 dark:text-slate-500 font-mono">
                            {{ $log->created_at->format('H:i:s') }}
                        </span>
                    </td>
                    {{-- User --}}
                    <td class="px-4 py-3 whitespace-nowrap">
                        @if($log->user)
                            <a href="{{ route('admin.users.show', $log->user) }}"
                               class="font-medium text-slate-700 dark:text-slate-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                                {{ $log->user->name }}
                            </a>
                            <span class="block text-xs text-slate-400 font-mono">{{ $log->user->username }}</span>
                        @else
                            <span class="text-slate-400 dark:text-slate-500 italic text-xs">Deleted user</span>
                        @endif
                    </td>
                    {{-- Action badge --}}
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $color }}">
                            {{ $label }}
                        </span>
                        @if($log->action === 'auth.login' && isset($meta['guard']))
                            <span class="block text-xs text-slate-400 mt-0.5">guard: {{ $meta['guard'] }}</span>
                        @endif
                    </td>
                    {{-- IP --}}
                    <td class="px-4 py-3 whitespace-nowrap">
                        <span class="font-mono text-xs text-slate-600 dark:text-slate-300 bg-slate-100 dark:bg-slate-700 px-2 py-0.5 rounded">
                            {{ $log->ip_address }}
                        </span>
                    </td>
                    {{-- URL --}}
                    <td class="px-4 py-3 max-w-xs">
                        <span class="font-mono text-xs text-slate-500 dark:text-slate-400 break-all leading-relaxed line-clamp-2"
                              title="{{ $url }}">
                            {{ $url }}
                        </span>
                    </td>
                    {{-- HTTP Status --}}
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        @if($status)
                            <span class="font-mono text-xs font-semibold {{ $statusColor }}">{{ $status }}</span>
                        @else
                            <span class="text-slate-300 dark:text-slate-600">—</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($logs->hasPages())
    <div class="px-5 py-4 border-t border-slate-100 dark:border-slate-700">
        {{ $logs->links() }}
    </div>
    @endif

    @endif
</div>

</x-layout>

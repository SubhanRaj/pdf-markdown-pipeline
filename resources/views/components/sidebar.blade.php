@php
    $sidebarDepts = \App\Models\Department::orderBy('level')->orderBy('name')->get(['name', 'slug', 'level']);

    // icon + color per slug; fallback for anything not listed
    $deptMeta = [
        'excise'           => ['icon' => 'ti-building-community', 'color' => 'text-amber-400'],
        'sugarcane_sugar'  => ['icon' => 'ti-leaf',               'color' => 'text-emerald-400'],
        'sugar_mill_corp'  => ['icon' => 'ti-building-factory',   'color' => 'text-cyan-400'],
        'cane_federation'  => ['icon' => 'ti-stack-2',            'color' => 'text-violet-400'],
        'sugarcane'        => ['icon' => 'ti-plant-2',            'color' => 'text-green-400'],
    ];
    $fallbackIcons  = ['ti-building', 'ti-folder', 'ti-archive', 'ti-files', 'ti-database'];
    $fallbackColors = ['text-sky-400', 'text-pink-400', 'text-orange-400', 'text-teal-400', 'text-lime-400'];
@endphp
<aside id="sidebar" class="sidebar-expanded bg-slate-950 flex flex-col flex-shrink-0 overflow-hidden">

    {{-- Logo --}}
    <div class="px-4 py-5 flex items-center gap-3 border-b border-slate-800/70 min-w-0">
        <div class="w-9 h-9 rounded-lg bg-indigo-600 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-file-text-ai text-white text-lg"></i>
        </div>
        <div class="sidebar-logo-text min-w-0">
            <p class="text-sm font-semibold text-white leading-tight">Document Vault</p>
            <p class="text-xs text-slate-500 truncate">UP Dept. of Excise</p>
        </div>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 px-2 py-3 space-y-0.5 overflow-y-auto">

        <a href="{{ route('home') }}"
           data-tooltip="Dashboard"
           class="nav-link {{ request()->routeIs('home') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-layout-dashboard w-5 text-center text-base flex-shrink-0"></i>
            <span class="sidebar-text">Dashboard</span>
        </a>

        <a href="{{ route('documents.index') }}"
           data-tooltip="All Documents"
           class="nav-link {{ request()->routeIs('documents.*') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-folder-open w-5 text-center text-base flex-shrink-0"></i>
            <span class="sidebar-text">All Documents</span>
        </a>

        <a href="{{ route('search.index') }}"
           data-tooltip="Search"
           class="nav-link {{ request()->routeIs('search.*') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-search w-5 text-center text-base flex-shrink-0"></i>
            <span class="sidebar-text">Search</span>
        </a>

        <span class="nav-section-label">Browse Vault</span>

        @forelse ($sidebarDepts as $i => $dept)
        @php
            $meta      = $deptMeta[$dept->slug] ?? [
                'icon'  => $fallbackIcons[$i % count($fallbackIcons)],
                'color' => $fallbackColors[$i % count($fallbackColors)],
            ];
            $isActive  = request()->routeIs('departments.show', 'departments.sections.*', 'documents.*')
                         && request()->route('department')?->is($dept);
        @endphp
        <a href="{{ route('departments.show', [$dept->levelAlias(), $dept]) }}"
           data-tooltip="{{ $dept->name }}"
           class="nav-link {{ $isActive ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti {{ $meta['icon'] }} w-5 text-center text-base flex-shrink-0 {{ $meta['color'] }}"></i>
            <span class="sidebar-text">{{ $dept->name }}</span>
        </a>
        @empty
        <a href="{{ route('departments.index') }}"
           data-tooltip="All Departments"
           class="nav-link nav-link-idle">
            <i class="ti ti-building w-5 text-center text-base flex-shrink-0"></i>
            <span class="sidebar-text">All Departments</span>
        </a>
        @endforelse

        <span class="nav-section-label">Tools</span>

        <span data-tooltip="Convert PDF — Coming soon" class="nav-link nav-link-idle opacity-60 cursor-not-allowed">
            <i class="ti ti-file-upload w-5 text-center text-base flex-shrink-0 text-indigo-400"></i>
            <span class="sidebar-text">Convert PDF</span>
            <span class="sidebar-badge ml-auto text-[10px] bg-indigo-900/60 text-indigo-400 px-1.5 py-0.5 rounded font-medium">Soon</span>
        </span>

        <span data-tooltip="Markdown Editor — Coming soon" class="nav-link nav-link-idle opacity-60 cursor-not-allowed">
            <i class="ti ti-markdown w-5 text-center text-base flex-shrink-0 text-sky-400"></i>
            <span class="sidebar-text">Markdown Editor</span>
            <span class="sidebar-badge ml-auto text-[10px] bg-indigo-900/60 text-indigo-400 px-1.5 py-0.5 rounded font-medium">Soon</span>
        </span>

        @guest
        <span class="nav-section-label">Departments</span>

        <a href="{{ route('departments.index') }}"
           data-tooltip="All Departments"
           class="nav-link {{ request()->routeIs('departments.*') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-building w-5 text-center text-base flex-shrink-0"></i>
            <span class="sidebar-text">All Departments</span>
        </a>
        @endguest

        @auth
        <span class="nav-section-label">Manage</span>

        <a href="{{ route('departments.index') }}"
           data-tooltip="Departments"
           class="nav-link {{ request()->routeIs('departments.*') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-building w-5 text-center text-base flex-shrink-0"></i>
            <span class="sidebar-text">Departments</span>
        </a>

        @if(auth()->user()->isAdmin())
        <a href="{{ route('admin.users.index') }}"
           data-tooltip="Users"
           class="nav-link {{ request()->routeIs('admin.users.*') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-users w-5 text-center text-base flex-shrink-0"></i>
            <span class="sidebar-text">Users</span>
        </a>
        @endif
        @endauth

    </nav>

    {{-- Collapse toggle --}}
    <div class="px-2 pb-2">
        <button
            id="sidebar-toggle"
            onclick="window.toggleSidebar()"
            data-tooltip="Expand sidebar"
            class="nav-link nav-link-idle w-full text-slate-500 hover:text-slate-300"
        >
            <i id="sidebar-toggle-icon" class="ti ti-layout-sidebar-left-collapse w-5 text-center text-base flex-shrink-0"></i>
            <span class="sidebar-text text-xs">Collapse</span>
        </button>
    </div>

    {{-- User profile / login --}}
    <div class="px-3 py-4 border-t border-slate-800/70 flex items-center gap-3 min-w-0">
        @auth
        <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
             data-tooltip="{{ auth()->user()->name }} · {{ ucfirst(auth()->user()->role) }}">
            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
        </div>
        <div class="sidebar-user-text flex-1 min-w-0">
            <p class="text-sm font-medium text-slate-200 truncate">{{ auth()->user()->name }}</p>
            <p class="text-xs text-slate-500 truncate">{{ ucfirst(auth()->user()->role) }}</p>
        </div>
        <form method="POST" action="{{ route('logout') }}" class="sidebar-user-text flex-shrink-0">
            @csrf
            <button type="submit"
                    data-tooltip="Log out"
                    class="text-slate-600 hover:text-slate-300 transition-colors"
                    title="Logout">
                <i class="ti ti-logout text-sm"></i>
            </button>
        </form>
        @else
        <div class="w-8 h-8 rounded-full bg-slate-700 flex items-center justify-center text-xs font-bold text-slate-400 flex-shrink-0"
             data-tooltip="Guest · Not signed in">
            G
        </div>
        <div class="sidebar-user-text flex-1 min-w-0">
            <p class="text-sm font-medium text-slate-400 truncate">Guest</p>
            <p class="text-xs text-slate-600 truncate">Visitor</p>
        </div>
        <a href="{{ route('login') }}"
           data-tooltip="Sign in"
           class="sidebar-user-text flex-shrink-0 text-slate-500 hover:text-slate-200 transition-colors">
            <i class="ti ti-login-2 text-xl"></i>
        </a>
        @endauth
    </div>

</aside>

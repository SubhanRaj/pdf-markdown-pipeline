<aside class="w-64 bg-slate-950 flex flex-col flex-shrink-0 overflow-y-auto">

    {{-- Logo / App identity --}}
    <div class="px-5 py-5 flex items-center gap-3 border-b border-slate-800/70">
        <div class="w-9 h-9 rounded-lg bg-indigo-600 flex items-center justify-center flex-shrink-0">
            <i class="ti ti-file-text-ai text-white text-lg"></i>
        </div>
        <div class="min-w-0">
            <p class="text-sm font-semibold text-white leading-tight">Document Vault</p>
            <p class="text-xs text-slate-500 truncate">UP Dept. of Excise</p>
        </div>
    </div>

    {{-- Navigation --}}
    <nav class="flex-1 px-3 py-3 space-y-0.5">

        <a href="{{ route('home') }}"
           class="nav-link {{ request()->routeIs('home') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-layout-dashboard w-4 text-center text-base"></i>
            <span>Dashboard</span>
        </a>

        <a href="{{ route('vault.documents.index') }}"
           class="nav-link {{ request()->routeIs('vault.documents.index') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-folder-open w-4 text-center text-base"></i>
            <span>All Documents</span>
        </a>

        {{-- Browse by Department --}}
        <span class="nav-section-label">Browse Vault</span>

        <a href="#" class="nav-link nav-link-idle">
            <i class="ti ti-building-community w-4 text-center text-base text-amber-400"></i>
            <span>Excise Department</span>
        </a>

        <a href="#" class="nav-link nav-link-idle">
            <i class="ti ti-leaf w-4 text-center text-base text-emerald-400"></i>
            <span>Sugarcane & Sugar</span>
        </a>

        <a href="#" class="nav-link nav-link-idle">
            <i class="ti ti-building-factory w-4 text-center text-base text-cyan-400"></i>
            <span>Sugar Mill Corp.</span>
        </a>

        <a href="#" class="nav-link nav-link-idle">
            <i class="ti ti-stack-2 w-4 text-center text-base text-violet-400"></i>
            <span>Cane Federation</span>
        </a>

        <a href="#" class="nav-link nav-link-idle">
            <i class="ti ti-building-arch w-4 text-center text-base text-rose-400"></i>
            <span>Secretariat</span>
        </a>

        {{-- Tools --}}
        <span class="nav-section-label">Tools</span>

        <a href="#" class="nav-link nav-link-idle">
            <i class="ti ti-file-upload w-4 text-center text-base text-indigo-400"></i>
            <span>Convert PDF</span>
            <span class="ml-auto text-[10px] bg-indigo-900/60 text-indigo-400 px-1.5 py-0.5 rounded font-medium">Soon</span>
        </a>

        <a href="#" class="nav-link nav-link-idle">
            <i class="ti ti-markdown w-4 text-center text-base text-sky-400"></i>
            <span>Markdown Editor</span>
            <span class="ml-auto text-[10px] bg-indigo-900/60 text-indigo-400 px-1.5 py-0.5 rounded font-medium">Soon</span>
        </a>

        {{-- Admin --}}
        <span class="nav-section-label">Admin</span>

        <a href="{{ route('vault.departments.index') }}"
           class="nav-link {{ request()->routeIs('vault.departments.*') ? 'nav-link-active' : 'nav-link-idle' }}">
            <i class="ti ti-building w-4 text-center text-base"></i>
            <span>Departments</span>
        </a>

    </nav>

    {{-- User profile bar --}}
    <div class="px-4 py-4 border-t border-slate-800/70 flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold text-white flex-shrink-0">
            SR
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-slate-200 truncate">Subhan Raj</p>
            <p class="text-xs text-slate-500 truncate">Administrator</p>
        </div>
        <button class="text-slate-600 hover:text-slate-300 transition-colors" title="Logout">
            <i class="ti ti-logout text-sm"></i>
        </button>
    </div>

</aside>

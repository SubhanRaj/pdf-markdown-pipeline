@props([
    'pageTitle'    => 'Dashboard',
    'pageSubtitle' => 'UP Department of Excise — Document Vault',
])

<header class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 px-3 sm:px-6 py-3.5 flex items-center justify-between flex-shrink-0 sticky top-0 z-30">
    <div class="flex items-center gap-3 min-w-0">
        {{-- Mobile drawer toggle — hidden at md and up, where the sidebar is always visible --}}
        <button
            onclick="window.toggleMobileSidebar()"
            class="md:hidden w-9 h-9 flex-shrink-0 flex items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
            title="Menu"
        >
            <i class="ti ti-menu-2 text-lg"></i>
        </button>

        <div class="min-w-0">
            {{-- line-clamp (not truncate) — document titles are often long legal names; a single-line
                 ellipsis clips them unreadably on a narrow phone screen, so wrap up to 2 lines instead. --}}
            <h1 class="text-base font-semibold text-slate-800 dark:text-slate-100 line-clamp-2 sm:truncate break-words">{{ $pageTitle }}</h1>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 truncate hidden sm:block">{{ $pageSubtitle }}</p>
        </div>
    </div>

    <div class="flex items-center gap-2 flex-shrink-0">
        {{-- Global search — hidden below sm; the sidebar's "Search" link covers that case --}}
        <form method="GET" action="{{ route('search.index') }}" role="search" class="relative hidden sm:block">
            <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
            <input
                type="search"
                name="q"
                value="{{ request('q') }}"
                placeholder="Search documents… / हिन्दी में खोजें"
                autocomplete="off"
                class="w-40 lg:w-52 pl-9 pr-3 py-2 text-sm bg-slate-100 dark:bg-slate-800 rounded-lg border-0 placeholder-slate-400 dark:placeholder-slate-500 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
        </form>

        {{-- Dark mode toggle --}}
        <button
            onclick="window.toggleDarkMode()"
            id="dark-mode-btn"
            class="w-9 h-9 flex-shrink-0 flex items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
            title="Toggle dark mode"
        >
            <i id="dark-mode-icon" class="ti ti-moon text-base"></i>
        </button>

        {{-- Pending-uploads indicator — files queued in this browser's IndexedDB (see
             public/js/resilient-upload.js) that haven't finished uploading yet, e.g. because
             the user navigated away mid-batch. Hidden until JS finds at least one. --}}
        @auth
        <a href="{{ route('documents.my-uploads.index') }}" id="pending-uploads-btn" style="display:none"
           class="relative w-9 h-9 flex-shrink-0 flex items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
           title="Uploads still in progress on this device">
            <i class="ti ti-cloud-upload text-base"></i>
            <span id="pending-uploads-count" class="absolute -top-1 -right-1 min-w-[16px] h-4 px-1 flex items-center justify-center rounded-full bg-amber-500 text-white text-[10px] font-bold leading-none"></span>
        </a>
        <script src="{{ asset('js/resilient-upload.js') }}"></script>
        <script>
        (function () {
            if (!window.ResilientUpload) return;
            ResilientUpload.allQueued().then(function (rows) {
                if (!rows.length) return;
                document.getElementById('pending-uploads-count').textContent = rows.length;
                document.getElementById('pending-uploads-btn').style.display = 'flex';
            });
        })();
        </script>
        @endauth

        {{-- New conversion CTA — standalone upload & convert, scoped to users who can upload anywhere --}}
        @auth
        @if(auth()->user()->uploadScope() !== 'none')
        <a href="{{ route('conversions.create') }}"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-3 sm:px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-plus text-base"></i>
            <span class="hidden sm:inline">New Conversion</span>
        </a>
        @endif
        @endauth
    </div>
</header>

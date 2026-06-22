@props([
    'pageTitle'    => 'Dashboard',
    'pageSubtitle' => 'UP Department of Excise — Document Vault',
])

<header class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-700 px-6 py-3.5 flex items-center justify-between flex-shrink-0 sticky top-0 z-30">
    <div>
        <h1 class="text-base font-semibold text-slate-800 dark:text-slate-100">{{ $pageTitle }}</h1>
        <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $pageSubtitle }}</p>
    </div>

    <div class="flex items-center gap-2">
        {{-- Global search --}}
        <form method="GET" action="{{ route('search.index') }}" role="search" class="relative">
            <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
            <input
                type="search"
                name="q"
                value="{{ request('q') }}"
                placeholder="Search documents…"
                autocomplete="off"
                class="w-52 pl-9 pr-3 py-2 text-sm bg-slate-100 dark:bg-slate-800 rounded-lg border-0 placeholder-slate-400 dark:placeholder-slate-500 text-slate-700 dark:text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
        </form>

        {{-- Dark mode toggle --}}
        <button
            onclick="window.toggleDarkMode()"
            id="dark-mode-btn"
            class="w-9 h-9 flex items-center justify-center rounded-lg text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 transition-colors"
            title="Toggle dark mode"
        >
            <i id="dark-mode-icon" class="ti ti-moon text-base"></i>
        </button>

        {{-- New conversion CTA --}}
        <a href="#"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-plus text-base"></i>
            New Conversion
        </a>
    </div>
</header>

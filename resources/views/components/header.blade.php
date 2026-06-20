@props([
    'pageTitle'    => 'Dashboard',
    'pageSubtitle' => 'UP Department of Excise — Document Vault',
])

<header class="bg-white border-b border-slate-200 px-6 py-3.5 flex items-center justify-between flex-shrink-0 sticky top-0 z-30">
    <div>
        <h1 class="text-base font-semibold text-slate-800">{{ $pageTitle }}</h1>
        <p class="text-xs text-slate-400 mt-0.5">{{ $pageSubtitle }}</p>
    </div>

    <div class="flex items-center gap-3">
        {{-- Global search --}}
        <div class="relative">
            <i class="ti ti-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
            <input
                type="search"
                placeholder="Search documents…"
                class="w-60 pl-9 pr-3 py-2 text-sm bg-slate-100 rounded-lg border-0 placeholder-slate-400 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
            >
        </div>

        {{-- Primary action --}}
        <a href="#"
           class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
            <i class="ti ti-plus text-base"></i>
            New Conversion
        </a>
    </div>
</header>

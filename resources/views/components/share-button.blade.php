{{--
    Share dropdown — WhatsApp / X / copy link, all pointing at the current page URL. Shared by
    document show pages and, since 2026-09-02, department/section/division/folder show pages too.
    Purely client-side (Alpine, self-hosted — see components/head.blade.php); no controller
    action needed, since it only ever shares the URL of the page it's already rendered on —
    a viewer who can see this button can already see (and copy) that URL from the address bar,
    so this adds convenience, not new exposure, regardless of the container's own visibility.
--}}
@props(['title'])
<div class="relative flex-1 sm:flex-none min-w-[5rem] sm:min-w-0" x-data="{ shareOpen: false, copied: false }" @keydown.escape.window="shareOpen = false">
    <button type="button" title="Share" @click="shareOpen = !shareOpen"
            class="w-full inline-flex items-center justify-center gap-1.5 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-3 py-2 rounded-lg transition-all">
        <i class="ti ti-share-2 text-base"></i>
        <span class="hidden sm:inline">Share</span>
    </button>
    {{-- Anchored left on mobile (button sits at the left edge of a full-width row, so a
         right-anchored panel would overflow past the left edge of the viewport) and right on
         desktop (button sits at the far right of the header row). --}}
    <div x-show="shareOpen" x-cloak @click.outside="shareOpen = false"
         class="absolute left-0 sm:left-auto sm:right-0 top-full mt-2 w-44 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg shadow-lg py-1 z-20">
        <a href="https://wa.me/?text={{ urlencode($title . ' — ' . url()->current()) }}" target="_blank" rel="noopener"
           class="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
            <i class="ti ti-brand-whatsapp text-base text-green-500"></i> WhatsApp
        </a>
        <a href="https://twitter.com/intent/tweet?text={{ urlencode($title) }}&url={{ urlencode(url()->current()) }}" target="_blank" rel="noopener"
           class="flex items-center gap-2 px-3 py-2 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
            <i class="ti ti-brand-x text-base"></i> X
        </a>
        <button type="button"
                @click="
                    navigator.clipboard.writeText('{{ url()->current() }}');
                    copied = true;
                    setTimeout(() => { copied = false; shareOpen = false }, 1500);
                "
                class="w-full flex items-center gap-2 px-3 py-2 text-sm text-slate-600 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors">
            <i class="ti text-base" :class="copied ? 'ti-check' : 'ti-link'"></i>
            <span x-text="copied ? 'Copied!' : 'Copy link'"></span>
        </button>
    </div>
</div>

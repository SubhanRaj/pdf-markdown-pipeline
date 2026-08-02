@props([
    'title'        => 'Dashboard',
    'pageTitle'    => 'Dashboard',
    'pageSubtitle' => 'UP Department of Excise — Document Vault',
    'description'  => null,
    'image'        => null,
    'ogUrl'        => null,
    'ogType'       => 'website',
    'noindex'      => false,
    // Opt-in for pages that need to fill exactly the space below the header (a
    // side-by-side compare pane whose action bar must never scroll off-screen) instead
    // of growing with their content — sidebar/header stay put either way, only the
    // <main> sizing and the footer (dropped, it'd fight for the same vertical space)
    // change. Every other page is unaffected — default false.
    'fullHeight'   => false,
])

<!DOCTYPE html>
<html lang="en" class="h-full">

<x-head :title="$title" :description="$description ?? $pageSubtitle" :image="$image" :url="$ogUrl" :type="$ogType" :noindex="$noindex" />

<body class="bg-slate-100 dark:bg-slate-950 h-full transition-colors duration-200">
<div class="flex h-screen overflow-hidden">

    <x-sidebar />

    {{-- Mobile sidebar backdrop — only ever shown below md, dismisses the drawer on tap --}}
    <div id="sidebar-backdrop" onclick="window.toggleMobileSidebar()"
         class="fixed inset-0 bg-black/50 z-40 hidden md:hidden"></div>

    <div class="flex-1 flex flex-col min-w-0 {{ $fullHeight ? 'overflow-hidden' : 'overflow-y-auto' }}">

        <x-header :page-title="$pageTitle" :page-subtitle="$pageSubtitle" />

        <main class="flex-1 p-3 sm:p-6 {{ $fullHeight ? 'min-h-0 flex flex-col overflow-hidden' : '' }}">
            {{ $slot }}
        </main>

        @unless($fullHeight)
        <x-footer />
        @endunless

    </div>
</div>

{{-- Sidebar tooltip bubble — positioned by JS, escapes overflow clipping --}}
<div id="nav-tooltip-bubble"
     style="display:none;position:fixed;z-index:9999;pointer-events:none;transform:translateY(-50%)"
     class="px-2.5 py-1.5 text-xs font-medium text-slate-100 bg-slate-800 rounded-md shadow-lg whitespace-nowrap">
</div>

@flasher_render

@stack('scripts')

<script>
// ── Dark mode ────────────────────────────────────────────────────────────────
window.toggleDarkMode = function () {
    const isDark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('color_scheme', isDark ? 'dark' : 'light');
    updateDarkIcon();
};

function updateDarkIcon() {
    const icon = document.getElementById('dark-mode-icon');
    if (!icon) return;
    const isDark = document.documentElement.classList.contains('dark');
    icon.className = isDark ? 'ti ti-sun text-base' : 'ti ti-moon text-base';
}

// ── Mobile sidebar drawer (off-canvas below md) ─────────────────────────────
window.toggleMobileSidebar = function () {
    const sidebar  = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    sidebar.classList.toggle('-translate-x-full');
    sidebar.classList.toggle('translate-x-0');
    backdrop.classList.toggle('hidden');
};

// ── Sidebar collapse ─────────────────────────────────────────────────────────
window.toggleSidebar = function () {
    const sidebar   = document.getElementById('sidebar');
    const collapsed = sidebar.classList.contains('sidebar-collapsed');
    sidebar.classList.toggle('sidebar-collapsed', !collapsed);
    sidebar.classList.toggle('sidebar-expanded',   collapsed);
    localStorage.setItem('sidebar_collapsed', collapsed ? '0' : '1');
    updateSidebarIcon();
    updateToggleTooltip(!collapsed);
    hideTooltip();
};

function updateSidebarIcon() {
    const icon    = document.getElementById('sidebar-toggle-icon');
    const sidebar = document.getElementById('sidebar');
    if (!icon) return;
    const collapsed = sidebar.classList.contains('sidebar-collapsed');
    icon.className  = collapsed
        ? 'ti ti-layout-sidebar-left-expand w-5 text-center text-base flex-shrink-0'
        : 'ti ti-layout-sidebar-left-collapse w-5 text-center text-base flex-shrink-0';
}

function updateToggleTooltip(collapsed) {
    const btn = document.getElementById('sidebar-toggle');
    if (btn) btn.dataset.tooltip = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
}

// ── Sidebar tooltips (fixed-position, escapes overflow clipping) ──────────────
const tooltipEl = document.getElementById('nav-tooltip-bubble');

function showTooltip(el) {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar || !sidebar.classList.contains('sidebar-collapsed')) return;
    const label = el.dataset.tooltip;
    if (!label) return;
    const rect = el.getBoundingClientRect();
    tooltipEl.textContent = label;
    tooltipEl.style.left  = (rect.right + 10) + 'px';
    tooltipEl.style.top   = (rect.top + rect.height / 2) + 'px';
    tooltipEl.style.display = 'block';
}

function hideTooltip() {
    if (tooltipEl) tooltipEl.style.display = 'none';
}

function initTooltips() {
    document.querySelectorAll('#sidebar [data-tooltip]').forEach(function (el) {
        el.addEventListener('mouseenter', function () { showTooltip(el); });
        el.addEventListener('mouseleave', hideTooltip);
        el.addEventListener('click',      hideTooltip);
    });
}

// ── Init on load ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    if (localStorage.getItem('sidebar_collapsed') === '1') {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.classList.remove('sidebar-expanded');
            sidebar.classList.add('sidebar-collapsed');
        }
    }
    updateSidebarIcon();
    updateDarkIcon();
    initTooltips();

    // Auto-close the mobile drawer after navigating, so it doesn't stay open on the next page.
    document.querySelectorAll('#sidebar a, #sidebar button[type="submit"]').forEach(function (el) {
        el.addEventListener('click', function () {
            if (window.innerWidth < 768) window.toggleMobileSidebar();
        });
    });
});
</script>

</body>
</html>

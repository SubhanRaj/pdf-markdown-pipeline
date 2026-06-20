@props([
    'title'        => 'Dashboard',
    'pageTitle'    => 'Dashboard',
    'pageSubtitle' => 'UP Department of Excise — Document Vault',
])

<!DOCTYPE html>
<html lang="en" class="h-full">

<x-head :title="$title" />

<body class="bg-slate-100 dark:bg-slate-950 h-full transition-colors duration-200">
<div class="flex h-screen overflow-hidden">

    <x-sidebar />

    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">

        <x-header :page-title="$pageTitle" :page-subtitle="$pageSubtitle" />

        @if(isset($breadcrumb) && $breadcrumb->isNotEmpty())
        <div class="px-6 pt-4">
            <nav class="flex items-center gap-1.5 text-xs text-slate-400 dark:text-slate-500">
                {{ $breadcrumb }}
            </nav>
        </div>
        @endif

        <main class="flex-1 p-6">
            {{ $slot }}
        </main>

        <x-footer />

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
});
</script>

</body>
</html>

@props(['title' => 'Dashboard'])

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} | {{ config('app.name') }}</title>

    {{-- Anti-flash: runs synchronously before paint to prevent theme flicker --}}
    <script>
        (function () {
            const stored = localStorage.getItem('color_scheme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (stored === 'dark' || (!stored && prefersDark)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>

    {{-- Google Fonts: Inter --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    {{-- Tailwind CSS Play CDN (typography plugin powers the `prose` classes used to render Markdown) --}}
    <script src="https://cdn.tailwindcss.com?plugins=typography"></script>

    {{-- Tabler Icons webfont, self-hosted (public/vendor/tabler-icons) — CDN was flaky on some users' networks --}}
    <link rel="stylesheet" href="{{ asset('vendor/tabler-icons/tabler-icons.min.css') }}">

    {{-- Chart.js via jsDelivr --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

    {{-- SweetAlert2 via jsDelivr --}}
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                }
            }
        }
    </script>

    <style type="text/tailwindcss">
        body { font-family: 'Inter', system-ui, sans-serif; }

        /* ── Sidebar nav ─────────────────────────────────────────────────── */
        .nav-link {
            @apply flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors duration-150 cursor-pointer;
        }
        .nav-link-active { @apply bg-indigo-600 text-white font-medium; }
        .nav-link-idle   { @apply text-slate-400 hover:bg-slate-800/70 hover:text-slate-100; }
        .nav-section-label {
            @apply text-[10px] font-semibold uppercase tracking-widest text-slate-600 px-3 pt-5 pb-1.5 block;
        }

        /* ── Cards / badges ──────────────────────────────────────────────── */
        .stat-card {
            @apply bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 flex items-start gap-4;
        }
        .stat-icon { @apply w-11 h-11 rounded-lg flex items-center justify-center flex-shrink-0 text-xl; }
        .badge     { @apply inline-flex items-center px-2 py-0.5 rounded text-xs font-medium; }

        /* ── Form fields (used on admin pages) ───────────────────────────── */
        .field-label   { @apply block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1.5 uppercase tracking-wide; }
        .field-input   { @apply w-full px-3 py-2.5 text-sm bg-slate-50 dark:bg-slate-900 border border-slate-200 dark:border-slate-700 rounded-lg text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition; }
        .field-error   { @apply !border-red-400 !bg-red-50 dark:!bg-red-950/40 focus:!ring-red-400; }
        .field-valid   { @apply !border-emerald-400 !bg-emerald-50 dark:!bg-emerald-950/30; }
        .field-hint    { @apply text-xs text-slate-400 dark:text-slate-500 mt-1; }
        .field-err-msg { @apply text-xs text-red-600 dark:text-red-400 mt-1; }

        /* ── Sidebar collapse ────────────────────────────────────────────── */
        #sidebar { transition: width 280ms cubic-bezier(0.4, 0, 0.2, 1); }
        #sidebar.sidebar-expanded  { width: 16rem; }
        #sidebar.sidebar-collapsed { width: 4rem; }

        /* Hide labels / badges / section headers when collapsed */
        #sidebar.sidebar-collapsed .sidebar-text,
        #sidebar.sidebar-collapsed .nav-section-label,
        #sidebar.sidebar-collapsed .sidebar-badge,
        #sidebar.sidebar-collapsed .sidebar-logo-text,
        #sidebar.sidebar-collapsed .sidebar-user-text { display: none; }

        /* Centre icons when collapsed */
        #sidebar.sidebar-collapsed .nav-link {
            justify-content: center;
            padding-left: 0;
            padding-right: 0;
        }
    </style>

    @stack('styles')
</head>

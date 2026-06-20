@props(['title' => 'Dashboard'])

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} | {{ config('app.name') }}</title>

    {{-- Google Fonts: Inter --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    {{-- Tailwind CSS Play CDN --}}
    <script src="https://cdn.tailwindcss.com"></script>

    {{-- Tabler Icons webfont via jsDelivr --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@3.30.0/dist/tabler-icons.min.css">

    {{-- Chart.js via jsDelivr --}}
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                }
            }
        }
    </script>

    <style type="text/tailwindcss">
        body { font-family: 'Inter', system-ui, sans-serif; }

        .nav-link {
            @apply flex items-center gap-3 px-3 py-2 rounded-lg text-sm transition-colors duration-150 cursor-pointer;
        }
        .nav-link-active  { @apply bg-indigo-600 text-white font-medium; }
        .nav-link-idle    { @apply text-slate-400 hover:bg-slate-800/70 hover:text-slate-100; }

        .nav-section-label {
            @apply text-[10px] font-semibold uppercase tracking-widest text-slate-600 px-3 pt-5 pb-1.5 block;
        }

        .stat-card  { @apply bg-white rounded-xl border border-slate-200 p-5 flex items-start gap-4; }
        .stat-icon  { @apply w-11 h-11 rounded-lg flex items-center justify-center flex-shrink-0 text-xl; }
        .badge      { @apply inline-flex items-center px-2 py-0.5 rounded text-xs font-medium; }
    </style>

    @stack('styles')
</head>

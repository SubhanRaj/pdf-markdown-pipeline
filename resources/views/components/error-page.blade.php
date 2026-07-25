@props(['icon', 'heading', 'message', 'title' => null, 'primaryLabel' => 'Go to Dashboard', 'primaryRoute' => 'home', 'primaryIcon' => 'ti-home', 'secondaryLabel' => 'Search Documents', 'secondaryRoute' => 'search.index'])

<!DOCTYPE html>
<html lang="en" class="h-full">

<x-head :title="$title ?? $heading" :description="$message" :noindex="true" />

<body class="bg-slate-100 dark:bg-slate-950 h-full transition-colors duration-200">
<div class="min-h-screen flex items-center justify-center px-6">
    <div class="max-w-md w-full text-center">
        <div class="w-16 h-16 mx-auto mb-6 rounded-2xl bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center">
            <i class="ti {{ $icon }} text-3xl text-indigo-500 dark:text-indigo-400"></i>
        </div>
        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100 mb-2">{{ $heading }}</h1>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-8">{{ $message }}</p>
        <div class="flex items-center justify-center gap-3">
            @if($primaryRoute)
            <a href="{{ route($primaryRoute) }}"
               class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition-colors">
                <i class="ti {{ $primaryIcon }} text-base"></i> {{ $primaryLabel }}
            </a>
            @endif
            @if($secondaryRoute)
            <a href="{{ route($secondaryRoute) }}"
               class="inline-flex items-center gap-2 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 hover:border-indigo-400 dark:hover:border-indigo-500 text-slate-600 dark:text-slate-300 hover:text-indigo-600 dark:hover:text-indigo-400 text-sm font-medium px-4 py-2.5 rounded-lg transition-colors">
                <i class="ti ti-search text-base"></i> {{ $secondaryLabel }}
            </a>
            @endif
        </div>
    </div>
</div>

</body>
</html>

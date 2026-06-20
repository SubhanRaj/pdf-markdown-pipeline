@props([
    'title'        => 'Dashboard',
    'pageTitle'    => 'Dashboard',
    'pageSubtitle' => 'UP Department of Excise — Document Vault',
])

<!DOCTYPE html>
<html lang="en" class="h-full">

<x-head :title="$title" />

<body class="bg-slate-100 h-full">
<div class="flex h-screen overflow-hidden">

    <x-sidebar />

    <div class="flex-1 flex flex-col min-w-0 overflow-y-auto">

        <x-header :page-title="$pageTitle" :page-subtitle="$pageSubtitle" />

        {{-- Optional breadcrumb slot --}}
        @if(isset($breadcrumb) && $breadcrumb->isNotEmpty())
        <div class="px-6 pt-4">
            <nav class="flex items-center gap-1.5 text-xs text-slate-400">
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

@stack('scripts')
</body>
</html>

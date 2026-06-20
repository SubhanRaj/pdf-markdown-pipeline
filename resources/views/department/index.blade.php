<x-layout
    title="Departments"
    page-title="Departments"
    page-subtitle="Browse vault by department and level"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'Departments', 'url' => null],
]" />

@php
    $levelGroups = $departments->groupBy('level');
    $levelMeta = [
        'secretariat_level' => [
            'label' => 'Secretariat Level',
            'icon'  => 'ti-building-arch',
            'color' => 'text-violet-400',
            'bg'    => 'bg-violet-500/10 dark:bg-violet-500/20',
        ],
        'department_level' => [
            'label' => 'Department Level',
            'icon'  => 'ti-building-community',
            'color' => 'text-amber-400',
            'bg'    => 'bg-amber-500/10 dark:bg-amber-500/20',
        ],
    ];
    $deptColors = [
        0 => ['icon' => 'text-amber-400',  'bg' => 'bg-amber-500/10 dark:bg-amber-500/20'],
        1 => ['icon' => 'text-emerald-400', 'bg' => 'bg-emerald-500/10 dark:bg-emerald-500/20'],
        2 => ['icon' => 'text-cyan-400',    'bg' => 'bg-cyan-500/10 dark:bg-cyan-500/20'],
        3 => ['icon' => 'text-violet-400',  'bg' => 'bg-violet-500/10 dark:bg-violet-500/20'],
        4 => ['icon' => 'text-rose-400',    'bg' => 'bg-rose-500/10 dark:bg-rose-500/20'],
        5 => ['icon' => 'text-sky-400',     'bg' => 'bg-sky-500/10 dark:bg-sky-500/20'],
    ];
@endphp

@if($departments->isEmpty())

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col items-center justify-center py-20 text-center">
    <div class="w-14 h-14 rounded-xl bg-slate-100 dark:bg-slate-700 flex items-center justify-center mb-4">
        <i class="ti ti-building text-2xl text-slate-400 dark:text-slate-500"></i>
    </div>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-300">No departments configured yet</p>
    <p class="text-xs text-slate-400 dark:text-slate-500 mt-1">Run the database seeder to load the standard vault structure.</p>
    @auth @if(auth()->user()->isAdmin())
    <a href="{{ route('departments.create') }}"
       class="mt-4 inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors">
        <i class="ti ti-plus text-base"></i> Add Department
    </a>
    @endif @endauth
</div>

@else

@foreach($levelGroups as $level => $depts)
@php $meta = $levelMeta[$level] ?? ['label' => ucfirst($level), 'icon' => 'ti-building', 'color' => 'text-slate-400', 'bg' => 'bg-slate-500/10']; @endphp

<div class="mb-8">

    {{-- Level heading --}}
    <div class="flex items-center gap-3 mb-4">
        <div class="w-8 h-8 rounded-lg {{ $meta['bg'] }} flex items-center justify-center">
            <i class="ti {{ $meta['icon'] }} {{ $meta['color'] }} text-base"></i>
        </div>
        <div>
            <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-200">{{ $meta['label'] }}</h2>
            <p class="text-xs text-slate-400 dark:text-slate-500">{{ $depts->count() }} {{ Str::plural('department', $depts->count()) }}</p>
        </div>
    </div>

    {{-- Department cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        @foreach($depts as $i => $dept)
        @php $c = $deptColors[$i % count($deptColors)]; @endphp

        <a href="{{ route('departments.show', $dept) }}"
           class="group bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-indigo-300 dark:hover:border-indigo-600 p-5 flex flex-col gap-4 transition-all hover:shadow-md">

            {{-- Icon --}}
            <div class="w-10 h-10 rounded-lg {{ $c['bg'] }} flex items-center justify-center">
                <i class="ti ti-building {{ $c['icon'] }} text-lg"></i>
            </div>

            {{-- Name + slug --}}
            <div class="flex-1 min-w-0">
                <p class="font-semibold text-slate-800 dark:text-slate-100 leading-snug group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors">
                    {{ $dept->name }}
                </p>
                <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 font-mono">{{ $dept->slug }}</p>
            </div>

            {{-- Stats row --}}
            <div class="flex items-center justify-between pt-3 border-t border-slate-100 dark:border-slate-700 text-xs text-slate-500 dark:text-slate-400">
                <span class="flex items-center gap-1">
                    <i class="ti ti-files text-slate-400 dark:text-slate-500"></i>
                    {{ $dept->documents_count }} {{ Str::plural('doc', $dept->documents_count) }}
                </span>
                <span class="flex items-center gap-1">
                    <i class="ti ti-layout-list text-slate-400 dark:text-slate-500"></i>
                    {{ $dept->sections_count }} {{ Str::plural('section', $dept->sections_count) }}
                </span>
                <span class="text-slate-300 dark:text-slate-600 group-hover:text-indigo-400 transition-colors">
                    <i class="ti ti-arrow-right text-sm"></i>
                </span>
            </div>

        </a>
        @endforeach

        {{-- Add department card (admin only) --}}
        @auth @if(auth()->user()->isAdmin())
        <a href="{{ route('departments.create') }}"
           class="bg-slate-50 dark:bg-slate-800/50 rounded-xl border border-dashed border-slate-300 dark:border-slate-600 hover:border-indigo-400 dark:hover:border-indigo-500 p-5 flex flex-col items-center justify-center gap-2 text-slate-400 dark:text-slate-500 hover:text-indigo-500 dark:hover:text-indigo-400 transition-all min-h-[140px]">
            <i class="ti ti-plus text-xl"></i>
            <span class="text-xs font-medium">Add Department</span>
        </a>
        @endif @endauth

    </div>
</div>
@endforeach

@endif

</x-layout>

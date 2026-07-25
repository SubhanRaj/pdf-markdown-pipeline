<x-layout
    title="Pipeline Health"
    page-title="Pipeline Health"
    page-subtitle="Queue worker and server vitals — refreshes every 15s"
>

<meta http-equiv="refresh" content="15">

<x-breadcrumb :items="[
    ['name' => 'Home',                'url' => route('home')],
    ['name' => 'Conversion Pipeline', 'url' => route('documents.pipeline')],
    ['name' => 'Health',              'url' => null],
]" />

@php
    $isHealthy = $status === 'healthy';
    $temp = $server['cpu_temp_c'] ?? null;
    $tempColor = $temp === null ? 'slate' : ($temp >= 90 ? 'red' : ($temp >= 80 ? 'amber' : 'emerald'));
    $memUsedPct = ($server['memory_total_mb'] ?? 0) > 0
        ? round((1 - ($server['memory_available_mb'] / $server['memory_total_mb'])) * 100)
        : null;
    $memColor = $memUsedPct === null ? 'slate' : ($memUsedPct >= 90 ? 'red' : ($memUsedPct >= 75 ? 'amber' : 'emerald'));
    $loadPerCore = ($server['cpu_count'] ?? 0) > 0 ? $server['load_avg_1m'] / $server['cpu_count'] : null;
    $loadColor = $loadPerCore === null ? 'slate' : ($loadPerCore >= 1 ? 'red' : ($loadPerCore >= 0.75 ? 'amber' : 'emerald'));
    $colorClasses = [
        'emerald' => 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300',
        'amber'   => 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300',
        'red'     => 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-700 dark:text-red-300',
        'slate'   => 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400',
    ];
@endphp

{{-- ── Overall status banner ─────────────────────────────────────────────────── --}}
<div class="mb-6 flex items-center gap-3 px-5 py-4 rounded-xl border {{ $isHealthy ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' }}">
    <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 {{ $isHealthy ? 'bg-emerald-100 dark:bg-emerald-900/40' : 'bg-red-100 dark:bg-red-900/40' }}">
        <i class="ti {{ $isHealthy ? 'ti-circle-check text-emerald-600 dark:text-emerald-400' : 'ti-alert-triangle text-red-600 dark:text-red-400' }} text-xl"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold {{ $isHealthy ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300' }}">
            {{ $isHealthy ? 'Queue worker is healthy' : 'Queue worker looks stalled' }}
        </p>
        <p class="text-xs {{ $isHealthy ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }} mt-0.5">
            @if($last_job_activity)
                Last job activity {{ $last_activity_ago }} ({{ $last_job_activity->format('d M Y, H:i:s') }})
            @else
                No job activity recorded yet.
            @endif
            @if(! $isHealthy)
                — {{ $pending_jobs }} jobs queued but nothing has moved in over 15 minutes.
            @endif
        </p>
    </div>
    <a href="{{ route('documents.pipeline.health', ['format' => 'json']) }}"
       class="flex-shrink-0 text-xs text-slate-500 dark:text-slate-400 hover:text-indigo-600 dark:hover:text-indigo-400 hover:underline flex items-center gap-1">
        <i class="ti ti-braces text-sm"></i> Raw JSON
    </a>
</div>

{{-- ── Queue stats ───────────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="stat-card">
        <div class="stat-icon bg-blue-50 dark:bg-blue-900/30 flex-shrink-0">
            <i class="ti ti-list-numbers text-blue-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ number_format($pending_jobs) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">Jobs queued</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-red-50 dark:bg-red-900/30 flex-shrink-0">
            <i class="ti ti-alert-circle text-red-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ number_format($failed_jobs) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">Failed jobs</p>
        </div>
    </div>
    @foreach(['processing' => ['ti-loader-2', 'blue'], 'ocr_pending' => ['ti-scan', 'amber']] as $key => [$icon, $color])
    <div class="stat-card">
        <div class="stat-icon bg-{{ $color }}-50 dark:bg-{{ $color }}-900/30 flex-shrink-0">
            <i class="ti {{ $icon }} text-{{ $color }}-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ number_format($documents[$key] ?? 0) }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ \App\Models\Document::STATUSES[$key]['label'] }}</p>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Document status breakdown ─────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 mb-6">
    <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between">
        <span class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Pipeline breakdown</span>
        <a href="{{ route('documents.pipeline') }}" class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1">
            View table <i class="ti ti-arrow-right text-xs"></i>
        </a>
    </div>
    <div class="px-5 py-4 flex flex-wrap gap-2">
        @foreach($pipelineStatuses as $s)
        @php $meta = \App\Models\Document::STATUSES[$s]; @endphp
        <span class="badge {{ ['slate'=>'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400','blue'=>'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400','amber'=>'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400','indigo'=>'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400','red'=>'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400'][$meta['color']] }}">
            {{ $meta['label'] }}: {{ number_format($documents[$s] ?? 0) }}
        </span>
        @endforeach
    </div>
</div>

{{-- ── Server vitals ─────────────────────────────────────────────────────────── --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700">
        <span class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">Server vitals</span>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 px-5 py-4">
        <div class="rounded-lg border px-4 py-3 {{ $colorClasses[$loadColor] }}">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">Load average</p>
            <p class="text-xl font-bold mt-1">{{ $server['load_avg_1m'] !== null ? number_format($server['load_avg_1m'], 2) : '—' }}</p>
            <p class="text-xs mt-0.5 opacity-80">
                5m {{ $server['load_avg_5m'] !== null ? number_format($server['load_avg_5m'], 2) : '—' }} ·
                15m {{ $server['load_avg_15m'] !== null ? number_format($server['load_avg_15m'], 2) : '—' }}
                @if($server['cpu_count']) · {{ $server['cpu_count'] }} cores @endif
            </p>
        </div>
        <div class="rounded-lg border px-4 py-3 {{ $colorClasses[$memColor] }}">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">Memory</p>
            <p class="text-xl font-bold mt-1">{{ $memUsedPct !== null ? "{$memUsedPct}%" : '—' }} used</p>
            <p class="text-xs mt-0.5 opacity-80">
                @if($server['memory_available_mb'] && $server['memory_total_mb'])
                    {{ number_format($server['memory_total_mb'] - $server['memory_available_mb']) }} / {{ number_format($server['memory_total_mb']) }} MB
                @else — @endif
            </p>
        </div>
        <div class="rounded-lg border px-4 py-3 {{ $colorClasses[$tempColor] }}">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">CPU temperature</p>
            <p class="text-xl font-bold mt-1">{{ $temp !== null ? number_format($temp, 1) . '°C' : '—' }}</p>
            <p class="text-xs mt-0.5 opacity-80">
                @if($temp === null) Not available
                @elseif($temp >= 90) High — consider reducing concurrent workers
                @elseif($temp >= 80) Elevated
                @else Normal @endif
            </p>
        </div>
    </div>
</div>

<p class="text-xs text-slate-400 dark:text-slate-500 mt-4 text-center">
    Checked {{ $checked_at->format('d M Y, H:i:s') }} · auto-refreshes every 15s
</p>

</x-layout>

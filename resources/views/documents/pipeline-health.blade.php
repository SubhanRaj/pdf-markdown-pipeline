<x-layout
    title="Pipeline Health"
    page-title="Pipeline Health"
    page-subtitle="Queue worker and server vitals — refreshes every 15s"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                'url' => route('home')],
    ['name' => 'Conversion Pipeline', 'url' => route('documents.pipeline')],
    ['name' => 'Health',              'url' => null],
]" />

@php
    $colorClasses = [
        'emerald' => 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300',
        'amber'   => 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-300',
        'red'     => 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-700 dark:text-red-300',
        'slate'   => 'bg-slate-50 dark:bg-slate-800 border-slate-200 dark:border-slate-700 text-slate-500 dark:text-slate-400',
    ];
    $badgeColors = [
        'slate'  => 'bg-slate-100 dark:bg-slate-700 text-slate-500 dark:text-slate-400',
        'blue'   => 'bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400',
        'amber'  => 'bg-amber-100 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400',
        'indigo' => 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-400',
        'red'    => 'bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400',
    ];
    // @json() splits its argument on top-level commas (Illuminate\View\Compilers\Concerns\CompilesJson
    // just does explode(',', ...)) — an inline multi-key array literal breaks it, so build the
    // array as its own variable first and pass a single bare reference to @json() below.
    $healthData = [
        'status' => $status, 'pending_jobs' => $pending_jobs, 'failed_jobs' => $failed_jobs,
        'documents' => $documents, 'server' => $server, 'log_signals' => $log_signals,
        'last_activity_ago' => $last_activity_ago,
        'last_job_activity' => $last_job_activity?->format('d M Y, H:i:s'),
        'checked_at' => $checked_at->format('d M Y, H:i:s'),
    ];
@endphp

{{-- Everything below is seeded from the initial server render (so it works on first paint even
     before Alpine finishes loading) and then kept live by Alpine polling this same page's own
     ?format=json endpoint every 15s — replaces the old <meta http-equiv="refresh"> which did a
     full page reload (felt like a "reboot") just to show updated numbers. --}}
<div x-data="pipelineHealthState(@json($healthData))" x-init="start()">

{{-- ── Overall status banner ─────────────────────────────────────────────────── --}}
<div class="mb-6 flex items-center gap-3 px-5 py-4 rounded-xl border" :class="isHealthy ? 'bg-emerald-50 dark:bg-emerald-900/20 border-emerald-200 dark:border-emerald-800' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800'">
    <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0" :class="isHealthy ? 'bg-emerald-100 dark:bg-emerald-900/40' : 'bg-red-100 dark:bg-red-900/40'">
        <i class="ti text-xl" :class="isHealthy ? 'ti-circle-check text-emerald-600 dark:text-emerald-400' : 'ti-alert-triangle text-red-600 dark:text-red-400'"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="text-sm font-semibold" :class="isHealthy ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-700 dark:text-red-300'" x-text="isHealthy ? 'Queue worker is healthy' : 'Queue worker looks stalled'"></p>
        <p class="text-xs mt-0.5" :class="isHealthy ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
            <template x-if="data.last_job_activity"><span x-text="'Last job activity ' + data.last_activity_ago + ' (' + data.last_job_activity + ')'"></span></template>
            <template x-if="!data.last_job_activity"><span>No job activity recorded yet.</span></template>
            <template x-if="!isHealthy"><span x-text="' — ' + data.pending_jobs + ' jobs queued but nothing has moved in over 15 minutes.'"></span></template>
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
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100" x-text="data.pending_jobs.toLocaleString()"></p>
            <p class="text-xs text-slate-500 dark:text-slate-400">Jobs queued</p>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon bg-red-50 dark:bg-red-900/30 flex-shrink-0">
            <i class="ti ti-alert-circle text-red-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100" x-text="data.failed_jobs.toLocaleString()"></p>
            <p class="text-xs text-slate-500 dark:text-slate-400">Failed jobs</p>
        </div>
    </div>
    @foreach(['processing' => ['ti-loader-2', 'blue'], 'ocr_pending' => ['ti-scan', 'amber']] as $key => [$icon, $color])
    <div class="stat-card">
        <div class="stat-icon bg-{{ $color }}-50 dark:bg-{{ $color }}-900/30 flex-shrink-0">
            <i class="ti {{ $icon }} text-{{ $color }}-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100" x-text="(data.documents['{{ $key }}'] ?? 0).toLocaleString()"></p>
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
        <span class="badge {{ $badgeColors[$meta['color']] }}">
            {{ $meta['label'] }}: <span x-text="(data.documents['{{ $s }}'] ?? 0).toLocaleString()"></span>
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
        <div class="rounded-lg border px-4 py-3" :class="colorClasses[loadColor()]">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">Load average</p>
            <p class="text-xl font-bold mt-1" x-text="data.server.load_avg_1m !== null ? data.server.load_avg_1m.toFixed(2) : '—'"></p>
            <p class="text-xs mt-0.5 opacity-80">
                5m <span x-text="data.server.load_avg_5m !== null ? data.server.load_avg_5m.toFixed(2) : '—'"></span> ·
                15m <span x-text="data.server.load_avg_15m !== null ? data.server.load_avg_15m.toFixed(2) : '—'"></span>
                <template x-if="data.server.cpu_count"><span x-text="' · ' + data.server.cpu_count + ' cores'"></span></template>
            </p>
        </div>
        <div class="rounded-lg border px-4 py-3" :class="colorClasses[memColor()]">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">Memory</p>
            <p class="text-xl font-bold mt-1" x-text="(memUsedPct() !== null ? memUsedPct() + '%' : '—') + ' used'"></p>
            <p class="text-xs mt-0.5 opacity-80">
                <template x-if="data.server.memory_available_mb && data.server.memory_total_mb">
                    <span x-text="Math.round(data.server.memory_total_mb - data.server.memory_available_mb).toLocaleString() + ' / ' + Math.round(data.server.memory_total_mb).toLocaleString() + ' MB'"></span>
                </template>
                <template x-if="!(data.server.memory_available_mb && data.server.memory_total_mb)"><span>—</span></template>
            </p>
        </div>
        <div class="rounded-lg border px-4 py-3" :class="colorClasses[tempColor()]">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">CPU temperature</p>
            <p class="text-xl font-bold mt-1" x-text="data.server.cpu_temp_c !== null ? data.server.cpu_temp_c.toFixed(1) + '°C' : '—'"></p>
            <p class="text-xs mt-0.5 opacity-80">
                <span x-show="data.server.cpu_temp_c === null">Not available</span>
                <span x-show="data.server.cpu_temp_c >= 90">High — consider reducing concurrent workers</span>
                <span x-show="data.server.cpu_temp_c >= 80 && data.server.cpu_temp_c < 90">Elevated</span>
                <span x-show="data.server.cpu_temp_c !== null && data.server.cpu_temp_c < 80">Normal</span>
            </p>
        </div>
    </div>
</div>

{{-- ── App signals — replaces Pulse's Exceptions/SlowQueries cards (removed 2026-07-26).
     Same information (log-derived counts over the last hour), no separate package/dashboard. --}}
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 mt-4">
    <div class="px-5 py-3 border-b border-slate-100 dark:border-slate-700">
        <span class="text-xs font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500">App signals (last hour)</span>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 px-5 py-4">
        <div class="rounded-lg border px-4 py-3" :class="colorClasses[thresholdColor(data.log_signals.errors_last_hour)]">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">Errors</p>
            <p class="text-xl font-bold mt-1" x-text="data.log_signals.errors_last_hour.toLocaleString()"></p>
        </div>
        <div class="rounded-lg border px-4 py-3" :class="colorClasses[thresholdColor(data.log_signals.slow_queries_last_hour)]">
            <p class="text-xs font-medium uppercase tracking-wide opacity-80">Slow queries (&gt;500ms/request)</p>
            <p class="text-xl font-bold mt-1" x-text="data.log_signals.slow_queries_last_hour.toLocaleString()"></p>
        </div>
    </div>
</div>

<p class="text-xs text-slate-400 dark:text-slate-500 mt-4 text-center">
    Checked <span x-text="data.checked_at"></span> · auto-refreshes every 15s
    <span x-show="refreshFailed" class="text-amber-500 dark:text-amber-400">(last refresh failed, retrying…)</span>
</p>

</div>

@push('scripts')
<script>
function pipelineHealthState(initial) {
    return {
        data: initial,
        refreshFailed: false,
        colorClasses: {!! json_encode($colorClasses) !!},
        get isHealthy() { return this.data.status === 'healthy'; },
        memUsedPct() {
            const s = this.data.server;
            return s.memory_total_mb ? Math.round((1 - (s.memory_available_mb / s.memory_total_mb)) * 100) : null;
        },
        memColor() {
            const pct = this.memUsedPct();
            return pct === null ? 'slate' : (pct >= 90 ? 'red' : (pct >= 75 ? 'amber' : 'emerald'));
        },
        loadColor() {
            const s = this.data.server;
            const perCore = s.cpu_count ? s.load_avg_1m / s.cpu_count : null;
            return perCore === null ? 'slate' : (perCore >= 1 ? 'red' : (perCore >= 0.75 ? 'amber' : 'emerald'));
        },
        tempColor() {
            const t = this.data.server.cpu_temp_c;
            return t === null ? 'slate' : (t >= 90 ? 'red' : (t >= 80 ? 'amber' : 'emerald'));
        },
        thresholdColor(count) {
            return count === 0 ? 'emerald' : (count < 5 ? 'amber' : 'red');
        },
        start() {
            setInterval(() => this.refresh(), 15000);
        },
        refresh() {
            fetch('{{ route('documents.pipeline.health', ['format' => 'json']) }}', { headers: { Accept: 'application/json' } })
                .then(res => { if (!res.ok) throw new Error(); return res.json(); })
                .then(json => {
                    this.refreshFailed = false;
                    this.data = {
                        ...json,
                        last_job_activity: json.last_job_activity ? new Date(json.last_job_activity).toLocaleString('en-GB').replace(',', '') : null,
                        checked_at: new Date(json.checked_at).toLocaleString('en-GB').replace(',', ''),
                    };
                })
                .catch(() => { this.refreshFailed = true; });
        },
    };
}
</script>
@endpush

</x-layout>

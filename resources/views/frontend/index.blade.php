<x-layout
    title="Dashboard"
    page-title="Dashboard"
    page-subtitle="Overview of vault activity and document status"
>

{{-- ── Stat cards ── --}}
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">

    <a href="{{ route('documents.index') }}" class="stat-card">
        <div class="stat-icon bg-slate-100 dark:bg-slate-700">
            <i class="ti ti-files text-slate-600 dark:text-slate-300"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ number_format($stats['total']) }}</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Total Documents</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1 flex items-center gap-1">
                <i class="ti ti-database text-xs"></i> All departments
            </p>
        </div>
    </a>

    <div class="stat-card">
        <div class="stat-icon bg-emerald-50 dark:bg-emerald-900/30">
            <i class="ti ti-circle-check text-emerald-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ number_format($stats['verified']) }}</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">Verified</p>
            <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-1">
                @if($stats['total'] > 0) {{ round(($stats['verified'] / $stats['total']) * 100) }}% of total
                @else Ready for RAG @endif
            </p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber-50 dark:bg-amber-900/30">
            <i class="ti ti-eye text-amber-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ number_format($stats['review']) }}</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">In Review</p>
            <p class="text-xs text-amber-600 dark:text-amber-400 mt-1 flex items-center gap-1">
                <i class="ti ti-clock text-xs"></i> Pending approval
            </p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-blue-50 dark:bg-blue-900/30">
            <i class="ti ti-loader-2 text-blue-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800 dark:text-slate-100">{{ number_format($stats['processing'] + $stats['uploaded']) }}</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-0.5">In Pipeline</p>
            <p class="text-xs text-blue-600 dark:text-blue-400 mt-1 flex items-center gap-1">
                <i class="ti ti-cpu text-xs"></i>
                {{ $stats['processing'] }} processing · {{ $stats['uploaded'] }} queued
            </p>
        </div>
    </div>

</div>

{{-- ── Department Browse Cards ── --}}
<div class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-300 flex items-center gap-2">
            <i class="ti ti-building-estate text-slate-400 dark:text-slate-500"></i>
            Browse by Department
        </h2>
        <span class="text-xs text-slate-400 dark:text-slate-500">Select a department to browse its document vault</span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">

        @php
            $deptCards = [
                ['label' => 'Excise Dept.',       'sub' => 'HQ + Secretariat',   'icon' => 'ti-building-community', 'color' => 'amber',   'tag' => 'Dept.',  'slug' => 'excise',     'filter' => fn() => $departments->where('slug', 'excise')->sum('documents_count')],
                ['label' => 'Sugarcane & Sugar',  'sub' => 'Industries Dept.',    'icon' => 'ti-leaf',               'color' => 'emerald', 'tag' => 'Dept.',  'slug' => 'sugarcane',  'filter' => fn() => $departments->where('slug', 'sugarcane')->sum('documents_count')],
                ['label' => 'Sugar Mill Corp.',   'sub' => 'UP State Corp.',      'icon' => 'ti-building-factory',   'color' => 'cyan',    'tag' => 'Corp.',  'slug' => null,         'filter' => fn() => 0],
                ['label' => 'Cane Federation',    'sub' => 'UP Cooperative',      'icon' => 'ti-stack-2',            'color' => 'violet',  'tag' => 'Fed.',   'slug' => null,         'filter' => fn() => 0],
                ['label' => 'Secretariat',        'sub' => 'JS / DS Wing',        'icon' => 'ti-building-arch',      'color' => 'rose',    'tag' => 'Sectt.', 'slug' => null,         'filter' => fn() => $departments->where('level', 'secretariat_level')->sum('documents_count')],
            ];
            $hoverMap = ['amber' => 'hover:border-amber-300 dark:hover:border-amber-700', 'emerald' => 'hover:border-emerald-300 dark:hover:border-emerald-700', 'cyan' => 'hover:border-cyan-300 dark:hover:border-cyan-700', 'violet' => 'hover:border-violet-300 dark:hover:border-violet-700', 'rose' => 'hover:border-rose-300 dark:hover:border-rose-700'];
            $iconBgMap = ['amber' => 'bg-amber-100 dark:bg-amber-900/40 group-hover:bg-amber-200 dark:group-hover:bg-amber-900/60', 'emerald' => 'bg-emerald-100 dark:bg-emerald-900/40 group-hover:bg-emerald-200 dark:group-hover:bg-emerald-900/60', 'cyan' => 'bg-cyan-100 dark:bg-cyan-900/40 group-hover:bg-cyan-200 dark:group-hover:bg-cyan-900/60', 'violet' => 'bg-violet-100 dark:bg-violet-900/40 group-hover:bg-violet-200 dark:group-hover:bg-violet-900/60', 'rose' => 'bg-rose-100 dark:bg-rose-900/40 group-hover:bg-rose-200 dark:group-hover:bg-rose-900/60'];
            $iconColorMap = ['amber' => 'text-amber-600 dark:text-amber-400', 'emerald' => 'text-emerald-600 dark:text-emerald-400', 'cyan' => 'text-cyan-600 dark:text-cyan-400', 'violet' => 'text-violet-600 dark:text-violet-400', 'rose' => 'text-rose-600 dark:text-rose-400'];
            $tagMap = ['amber' => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400', 'emerald' => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400', 'cyan' => 'bg-cyan-100 dark:bg-cyan-900/40 text-cyan-700 dark:text-cyan-400', 'violet' => 'bg-violet-100 dark:bg-violet-900/40 text-violet-700 dark:text-violet-400', 'rose' => 'bg-rose-100 dark:bg-rose-900/40 text-rose-700 dark:text-rose-400'];
        @endphp

        @foreach($deptCards as $card)
        @php
            $matchedDept = $card['slug']
                ? $departments->first(fn ($d) => $d->slug === $card['slug'])
                : null;
            $cardHref = $matchedDept
                ? route('departments.show', $matchedDept)
                : route('departments.index');
        @endphp
        <a href="{{ $cardHref }}" class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-xl p-5 {{ $hoverMap[$card['color']] }} hover:shadow-md hover:-translate-y-0.5 transition-all group">
            <div class="w-10 h-10 rounded-lg {{ $iconBgMap[$card['color']] }} flex items-center justify-center mb-3 transition-colors">
                <i class="ti {{ $card['icon'] }} text-xl {{ $iconColorMap[$card['color']] }}"></i>
            </div>
            <p class="text-sm font-semibold text-slate-800 dark:text-slate-100 leading-tight">{{ $card['label'] }}</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5">{{ $card['sub'] }}</p>
            <div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-700 flex items-center justify-between">
                <span class="text-xs text-slate-400 dark:text-slate-500 flex items-center gap-1">
                    <i class="ti ti-file"></i> {{ ($card['filter'])() }} docs
                </span>
                <span class="text-xs {{ $tagMap[$card['color']] }} px-1.5 py-0.5 rounded font-medium">{{ $card['tag'] }}</span>
            </div>
        </a>
        @endforeach

    </div>
</div>

{{-- ── Recent docs + Status chart ── --}}
<div class="grid grid-cols-3 gap-4">

    {{-- Recent Documents --}}
    <div class="col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex items-center justify-between flex-shrink-0">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                <i class="ti ti-clock-hour-4 text-slate-400 dark:text-slate-500"></i> Recent Documents
            </h3>
            <a href="{{ route('documents.index') }}"
               class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline flex items-center gap-1 font-medium">
                View all <i class="ti ti-arrow-right text-sm"></i>
            </a>
        </div>

        @if($recentDocuments->isEmpty())
        <div class="flex-1 flex flex-col items-center justify-center py-14 text-center px-6">
            <div class="w-14 h-14 rounded-full bg-slate-100 dark:bg-slate-700 flex items-center justify-center mb-4">
                <i class="ti ti-folder-off text-2xl text-slate-300 dark:text-slate-500"></i>
            </div>
            <p class="text-sm font-semibold text-slate-500 dark:text-slate-400">No documents yet</p>
            <p class="text-xs text-slate-400 dark:text-slate-500 mt-1 max-w-xs">Navigate to a section and upload a PDF to start converting it to Markdown.</p>
            <a href="{{ route('departments.index') }}" class="mt-4 inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition-colors">
                <i class="ti ti-building"></i> Browse Departments
            </a>
        </div>
        @else
        <div class="divide-y divide-slate-100 dark:divide-slate-700 flex-1">
            @foreach($recentDocuments as $doc)
            @php
                $statusMap = [
                    'verified'    => 'bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-400',
                    'review'      => 'bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400',
                    'processing'  => 'bg-blue-100 dark:bg-blue-900/40 text-blue-700 dark:text-blue-400',
                    'ocr_pending' => 'bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400',
                    'uploaded'    => 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300',
                    'failed'      => 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400',
                ];
                $badgeClass = $statusMap[$doc->status] ?? 'bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-300';
            @endphp
            <div class="px-5 py-3 flex items-center gap-4 hover:bg-slate-50 dark:hover:bg-slate-700/50 transition-colors">
                <div class="w-8 h-8 rounded-lg bg-red-50 dark:bg-red-900/30 flex items-center justify-center flex-shrink-0">
                    <i class="ti ti-file-type-pdf text-base text-red-500"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-200 truncate">{{ $doc->title }}</p>
                    <p class="text-xs text-slate-400 dark:text-slate-500 mt-0.5 truncate">
                        {{ $doc->department?->name ?? '—' }}
                        @if($doc->section)<span class="mx-1 text-slate-300 dark:text-slate-600">·</span>{{ $doc->section->name }}@endif
                        <span class="mx-1 text-slate-300 dark:text-slate-600">·</span>
                        {{ \App\Models\Document::DOCUMENT_TYPES[$doc->document_type] ?? ucfirst($doc->document_type) }}
                    </p>
                </div>
                <span class="badge {{ $badgeClass }} flex-shrink-0">
                    {{ ucfirst(str_replace('_', ' ', $doc->status)) }}
                </span>
                <span class="text-xs text-slate-400 dark:text-slate-500 flex-shrink-0 hidden lg:block">
                    {{ $doc->created_at->diffForHumans() }}
                </span>
                <a href="{{ route('documents.show', $doc) }}"
                   class="flex-shrink-0 text-slate-300 dark:text-slate-600 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
                    <i class="ti ti-arrow-right text-base"></i>
                </a>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Status chart --}}
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 flex flex-col">
        <div class="px-5 py-4 border-b border-slate-100 dark:border-slate-700 flex-shrink-0">
            <h3 class="text-sm font-semibold text-slate-700 dark:text-slate-200 flex items-center gap-2">
                <i class="ti ti-chart-donut text-slate-400 dark:text-slate-500"></i> Status Breakdown
            </h3>
        </div>

        <div class="flex-1 flex flex-col items-center justify-center p-5">
            @if($stats['total'] === 0)
            <div class="flex flex-col items-center text-center py-8">
                <i class="ti ti-chart-donut-3 text-5xl text-slate-200 dark:text-slate-700 mb-3"></i>
                <p class="text-sm text-slate-400 dark:text-slate-500 font-medium">No data yet</p>
                <p class="text-xs text-slate-300 dark:text-slate-600 mt-1">Charts appear once<br>documents are added.</p>
            </div>
            @else
            <div class="w-full max-w-[180px]">
                <canvas id="statusChart"></canvas>
            </div>
            @endif
        </div>

        @if($stats['total'] > 0)
        <div class="px-5 pb-5 space-y-2.5 flex-shrink-0">
            @php
                $legendItems = [
                    ['label' => 'Verified',   'dot' => 'bg-emerald-500', 'key' => 'verified'],
                    ['label' => 'In Review',  'dot' => 'bg-amber-400',   'key' => 'review'],
                    ['label' => 'Processing', 'dot' => 'bg-blue-500',    'key' => 'processing'],
                    ['label' => 'Uploaded',   'dot' => 'bg-slate-400',   'key' => 'uploaded'],
                    ['label' => 'Failed',     'dot' => 'bg-red-500',     'key' => 'failed'],
                ];
            @endphp
            @foreach($legendItems as $item)
            @if(($stats[$item['key']] ?? 0) > 0)
            <div class="flex items-center justify-between text-xs">
                <div class="flex items-center gap-2">
                    <div class="w-2.5 h-2.5 rounded-full {{ $item['dot'] }}"></div>
                    <span class="text-slate-500 dark:text-slate-400">{{ $item['label'] }}</span>
                </div>
                <span class="font-semibold text-slate-700 dark:text-slate-200">{{ number_format($stats[$item['key']]) }}</span>
            </div>
            @endif
            @endforeach
            <div class="pt-2 border-t border-slate-100 dark:border-slate-700 flex items-center justify-between text-xs">
                <span class="text-slate-400 dark:text-slate-500 font-medium">Total</span>
                <span class="font-bold text-slate-800 dark:text-slate-100">{{ number_format($stats['total']) }}</span>
            </div>
        </div>
        @endif
    </div>

</div>

{{-- JSON data island for chart --}}
@if($stats['total'] > 0)
<script id="vault-stats" type="application/json">@json($stats)</script>
@endif

@push('scripts')
@if($stats['total'] > 0)
<script>
(function () {
    const stats = JSON.parse(document.getElementById('vault-stats').textContent);
    const ctx   = document.getElementById('statusChart').getContext('2d');
    const isDark = () => document.documentElement.classList.contains('dark');

    const centerTextPlugin = {
        id: 'centerText',
        afterDraw(chart) {
            const { width, height, ctx: c } = chart;
            c.save();
            c.font = 'bold 22px Inter, sans-serif';
            c.fillStyle = isDark() ? '#f1f5f9' : '#1e293b';
            c.textAlign = 'center';
            c.textBaseline = 'middle';
            c.fillText(stats.total, width / 2, height / 2 - 8);
            c.font = '11px Inter, sans-serif';
            c.fillStyle = isDark() ? '#64748b' : '#94a3b8';
            c.fillText('documents', width / 2, height / 2 + 12);
            c.restore();
        }
    };

    new Chart(ctx, {
        type: 'doughnut',
        plugins: [centerTextPlugin],
        data: {
            labels: ['Verified', 'In Review', 'Processing', 'Uploaded', 'Failed'],
            datasets: [{
                data: [stats.verified, stats.review, stats.processing, stats.uploaded, stats.failed],
                backgroundColor: ['#10b981', '#fbbf24', '#3b82f6', '#94a3b8', '#ef4444'],
                borderWidth: 2,
                borderColor: isDark() ? '#1e293b' : '#ffffff',
                hoverOffset: 5,
            }]
        },
        options: {
            responsive: true,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: (c) => `  ${c.label}: ${c.parsed}` } }
            }
        }
    });
})();
</script>
@endif
@endpush

</x-layout>

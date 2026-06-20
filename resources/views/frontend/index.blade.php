<x-layout
    title="Dashboard"
    page-title="Dashboard"
    page-subtitle="Overview of vault activity and document status"
>

{{-- ── Stat cards ── --}}
<div class="grid grid-cols-2 xl:grid-cols-4 gap-4 mb-6">

    <div class="stat-card">
        <div class="stat-icon bg-slate-100">
            <i class="ti ti-files text-slate-600"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800">{{ number_format($stats['total']) }}</p>
            <p class="text-sm text-slate-500 mt-0.5">Total Documents</p>
            <p class="text-xs text-slate-400 mt-1 flex items-center gap-1">
                <i class="ti ti-database text-xs"></i> All departments
            </p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-emerald-50">
            <i class="ti ti-circle-check text-emerald-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800">{{ number_format($stats['verified']) }}</p>
            <p class="text-sm text-slate-500 mt-0.5">Verified</p>
            <p class="text-xs text-emerald-600 mt-1 flex items-center gap-1">
                @if($stats['total'] > 0)
                    {{ round(($stats['verified'] / $stats['total']) * 100) }}% of total
                @else
                    Ready for RAG
                @endif
            </p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-amber-50">
            <i class="ti ti-eye text-amber-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800">{{ number_format($stats['review']) }}</p>
            <p class="text-sm text-slate-500 mt-0.5">In Review</p>
            <p class="text-xs text-amber-600 mt-1 flex items-center gap-1">
                <i class="ti ti-clock text-xs"></i> Pending approval
            </p>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon bg-blue-50">
            <i class="ti ti-loader-2 text-blue-500"></i>
        </div>
        <div>
            <p class="text-2xl font-bold text-slate-800">{{ number_format($stats['processing'] + $stats['uploaded']) }}</p>
            <p class="text-sm text-slate-500 mt-0.5">In Pipeline</p>
            <p class="text-xs text-blue-600 mt-1 flex items-center gap-1">
                <i class="ti ti-cpu text-xs"></i>
                {{ $stats['processing'] }} processing · {{ $stats['uploaded'] }} queued
            </p>
        </div>
    </div>

</div>

{{-- ── Department Browse Cards ── --}}
<div class="mb-6">
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-sm font-semibold text-slate-700 flex items-center gap-2">
            <i class="ti ti-building-estate text-slate-400"></i>
            Browse by Department
        </h2>
        <span class="text-xs text-slate-400">Select a department to browse its document vault</span>
    </div>

    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4">

        <a href="#" class="bg-white border border-slate-200 rounded-xl p-5 hover:border-amber-300 hover:shadow-md hover:-translate-y-0.5 transition-all group">
            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center mb-3 group-hover:bg-amber-200 transition-colors">
                <i class="ti ti-building-community text-xl text-amber-600"></i>
            </div>
            <p class="text-sm font-semibold text-slate-800 leading-tight">Excise Dept.</p>
            <p class="text-xs text-slate-400 mt-0.5">HQ + Secretariat</p>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-400 flex items-center gap-1">
                    <i class="ti ti-file"></i>
                    {{ $departments->where('slug', 'excise')->sum('documents_count') }} docs
                </span>
                <span class="text-xs bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded font-medium">Dept.</span>
            </div>
        </a>

        <a href="#" class="bg-white border border-slate-200 rounded-xl p-5 hover:border-emerald-300 hover:shadow-md hover:-translate-y-0.5 transition-all group">
            <div class="w-10 h-10 rounded-lg bg-emerald-100 flex items-center justify-center mb-3 group-hover:bg-emerald-200 transition-colors">
                <i class="ti ti-leaf text-xl text-emerald-600"></i>
            </div>
            <p class="text-sm font-semibold text-slate-800 leading-tight">Sugarcane & Sugar</p>
            <p class="text-xs text-slate-400 mt-0.5">Industries Dept.</p>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-400 flex items-center gap-1">
                    <i class="ti ti-file"></i>
                    {{ $departments->where('slug', 'sugarcane')->sum('documents_count') }} docs
                </span>
                <span class="text-xs bg-emerald-100 text-emerald-700 px-1.5 py-0.5 rounded font-medium">Dept.</span>
            </div>
        </a>

        <a href="#" class="bg-white border border-slate-200 rounded-xl p-5 hover:border-cyan-300 hover:shadow-md hover:-translate-y-0.5 transition-all group">
            <div class="w-10 h-10 rounded-lg bg-cyan-100 flex items-center justify-center mb-3 group-hover:bg-cyan-200 transition-colors">
                <i class="ti ti-building-factory text-xl text-cyan-600"></i>
            </div>
            <p class="text-sm font-semibold text-slate-800 leading-tight">Sugar Mill Corp.</p>
            <p class="text-xs text-slate-400 mt-0.5">UP State Corp.</p>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-400 flex items-center gap-1">
                    <i class="ti ti-file"></i> 0 docs
                </span>
                <span class="text-xs bg-cyan-100 text-cyan-700 px-1.5 py-0.5 rounded font-medium">Corp.</span>
            </div>
        </a>

        <a href="#" class="bg-white border border-slate-200 rounded-xl p-5 hover:border-violet-300 hover:shadow-md hover:-translate-y-0.5 transition-all group">
            <div class="w-10 h-10 rounded-lg bg-violet-100 flex items-center justify-center mb-3 group-hover:bg-violet-200 transition-colors">
                <i class="ti ti-stack-2 text-xl text-violet-600"></i>
            </div>
            <p class="text-sm font-semibold text-slate-800 leading-tight">Cane Federation</p>
            <p class="text-xs text-slate-400 mt-0.5">UP Cooperative</p>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-400 flex items-center gap-1">
                    <i class="ti ti-file"></i> 0 docs
                </span>
                <span class="text-xs bg-violet-100 text-violet-700 px-1.5 py-0.5 rounded font-medium">Fed.</span>
            </div>
        </a>

        <a href="#" class="bg-white border border-slate-200 rounded-xl p-5 hover:border-rose-300 hover:shadow-md hover:-translate-y-0.5 transition-all group">
            <div class="w-10 h-10 rounded-lg bg-rose-100 flex items-center justify-center mb-3 group-hover:bg-rose-200 transition-colors">
                <i class="ti ti-building-arch text-xl text-rose-600"></i>
            </div>
            <p class="text-sm font-semibold text-slate-800 leading-tight">Secretariat</p>
            <p class="text-xs text-slate-400 mt-0.5">JS / DS Wing</p>
            <div class="mt-3 pt-3 border-t border-slate-100 flex items-center justify-between">
                <span class="text-xs text-slate-400 flex items-center gap-1">
                    <i class="ti ti-file"></i>
                    {{ $departments->where('level', 'secretariat_level')->sum('documents_count') }} docs
                </span>
                <span class="text-xs bg-rose-100 text-rose-700 px-1.5 py-0.5 rounded font-medium">Sectt.</span>
            </div>
        </a>

    </div>
</div>

{{-- ── Recent docs + Status chart ── --}}
<div class="grid grid-cols-3 gap-4">

    {{-- Recent Documents --}}
    <div class="col-span-2 bg-white rounded-xl border border-slate-200 flex flex-col">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
            <h3 class="text-sm font-semibold text-slate-700 flex items-center gap-2">
                <i class="ti ti-clock-hour-4 text-slate-400"></i>
                Recent Documents
            </h3>
            <a href="{{ route('vault.documents.index') }}"
               class="text-xs text-indigo-600 hover:text-indigo-800 flex items-center gap-1 font-medium">
                View all <i class="ti ti-arrow-right text-sm"></i>
            </a>
        </div>

        @if($recentDocuments->isEmpty())
        <div class="flex-1 flex flex-col items-center justify-center py-14 text-center px-6">
            <div class="w-14 h-14 rounded-full bg-slate-100 flex items-center justify-center mb-4">
                <i class="ti ti-folder-off text-2xl text-slate-300"></i>
            </div>
            <p class="text-sm font-semibold text-slate-500">No documents yet</p>
            <p class="text-xs text-slate-400 mt-1 max-w-xs">Upload a PDF and convert it to Markdown to populate the vault.</p>
            <a href="#"
               class="mt-4 inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-medium px-3 py-1.5 rounded-lg transition-colors">
                <i class="ti ti-file-upload"></i> Convert your first PDF
            </a>
        </div>
        @else
        <div class="divide-y divide-slate-100 flex-1">
            @foreach($recentDocuments as $doc)
            @php
                $statusMap = [
                    'verified'    => ['bg-emerald-100 text-emerald-700', 'ti-circle-check'],
                    'review'      => ['bg-amber-100 text-amber-700',     'ti-eye'],
                    'processing'  => ['bg-blue-100 text-blue-700',       'ti-loader-2'],
                    'ocr_pending' => ['bg-purple-100 text-purple-700',   'ti-scan'],
                    'uploaded'    => ['bg-slate-100 text-slate-600',     'ti-upload'],
                    'failed'      => ['bg-red-100 text-red-700',         'ti-alert-circle'],
                ];
                [$badgeClass, $badgeIcon] = $statusMap[$doc->status] ?? ['bg-slate-100 text-slate-600', 'ti-file'];
            @endphp
            <div class="px-5 py-3 flex items-center gap-4 hover:bg-slate-50 transition-colors">
                <div class="w-8 h-8 rounded-lg bg-red-50 flex items-center justify-center flex-shrink-0">
                    <i class="ti ti-file-type-pdf text-base text-red-500"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-700 truncate">{{ $doc->original_filename }}</p>
                    <p class="text-xs text-slate-400 mt-0.5 truncate">
                        {{ $doc->department?->name ?? '—' }}
                        @if($doc->section)
                            <span class="text-slate-300 mx-1">·</span>{{ $doc->section->name }}
                        @endif
                    </p>
                </div>
                <span class="badge {{ $badgeClass }} flex-shrink-0">
                    <i class="ti {{ $badgeIcon }} mr-1 text-xs"></i>
                    {{ ucfirst(str_replace('_', ' ', $doc->status)) }}
                </span>
                <span class="text-xs text-slate-400 flex-shrink-0 hidden lg:block">
                    {{ $doc->created_at->diffForHumans() }}
                </span>
                <a href="{{ route('vault.documents.show', $doc) }}"
                   class="flex-shrink-0 text-slate-300 hover:text-indigo-600 transition-colors">
                    <i class="ti ti-arrow-right text-base"></i>
                </a>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- Status chart + legend --}}
    <div class="bg-white rounded-xl border border-slate-200 flex flex-col">
        <div class="px-5 py-4 border-b border-slate-100 flex-shrink-0">
            <h3 class="text-sm font-semibold text-slate-700 flex items-center gap-2">
                <i class="ti ti-chart-donut text-slate-400"></i>
                Status Breakdown
            </h3>
        </div>

        <div class="flex-1 flex flex-col items-center justify-center p-5">
            @if($stats['total'] === 0)
            <div class="flex flex-col items-center text-center py-8">
                <i class="ti ti-chart-donut-3 text-5xl text-slate-200 mb-3"></i>
                <p class="text-sm text-slate-400 font-medium">No data yet</p>
                <p class="text-xs text-slate-300 mt-1">Charts appear once<br>documents are added.</p>
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
                    <span class="text-slate-500">{{ $item['label'] }}</span>
                </div>
                <span class="font-semibold text-slate-700">{{ number_format($stats[$item['key']]) }}</span>
            </div>
            @endif
            @endforeach
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                <span class="text-slate-400 font-medium">Total</span>
                <span class="font-bold text-slate-800">{{ number_format($stats['total']) }}</span>
            </div>
        </div>
        @endif
    </div>

</div>

{{-- Data island: IDE treats type="application/json" as JSON, not JS — no false positive errors --}}
@if($stats['total'] > 0)
<script id="vault-stats" type="application/json">@json($stats)</script>
@endif

@push('scripts')
@if($stats['total'] > 0)
<script>
(function () {
    const stats = JSON.parse(document.getElementById('vault-stats').textContent);
    const ctx   = document.getElementById('statusChart').getContext('2d');

    const centerTextPlugin = {
        id: 'centerText',
        afterDraw(chart) {
            const { width, height, ctx: c } = chart;
            c.save();
            c.font = 'bold 22px Inter, sans-serif';
            c.fillStyle = '#1e293b';
            c.textAlign = 'center';
            c.textBaseline = 'middle';
            c.fillText(stats.total, width / 2, height / 2 - 8);
            c.font = '11px Inter, sans-serif';
            c.fillStyle = '#94a3b8';
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
                borderColor: '#ffffff',
                hoverOffset: 5,
            }]
        },
        options: {
            responsive: true,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: (c) => `  ${c.label}: ${c.parsed}` }
                }
            }
        }
    });
})();
</script>
@endif
@endpush

</x-layout>

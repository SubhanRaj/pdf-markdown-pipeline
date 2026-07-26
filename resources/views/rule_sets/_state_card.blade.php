{{-- One state/UT card. Expects $department and $state (['name' => ..., 'count' => ...]) in scope. --}}
<a href="{{ route('departments.policy.state', [$department->levelAlias(), $department, \App\Models\RuleSet::stateSlug($state['name'])]) }}"
   class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 flex flex-col gap-2 hover:border-indigo-300 dark:hover:border-indigo-600 transition-colors">
    <div class="flex items-center gap-2">
        <div class="stat-icon bg-slate-50 dark:bg-slate-700 flex-shrink-0 p-2" data-state-icon="{{ $state['name'] }}">
            <i class="ti ti-map-pin text-slate-400 dark:text-slate-500"></i>
        </div>
        <p class="text-sm font-medium text-slate-700 dark:text-slate-200 leading-tight">{{ $state['name'] }}</p>
    </div>
    <p class="text-xs {{ $state['count'] > 0 ? 'text-indigo-500 dark:text-indigo-400 font-medium' : 'text-slate-400 dark:text-slate-500' }}">
        {{ $state['count'] > 0 ? $state['count'] . ' current ' . Str::plural('policy', $state['count']) : 'No policy yet' }}
    </p>
</a>

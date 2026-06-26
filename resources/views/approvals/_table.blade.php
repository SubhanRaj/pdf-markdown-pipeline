<div data-tab-body="{{ $tabKey }}">
    <div class="overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900">
        <table id="{{ $tableId }}" class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
            <thead>
                <tr class="bg-slate-50 dark:bg-slate-800/50">
                    @if($isApprover && $tabKey === 'pending')
                    <th class="px-4 py-3 w-10">
                        <input type="checkbox" class="select-all-cb w-4 h-4 rounded border-slate-300 dark:border-slate-600 text-indigo-600" data-tab="{{ $tabKey }}">
                    </th>
                    @else
                    <th class="px-4 py-3 w-10"></th>
                    @endif
                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Document</th>
                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 hidden sm:table-cell">Type</th>
                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 hidden md:table-cell">Status</th>
                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 hidden lg:table-cell">Uploaded By</th>
                    <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400 hidden xl:table-cell">Date</th>
                    <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                {{-- rows populated by JS --}}
            </tbody>
        </table>

        <div id="{{ $tableId }}-empty" class="hidden py-16 text-center">
            <i class="ti ti-inbox text-4xl text-slate-300 dark:text-slate-600 mb-2 block"></i>
            <p class="text-sm text-slate-400 dark:text-slate-500">{{ $emptyMsg }}</p>
        </div>
    </div>

    @if($isApprover && $tabKey === 'pending')
    {{-- Bulk action bar --}}
    <div id="bulk-bar-{{ $tabKey }}" class="hidden mt-4 px-4 py-3 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-200 dark:border-indigo-800/50 rounded-xl flex items-center gap-3 flex-wrap">
        <span class="bulk-count text-sm font-medium text-indigo-700 dark:text-indigo-400"></span>
        <div class="flex-1"></div>
        <button onclick="bulkApprove('{{ $tabKey }}')"
            class="inline-flex items-center gap-1.5 px-4 py-1.5 text-sm font-semibold bg-green-600 hover:bg-green-700 text-white rounded-lg transition-colors">
            <i class="ti ti-checks"></i> Approve Selected
        </button>
        <button onclick="bulkReject('{{ $tabKey }}')"
            class="inline-flex items-center gap-1.5 px-4 py-1.5 text-sm font-semibold bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors">
            <i class="ti ti-x"></i> Reject Selected
        </button>
    </div>
    @endif
</div>

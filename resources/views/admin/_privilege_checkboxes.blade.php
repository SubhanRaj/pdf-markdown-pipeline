{{--
    Shared Granular Privileges checkbox panel.
    Params: $name (input name, e.g. "privileges" or "default_privileges"), $checked (array of
    currently-checked privilege keys), $readonly (optional — renders checked privileges as plain
    text instead of editable checkboxes, for profile/show pages).
--}}
@php
$readonly = $readonly ?? false;
$privilegeLabels = [
    'documents.upload'       => ['label' => 'Upload documents',           'group' => 'Documents'],
    'documents.edit'         => ['label' => 'Edit document metadata',     'group' => 'Documents'],
    'documents.delete'       => ['label' => 'Archive (soft-delete) docs', 'group' => 'Documents'],
    'documents.restore'      => ['label' => 'Restore from archive',       'group' => 'Documents'],
    'documents.force-delete' => ['label' => 'Permanently delete (requires letter)', 'group' => 'Documents'],
    'documents.verify'       => ['label' => 'Verify / mark as verified',  'group' => 'Documents'],
    'documents.approve'      => ['label' => 'Approve / reject pending uploads', 'group' => 'Documents'],
    'documents.move'         => ['label' => 'Move / copy a document to another location', 'group' => 'Documents'],
    'folders.delete'         => ['label' => 'Delete folders (and everything inside them)', 'group' => 'Documents'],
    'section.head'           => ['label' => 'Section Head (create divisions in own section)', 'group' => 'Organisational'],
    'department.head'        => ['label' => 'Department Head (create sections/divisions in own dept)', 'group' => 'Organisational'],
    'organization.head'      => ['label' => 'Organisation Head (full access across all depts)', 'group' => 'Organisational'],
];
$privGroups = collect($privilegeLabels)->groupBy(fn($v) => $v['group'], true);
@endphp
@if($readonly)
    @if(empty(array_intersect($checked ?? [], array_keys($privilegeLabels))))
    <p class="text-sm text-slate-400 dark:text-slate-500">None.</p>
    @else
    @foreach($privGroups as $group => $privs)
        @php($granted = $privs->only($checked ?? []))
        @continue($granted->isEmpty())
        <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1.5 mt-3">{{ $group }}</p>
        <ul class="space-y-1">
            @foreach($granted as $meta)
            <li class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <i class="ti ti-check text-emerald-500 text-base flex-shrink-0"></i> {{ $meta['label'] }}
            </li>
            @endforeach
        </ul>
    @endforeach
    @endif
@else
@foreach($privGroups as $group => $privs)
<p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-1.5 mt-3">{{ $group }}</p>
<div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
    @foreach($privs as $key => $meta)
    <label class="flex items-start gap-2 text-sm text-slate-600 dark:text-slate-300 cursor-pointer select-none p-2 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors">
        <input
            type="checkbox"
            name="{{ $name }}[]"
            value="{{ $key }}"
            {{ in_array($key, $checked ?? []) ? 'checked' : '' }}
            class="mt-0.5 w-4 h-4 rounded border-slate-300 dark:border-slate-600 dark:bg-slate-700 text-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-400 flex-shrink-0"
        >
        <span class="font-medium">{{ $meta['label'] }}</span>
    </label>
    @endforeach
</div>
@endforeach
@endif

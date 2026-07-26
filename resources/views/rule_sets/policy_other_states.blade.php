<x-layout
    title="Other States' Policy"
    page-title="Other States' Policy"
    :page-subtitle="$department->name"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                    'url' => route('home')],
    ['name' => 'Departments',             'url' => route('departments.index')],
    ['name' => $department->levelLabel(), 'url' => null],
    ['name' => $department->name,         'url' => route('departments.show', [$department->levelAlias(), $department])],
    ['name' => 'Policies',                'url' => route('departments.policy.index', [$department->levelAlias(), $department])],
    ['name' => 'Other States',            'url' => null],
]" />

<p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-2">States</p>
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mb-8">
    @foreach($states as $state)
    @include('rule_sets._state_card')
    @endforeach
</div>

<p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 dark:text-slate-500 mb-2">Union Territories</p>
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
    @foreach($unionTerritories as $state)
    @include('rule_sets._state_card')
    @endforeach
</div>

@push('scripts')
@include('rule_sets._state_icon_loader')
@endpush

</x-layout>

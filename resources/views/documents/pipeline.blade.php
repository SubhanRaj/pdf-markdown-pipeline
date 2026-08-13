<x-layout
    title="Conversion Pipeline"
    page-title="Conversion Pipeline"
    page-subtitle="Every document not yet verified — upload, conversion, and review status at a glance"
>

<x-breadcrumb :items="[
    ['name' => 'Home',                'url' => route('home')],
    ['name' => 'Conversion Pipeline', 'url' => null],
]" />

<livewire:pipeline-monitor :active-status="$activeStatus" />

</x-layout>

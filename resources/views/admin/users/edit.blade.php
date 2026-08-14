<x-layout
    title="Edit User"
    page-title="Edit User"
    page-subtitle="Update account details for {{ $user->name }}"
>

<x-breadcrumb :items="[
    ['name' => 'Home', 'url' => route('home')],
    ['name' => 'Users', 'url' => route('admin.users.index')],
    ['name' => 'Edit · ' . $user->name, 'url' => null],
]" />

<livewire:user-form :user-id="$user->id" />

</x-layout>

<?php

use App\Models\Designation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('non-admins cannot create a designation', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);

    Livewire::actingAs($viewer)
        ->test('designation-manager')
        ->set('form.name', 'Section Officer')
        ->set('form.default_scope', 'section')
        ->call('save')
        ->assertStatus(403);

    expect(Designation::count())->toBe(0);
});

test('save creates a new designation with a derived slug', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($admin)
        ->test('designation-manager')
        ->set('form.name', 'Section Officer')
        ->set('form.default_scope', 'section')
        ->set('form.default_privileges', ['documents.upload'])
        ->call('save')
        ->assertSet('showModal', false);

    $designation = Designation::sole();
    expect($designation->name)->toBe('Section Officer');
    expect($designation->slug)->toBe('section_officer');
    expect($designation->default_scope)->toBe('section');
    expect($designation->default_privileges)->toBe(['documents.upload']);
});

test('save surfaces a validation error and keeps the modal open instead of throwing', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($admin)
        ->test('designation-manager')
        ->call('openCreate')
        ->set('form.name', '')
        ->set('form.default_scope', 'section')
        ->call('save')
        ->assertSet('showModal', true)
        ->assertHasErrors('form.name');

    expect(Designation::count())->toBe(0);
});

test('openEdit loads an existing designation into the form and save updates it', function () {
    $admin       = User::factory()->create(['role' => 'admin']);
    $designation = Designation::create([
        'name' => 'Deputy Commissioner', 'slug' => 'deputy_commissioner',
        'default_scope' => 'department', 'default_privileges' => [], 'sort_order' => 0,
    ]);

    Livewire::actingAs($admin)
        ->test('designation-manager')
        ->call('openEdit', $designation->id)
        ->assertSet('form.name', 'Deputy Commissioner')
        ->set('form.default_scope', 'global')
        ->call('save')
        ->assertSet('showModal', false);

    expect($designation->fresh()->default_scope)->toBe('global');
});

test('non-admins cannot delete a designation', function () {
    $viewer      = User::factory()->create(['role' => 'viewer']);
    $designation = Designation::create([
        'name' => 'Clerk', 'slug' => 'clerk', 'default_scope' => 'none',
        'default_privileges' => [], 'sort_order' => 0,
    ]);

    Livewire::actingAs($viewer)
        ->test('designation-manager')
        ->call('delete', $designation->id)
        ->assertStatus(403);

    expect(Designation::find($designation->id))->not->toBeNull();
});

test('delete removes the designation', function () {
    $admin       = User::factory()->create(['role' => 'admin']);
    $designation = Designation::create([
        'name' => 'Clerk', 'slug' => 'clerk', 'default_scope' => 'none',
        'default_privileges' => [], 'sort_order' => 0,
    ]);

    Livewire::actingAs($admin)
        ->test('designation-manager')
        ->call('delete', $designation->id);

    expect(Designation::find($designation->id))->toBeNull();
});

<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Division;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── user-list ───────────────────────────────────────────────────────────────

test('non-admins cannot delete a user', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $target = User::factory()->create(['role' => 'viewer']);

    Livewire::actingAs($viewer)
        ->test('user-list')
        ->call('delete', $target->id)
        ->assertStatus(403);

    expect(User::find($target->id))->not->toBeNull();
});

test('delete soft-deletes the target user', function () {
    $admin  = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'viewer']);

    Livewire::actingAs($admin)
        ->test('user-list')
        ->call('delete', $target->id);

    expect(User::find($target->id))->toBeNull();
    expect(User::withTrashed()->find($target->id))->not->toBeNull();
});

// ── user-form ───────────────────────────────────────────────────────────────

test('non-admins cannot create a user', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);

    Livewire::actingAs($viewer)
        ->test('user-form')
        ->set('form.name', 'Ramesh Kumar')
        ->set('form.email', 'ramesh@example.gov.in')
        ->set('form.role', 'viewer')
        ->call('save')
        ->assertStatus(403);

    expect(User::where('email', 'ramesh@example.gov.in')->exists())->toBeFalse();
});

test('save creates a new user and auto-generates a username when left blank', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($admin)
        ->test('user-form')
        ->set('form.name', 'Ramesh Kumar')
        ->set('form.email', 'ramesh@example.gov.in')
        ->set('form.role', 'viewer')
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'ramesh@example.gov.in')->sole();
    expect($user->name)->toBe('Ramesh Kumar');
    expect($user->username)->not->toBeEmpty();
    expect($user->role)->toBe('viewer');
});

test('save surfaces a validation error and does not create a user', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    Livewire::actingAs($admin)
        ->test('user-form')
        ->set('form.name', 'Ramesh Kumar')
        ->set('form.email', 'not-an-email')
        ->set('form.role', 'viewer')
        ->call('save')
        ->assertHasErrors('form.email');

    expect(User::count())->toBe(1); // only the admin from setup
});

test('changing designation pre-fills department and default privileges', function () {
    $admin      = User::factory()->create(['role' => 'admin']);
    $department = Department::factory()->create();
    $designation = Designation::create([
        'name' => 'Section Officer', 'slug' => 'section_officer', 'department_id' => $department->id,
        'default_scope' => 'section', 'default_privileges' => ['documents.upload'], 'sort_order' => 0,
    ]);

    Livewire::actingAs($admin)
        ->test('user-form')
        ->set('form.designation_id', $designation->id)
        ->assertSet('form.department_id', $department->id)
        ->assertSet('form.privileges', ['documents.upload']);
});

test('changing department resets the previously selected section and division', function () {
    $admin      = User::factory()->create(['role' => 'admin']);
    $department = Department::factory()->create();
    $section    = Section::factory()->create(['department_id' => $department->id]);
    $division   = Division::create(['section_id' => $section->id, 'name' => 'Div A', 'slug' => 'div_a']);
    $otherDept  = Department::factory()->create();

    Livewire::actingAs($admin)
        ->test('user-form')
        ->set('form.section_id', $section->id)
        ->set('form.division_id', $division->id)
        ->set('form.department_id', $otherDept->id)
        ->assertSet('form.section_id', null)
        ->assertSet('form.division_id', null);
});

test('openEdit-equivalent mount loads an existing user and save updates it', function () {
    $admin  = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'viewer', 'name' => 'Old Name', 'username' => 'old_username']);

    Livewire::actingAs($admin)
        ->test('user-form', ['userId' => $target->id])
        ->assertSet('form.name', 'Old Name')
        ->set('form.name', 'New Name')
        ->call('save')
        ->assertHasNoErrors();

    expect($target->fresh()->name)->toBe('New Name');
});

test('resendActivation requires admin', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $target = User::factory()->unverified()->create(['role' => 'viewer']);

    Livewire::actingAs($viewer)
        ->test('user-form', ['userId' => $target->id])
        ->call('resendActivation')
        ->assertStatus(403);
});

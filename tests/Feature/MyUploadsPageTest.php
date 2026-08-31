<?php

use App\Models\Department;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('my-uploads page renders for an authenticated user', function () {
    $user = User::factory()->create(['role' => 'viewer', 'username' => fake()->unique()->userName()]);
    $this->actingAs($user)->get(route('documents.my-uploads.index'))->assertOk();
});

test('my-uploads page requires auth', function () {
    $this->get(route('documents.my-uploads.index'))->assertRedirect(route('login'));
});

test('an upload page wires the queue to the pending-uploads indicator', function () {
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $resp = $this->actingAs($admin)->get(route('departments.sections.show', [$department->levelAlias(), $department, $section]));
    $resp->assertOk();
    $resp->assertSee('pending-uploads-btn', false);
    $resp->assertSee('ResilientUpload.Queue', false);
});

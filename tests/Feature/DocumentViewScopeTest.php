<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function scopeDept(): Department
{
    return Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
}

function scopeSection(Department $department, string $name, string $slug): Section
{
    return Section::create(['department_id' => $department->id, 'name' => $name, 'slug' => $slug]);
}

function scopeDoc(Department $department, Section $section, string $title): Document
{
    Storage::fake('public');
    Storage::disk('public')->put('docs/'.$section->slug.'.pdf', 'x');

    return Document::create([
        'department_id'     => $department->id,
        'section_id'        => $section->id,
        'title'             => $title,
        'slug'              => Str::slug($title),
        'document_type'     => 'go',
        'original_filename' => 'test.pdf',
        'original_pdf_path' => 'docs/'.$section->slug.'.pdf',
        'status'            => 'verified',
        'visibility'        => 'public',
    ]);
}

test('a section-scoped admin cannot browse another section in the same department', function () {
    $department = scopeDept();
    $own        = scopeSection($department, 'Establishment', 'establishment');
    $other      = scopeSection($department, 'Camp Office', 'camp-office');

    $user = User::factory()->create([
        'role'          => 'admin',
        'department_id' => $department->id,
        'section_id'    => $own->id,
        'username'      => fake()->unique()->userName(),
    ]);

    $this->actingAs($user)
        ->get(route('departments.sections.show', [$department->levelAlias(), $department, $own]))
        ->assertOk();

    $this->actingAs($user)
        ->get(route('departments.sections.show', [$department->levelAlias(), $department, $other]))
        ->assertForbidden();
});

test('a department-scoped admin (e.g. a Commissioner) sees every section in their department', function () {
    $department = scopeDept();
    $sectionA   = scopeSection($department, 'Establishment', 'establishment');
    $sectionB   = scopeSection($department, 'Camp Office', 'camp-office');

    $ec = User::factory()->create([
        'role'          => 'admin',
        'department_id' => $department->id,
        'privileges'    => ['department.head'],
        'username'      => fake()->unique()->userName(),
    ]);

    $this->actingAs($ec)
        ->get(route('departments.sections.show', [$department->levelAlias(), $department, $sectionA]))
        ->assertOk();

    $this->actingAs($ec)
        ->get(route('departments.sections.show', [$department->levelAlias(), $department, $sectionB]))
        ->assertOk();
});

test('system_admin bypasses view-scoping entirely', function () {
    $department = scopeDept();
    $section    = scopeSection($department, 'Camp Office', 'camp-office');

    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $this->actingAs($admin)
        ->get(route('departments.sections.show', [$department->levelAlias(), $department, $section]))
        ->assertOk();
});

test('an unscoped user (no department/section/division assigned) still sees everything', function () {
    $department = scopeDept();
    $section    = scopeSection($department, 'Camp Office', 'camp-office');

    $viewer = User::factory()->create(['role' => 'viewer', 'username' => fake()->unique()->userName()]);

    $this->actingAs($viewer)
        ->get(route('departments.sections.show', [$department->levelAlias(), $department, $section]))
        ->assertOk();
});

test('pipeline monitor only lists documents within a section-scoped admin\'s own section', function () {
    $department = scopeDept();
    $own        = scopeSection($department, 'Establishment', 'establishment');
    $other      = scopeSection($department, 'Camp Office', 'camp-office');

    $ownDoc   = scopeDoc($department, $own, 'Own Section Order');
    $otherDoc = scopeDoc($department, $other, 'Other Section Order');
    $ownDoc->update(['status' => 'review']);
    $otherDoc->update(['status' => 'review']);

    $user = User::factory()->create([
        'role'          => 'admin',
        'department_id' => $department->id,
        'section_id'    => $own->id,
        'username'      => fake()->unique()->userName(),
    ]);

    $response = $this->actingAs($user)->get(route('documents.pipeline'));

    $response->assertOk();
    $response->assertSee('Own Section Order');
    $response->assertDontSee('Other Section Order');
});

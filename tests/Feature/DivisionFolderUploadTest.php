<?php

use App\Models\Department;
use App\Models\Division;
use App\Models\Folder;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('a division-level folder upload with only folder_id sent is authorized', function () {
    Storage::fake('public'); // real disk I/O would otherwise land in production storage
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $division   = Division::create(['section_id' => $section->id, 'department_id' => $department->id, 'name' => 'Performance Audit', 'slug' => 'performance-audit']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $folderResp = $this->actingAs($admin)->postJson(route('departments.sections.divisions.folders.store', [$department->levelAlias(), $department, $section, $division]), [
        'name' => 'MyPickedFolder',
        'find_or_create' => 1,
    ]);
    $folderResp->assertOk();
    $folderId = $folderResp->json('id');

    // Exactly what divisions/show.blade.php sends: folder_id ONLY, no section_id/division_id.
    $docResp = $this->actingAs($admin)->post(route('documents.store'), [
        'title' => 'Test Doc',
        'document_type' => 'go',
        'visibility' => 'public',
        'language' => 'english',
        'folder_id' => $folderId,
        'file' => UploadedFile::fake()->create('test.pdf', 100, 'application/pdf'),
    ]);

    $docResp->assertOk();
});

test('a mismatched division_id sent alongside a folder from a different division is still rejected', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $divisionA  = Division::create(['section_id' => $section->id, 'department_id' => $department->id, 'name' => 'Division A', 'slug' => 'division-a']);
    $divisionB  = Division::create(['section_id' => $section->id, 'department_id' => $department->id, 'name' => 'Division B', 'slug' => 'division-b']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $folder = Folder::create([
        'department_id' => $department->id, 'section_id' => $section->id, 'division_id' => $divisionA->id,
        'name' => 'A Folder', 'slug' => 'a-folder',
    ]);

    $docResp = $this->actingAs($admin)->post(route('documents.store'), [
        'title' => 'Test Doc', 'document_type' => 'go', 'visibility' => 'public', 'language' => 'english',
        'folder_id' => $folder->id, 'division_id' => $divisionB->id,
        'file' => UploadedFile::fake()->create('test.pdf', 100, 'application/pdf'),
    ]);
    $docResp->assertForbidden();
});

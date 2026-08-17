<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\Folder;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function visibilityDept(): Department
{
    return Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
}

function visibilitySection(Department $department): Section
{
    return Section::create(['department_id' => $department->id, 'name' => 'Enforcement', 'slug' => 'enforcement']);
}

function visibilityFolder(Department $department, Section $section, string $visibility): Folder
{
    return Folder::create([
        'department_id' => $department->id,
        'section_id'    => $section->id,
        'name'          => 'Confidential Circulars',
        'slug'          => 'confidential-circulars',
        'visibility'    => $visibility,
    ]);
}

function visibilityDoc(Department $department, Section $section, ?Folder $folder, string $visibility): Document
{
    return Document::create([
        'department_id'      => $department->id,
        'section_id'         => $section->id,
        'folder_id'          => $folder?->id,
        'title'              => 'Test Order',
        'slug'               => 'test-order-'.uniqid(),
        'document_type'      => 'go',
        'original_filename'  => 'test.pdf',
        'original_pdf_path'  => 'docs/test.pdf',
        'status'             => 'verified',
        'visibility'         => $visibility,
    ]);
}

test('isPubliclyVisible is false for a public document inside an authenticated folder', function () {
    $department = visibilityDept();
    $section    = visibilitySection($department);
    $folder     = visibilityFolder($department, $section, 'authenticated');
    $doc        = visibilityDoc($department, $section, $folder, 'public');

    expect($doc->isPubliclyVisible())->toBeFalse();
});

test('isPubliclyVisible is true for a public document inside a public folder or no folder', function () {
    $department  = visibilityDept();
    $section     = visibilitySection($department);
    $publicFolder = visibilityFolder($department, $section, 'public');

    $inPublicFolder = visibilityDoc($department, $section, $publicFolder, 'public');
    $noFolder       = visibilityDoc($department, $section, null, 'public');

    expect($inPublicFolder->isPubliclyVisible())->toBeTrue();
    expect($noFolder->isPubliclyVisible())->toBeTrue();
});

test('isPubliclyVisible is false for an authenticated document regardless of folder', function () {
    $department = visibilityDept();
    $section    = visibilitySection($department);
    $folder     = visibilityFolder($department, $section, 'public');
    $doc        = visibilityDoc($department, $section, $folder, 'authenticated');

    expect($doc->isPubliclyVisible())->toBeFalse();
});

test('a guest gets 403 viewing a public document mistakenly placed in an authenticated folder', function () {
    $department = visibilityDept();
    $section    = visibilitySection($department);
    $folder     = visibilityFolder($department, $section, 'authenticated');
    $doc        = visibilityDoc($department, $section, $folder, 'public');

    $this->get(route('documents.folders.show', [$department->levelAlias(), $department, $section, $folder, $doc]))
        ->assertForbidden();
});

test('a guest can view a public document in a public folder', function () {
    $department = visibilityDept();
    $section    = visibilitySection($department);
    $folder     = visibilityFolder($department, $section, 'public');
    $doc        = visibilityDoc($department, $section, $folder, 'public');

    $this->get(route('documents.folders.show', [$department->levelAlias(), $department, $section, $folder, $doc]))
        ->assertOk();
});

test('guest search excludes a public document inside an authenticated folder', function () {
    $department = visibilityDept();
    $section    = visibilitySection($department);
    $folder     = visibilityFolder($department, $section, 'authenticated');
    $doc        = visibilityDoc($department, $section, $folder, 'public');

    $this->get(route('search.index', ['q' => 'Test Order']))
        ->assertOk()
        ->assertDontSee($doc->slug);
});

test('sitemap excludes a public document inside an authenticated folder', function () {
    $department = visibilityDept();
    $section    = visibilitySection($department);
    $folder     = visibilityFolder($department, $section, 'authenticated');
    $doc        = visibilityDoc($department, $section, $folder, 'public');
    $doc->update(['status' => 'verified']);

    $xml = $this->get(route('sitemap'))->assertOk()->getContent();

    expect($xml)->not->toContain($doc->slug);
});

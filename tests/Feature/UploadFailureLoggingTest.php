<?php

use App\Models\Department;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// A disk write can fail silently (permission denied, disk full — 'public' disk is configured
// with throw=>false) — DocumentController must log the real context when that happens, not just
// hand the user a generic message with nothing to diagnose from. See config/filesystems.php.
test('a failed disk write is logged with context, not just swallowed', function () {
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    // Storage::fake() points 'public' at an isolated, disposable directory (not real production
    // storage) — locking that down to read-only reproduces a genuine permission-denied write
    // failure, the actual failure mode this is meant to catch, without touching real disk I/O.
    Storage::fake('public');
    $vaultDir = storage_path('framework/testing/disks/public/document_vault/department_level/excise/account');
    mkdir($vaultDir, 0777, true);
    chmod($vaultDir, 0555);

    Log::spy();

    $resp = $this->actingAs($admin)->post(route('documents.store'), [
        'title' => 'Test Doc', 'document_type' => 'go', 'visibility' => 'public', 'language' => 'english',
        'section_id' => $section->id,
        'file' => UploadedFile::fake()->create('test.pdf', 100, 'application/pdf'),
    ]);

    chmod($vaultDir, 0777); // restore before teardown so RefreshDatabase/temp cleanup can remove it

    $resp->assertStatus(500);
    $resp->assertJson(['message' => 'File could not be saved. Please try again.']);
    Log::shouldHaveReceived('error')->withArgs(function ($message, $context) {
        return $message === 'Document upload: file could not be saved to disk'
            && isset($context['vault_dir'], $context['original_filename']);
    })->once();
});

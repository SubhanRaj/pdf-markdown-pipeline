<?php

use App\Models\Department;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// A second user who reuses (guesses/observes) someone else's upload_id must not be able to
// append a chunk into that in-progress upload — see the `.owner` check in
// DocumentController::storeChunk().
test('a user cannot append a chunk to another user\'s in-progress chunked upload', function () {
    Storage::fake('local');
    Queue::fake();

    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Enforcement', 'slug' => 'enforcement']);

    $owner    = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);
    $attacker = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $uploadId = (string) Illuminate\Support\Str::uuid();
    $fields   = [
        'title'          => 'Test Order',
        'document_type'  => 'go',
        'section_id'     => $section->id,
        'upload_id'      => $uploadId,
        'total_chunks'   => 2,
    ];

    $this->actingAs($owner)
        ->post(route('documents.store-chunk'), [
            ...$fields,
            'chunk_index' => 0,
            'file'        => Illuminate\Http\UploadedFile::fake()->create('0.pdf', 10, 'application/pdf'),
        ])->assertOk();

    $this->actingAs($attacker)
        ->post(route('documents.store-chunk'), [
            ...$fields,
            'chunk_index' => 1,
            'file'        => Illuminate\Http\UploadedFile::fake()->create('1.pdf', 10, 'application/pdf'),
        ])->assertNotFound();
});

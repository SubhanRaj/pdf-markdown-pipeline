<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function makeReviewDocument(array $overrides = []): Document
{
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);

    Storage::fake('public');
    Storage::disk('public')->put('docs/sample.md', '# Sample');

    return Document::create(array_merge([
        'department_id'      => $department->id,
        'title'              => 'Sample Order',
        'slug'               => 'sample-order',
        'document_type'      => 'go',
        'original_filename'  => 'sample.pdf',
        'original_pdf_path'  => 'docs/sample.pdf',
        'markdown_path'      => 'docs/sample.md',
        'status'             => 'review',
    ], $overrides));
}

test('admin can verify a document awaiting review', function () {
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);
    $document = makeReviewDocument();

    $response = $this->actingAs($admin)->post(route('documents.verify', $document->id));

    $response->assertOk();
    $response->assertJson(['status' => 'verified']);
    expect($document->fresh()->status)->toBe('verified');
});

test('non-admin cannot verify a document', function () {
    $viewer = User::factory()->create(['role' => 'viewer', 'username' => fake()->unique()->userName()]);
    $document = makeReviewDocument();

    $this->actingAs($viewer)->post(route('documents.verify', $document->id))
        ->assertForbidden();

    expect($document->fresh()->status)->toBe('review');
});

test('a document not awaiting review cannot be verified', function () {
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);
    $document = makeReviewDocument(['status' => 'uploaded']);

    $this->actingAs($admin)->post(route('documents.verify', $document->id))
        ->assertStatus(422);

    expect($document->fresh()->status)->toBe('uploaded');
});

test('a review document with no markdown draft cannot be verified', function () {
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);
    $document = makeReviewDocument(['markdown_path' => null]);

    $this->actingAs($admin)->post(route('documents.verify', $document->id))
        ->assertStatus(422);
});

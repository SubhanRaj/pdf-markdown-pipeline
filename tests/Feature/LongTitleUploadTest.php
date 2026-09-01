<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// 2026-09-01: a long Devanagari title produced an uncapped slug (HasUnicodeSlug
// keeps original script instead of transliterating), and Devanagari is 3 bytes
// per character in UTF-8 -- a normal-length human sentence blew past ext4's
// 255-byte NAME_MAX for the stored filename, so file_put_contents() failed
// silently and the upload returned "File could not be saved."
test('a long Devanagari title still produces a filesystem-safe stored filename', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $longTitle = 'शासन द्वारा निर्गत दण्डादेश का आदेश व आयुक्त महोदय द्वारा विभागीय कार्यवाही समाप्त किये जाने';
    $file = UploadedFile::fake()->create('order.pdf', 200, 'application/pdf');

    $resp = $this->actingAs($admin)->post(route('documents.store'), [
        'title' => $longTitle, 'document_type' => 'go', 'visibility' => 'public', 'language' => 'hindi',
        'section_id' => $section->id, 'file' => $file,
    ]);
    $resp->assertOk();

    $document = Document::findOrFail($resp->json('document_id'));
    expect(strlen($document->slug))->toBeLessThanOrEqual(150);
    expect(Storage::disk('public')->exists($document->original_pdf_path))->toBeTrue();
});

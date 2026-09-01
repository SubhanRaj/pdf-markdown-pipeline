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
//
// Fix decouples the DB slug/URL (kept full length, up to the title's own 255-
// char validation cap -- no filesystem constraint applies there) from the
// physical stored filename, which runs through Document::slugForFilename()
// for its own, separately byte-capped truncation. See HasUnicodeSlug.
test('a near-max-length Devanagari title keeps its full slug but a filesystem-safe filename', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    // 250 Devanagari characters -- close to the title's max:255 validation cap, and
    // 3x the byte length that would fit raw in a 255-byte filename component.
    $word      = 'शासनद्वारानिर्गतदण्डादेशकाआदेश ';
    $longTitle = mb_substr(str_repeat($word, 10), 0, 250);
    expect(mb_strlen($longTitle))->toBe(250);

    $file = UploadedFile::fake()->create('order.pdf', 200, 'application/pdf');

    $resp = $this->actingAs($admin)->post(route('documents.store'), [
        'title' => $longTitle, 'document_type' => 'go', 'visibility' => 'public', 'language' => 'hindi',
        'section_id' => $section->id, 'file' => $file,
    ]);
    $resp->assertOk();

    $document = Document::findOrFail($resp->json('document_id'));

    // The DB slug/URL is essentially the full title, untruncated.
    expect(mb_strlen($document->slug))->toBeGreaterThan(200);

    // But the actual stored filename component is safely under ext4's 255-byte limit.
    $filename = basename($document->original_pdf_path);
    expect(strlen($filename))->toBeLessThanOrEqual(255);
    expect(Storage::disk('public')->exists($document->original_pdf_path))->toBeTrue();
});

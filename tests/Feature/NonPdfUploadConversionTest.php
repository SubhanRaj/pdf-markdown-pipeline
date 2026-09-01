<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Word/Excel/PowerPoint/... (2026-09-01): no LibreOffice PDF round-trip on upload — the original
// is kept as-is (native_path) and converted straight to Markdown via markitdown, no OCR. See
// Document::isNativeFormat() and DocumentController::createDocumentFromUpload().
function uploadNative(User $admin, Section $section, string $fixture, string $filename, string $mime): Document
{
    $file = new UploadedFile(__DIR__ . '/../Fixtures/' . $fixture, $filename, $mime, null, true);

    $resp = test()->actingAs($admin)->post(route('documents.store'), [
        'title' => 'Test Doc', 'document_type' => 'go', 'visibility' => 'public', 'language' => 'english',
        'section_id' => $section->id, 'file' => $file,
    ]);
    $resp->assertOk();

    return Document::findOrFail($resp->json('document_id'));
}

test('a multi-sheet xlsx upload keeps the original and skips PDF conversion', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $document = uploadNative($admin, $section, 'sample-multisheet.xlsx', 'budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    expect($document->original_format)->toBe('xlsx');
    expect($document->isNativeFormat())->toBeTrue();
    expect($document->original_pdf_path)->toBeNull();
    expect($document->native_path)->not->toBeNull();
    expect(Storage::disk('public')->exists($document->native_path))->toBeTrue();
});

test('converting a multi-sheet xlsx produces Markdown with every sheet, no OCR queued', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $document = uploadNative($admin, $section, 'sample-multisheet.xlsx', 'budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

    $this->actingAs($admin)->post(route('documents.convert', $document->id))->assertOk();

    (new App\Jobs\ConvertDocumentToMarkdown($document->id))->handle();

    $document->refresh();
    expect($document->status)->toBe('review'); // never 'ocr_pending' — nothing scanned to OCR
    expect($document->metadata['extraction_method'])->toBe('markitdown-native');
    expect($document->markdown_path)->not->toBeNull();

    $markdown = Storage::disk('public')->get($document->markdown_path);
    expect($markdown)->toContain('Revenue Sheet')->toContain('Expenses Sheet');
});

test('a docx upload converts straight to Markdown via markitdown', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $document = uploadNative($admin, $section, 'sample.docx', 'circular.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($document->isNativeFormat())->toBeTrue();

    (new App\Jobs\ConvertDocumentToMarkdown($document->id))->handle();

    $document->refresh();
    expect($document->status)->toBe('review');
    expect(Storage::disk('public')->get($document->markdown_path))->toContain('Sample Word document body text');
});

test('convertToPdf generates a real PDF for a native-format document on request', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $document = uploadNative($admin, $section, 'sample.docx', 'circular.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($document->original_pdf_path)->toBeNull();

    $resp = $this->actingAs($admin)->post(route('documents.convert-to-pdf', $document->id));
    $resp->assertOk();

    $document->refresh();
    expect($document->original_pdf_path)->not->toBeNull();
    expect(Storage::disk('public')->exists($document->original_pdf_path))->toBeTrue();
    // native_path and original_format are untouched -- still the same Word document underneath
    expect($document->native_path)->not->toBeNull();
    expect($document->original_format)->toBe('docx');
});

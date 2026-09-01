<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Runs the real `soffice --headless --convert-to pdf` conversion — see
// DocumentController::createDocumentFromUpload() — no mocking, since a fake/empty file wouldn't
// exercise the actual conversion this is meant to guard. Word and Excel are both explicitly
// accepted upload types (StoreDocumentRequest::ACCEPTED_MIMETYPES); a multi-sheet workbook is
// used deliberately, since "does it lose sheets on conversion" was the reported concern.
test('a multi-sheet xlsx converts to a PDF with every sheet present', function () {
    Storage::fake('public'); // isolated disposable disk — soffice's real output still lands on a real path, just not production storage
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $file = new UploadedFile(
        __DIR__ . '/../Fixtures/sample-multisheet.xlsx',
        'budget.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null, true
    );

    $resp = $this->actingAs($admin)->post(route('documents.store'), [
        'title' => 'Test Budget', 'document_type' => 'go', 'visibility' => 'public', 'language' => 'english',
        'section_id' => $section->id, 'file' => $file,
    ]);

    $resp->assertOk();
    $document = Document::findOrFail($resp->json('document_id'));
    $pdfPath  = Storage::disk('public')->path($document->original_pdf_path);

    expect(str_ends_with($document->original_pdf_path, '.pdf'))->toBeTrue();
    expect(is_file($pdfPath))->toBeTrue();

    $text = shell_exec('pdftotext ' . escapeshellarg($pdfPath) . ' -');
    expect($text)->toContain('Revenue Sheet')->toContain('Expenses Sheet');
});

test('a docx converts to a PDF', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $file = new UploadedFile(
        __DIR__ . '/../Fixtures/sample.docx',
        'circular.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        null, true
    );

    $resp = $this->actingAs($admin)->post(route('documents.store'), [
        'title' => 'Test Circular', 'document_type' => 'go', 'visibility' => 'public', 'language' => 'english',
        'section_id' => $section->id, 'file' => $file,
    ]);

    $resp->assertOk();
    $document = Document::findOrFail($resp->json('document_id'));
    expect(is_file(Storage::disk('public')->path($document->original_pdf_path)))->toBeTrue();
});

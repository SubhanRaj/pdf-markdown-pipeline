<?php

use App\Jobs\ConvertQuickConversionToMarkdown;
use App\Models\QuickConversion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Before 2026-09-01, QuickConversionController@store had NO non-PDF handling at all despite
// StoreQuickConversionRequest accepting Word/Excel/images — a .xlsx got silently renamed to
// "original.pdf" (still xlsx bytes) and the conversion job's pdfminer/Docling pass on it was
// guaranteed to fail. This is very likely what the "New Conversion" .xlsx upload failure was.
test('an xlsx uploaded via New Conversion keeps its native format and converts via markitdown', function () {
    Storage::fake('public');
    // Both jobs store() dispatches run synchronously under the test env's sync queue driver,
    // which ignores delay() — without this, PruneQuickConversion runs immediately too and
    // deletes the row before the assertions below ever see it.
    Queue::fake();
    $user = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $file = new UploadedFile(
        __DIR__ . '/../Fixtures/sample-multisheet.xlsx', 'budget.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true
    );

    $resp = $this->actingAs($user)->post(route('conversions.store'), ['title' => 'Budget', 'file' => $file]);
    $resp->assertOk();

    $quickConversion = QuickConversion::firstOrFail();
    expect($quickConversion->isNativeFormat())->toBeTrue();
    expect($quickConversion->original_format)->toBe('xlsx');
    expect($quickConversion->pdf_path)->toBeNull();
    expect($quickConversion->native_path)->not->toBeNull();
    expect(Storage::disk('public')->exists($quickConversion->native_path))->toBeTrue();

    (new ConvertQuickConversionToMarkdown($quickConversion->id))->handle();

    $quickConversion->refresh();
    expect($quickConversion->status)->toBe('review');
    expect($quickConversion->metadata['extraction_method'])->toBe('markitdown-native');
    expect(Storage::disk('public')->get($quickConversion->markdown_path))->toContain('Revenue Sheet');
});


test('a native quick conversion can be placed into a real Document', function () {
    Storage::fake('public');
    Queue::fake();
    $user = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);
    $department = \App\Models\Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $section    = \App\Models\Section::create(['department_id' => $department->id, 'name' => 'Account', 'slug' => 'account']);

    $file = new UploadedFile(__DIR__ . '/../Fixtures/sample-multisheet.xlsx', 'budget.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    $this->actingAs($user)->post(route('conversions.store'), ['title' => 'Budget', 'file' => $file])->assertOk();

    $quickConversion = QuickConversion::firstOrFail();
    (new ConvertQuickConversionToMarkdown($quickConversion->id))->handle();
    $quickConversion->refresh();

    $resp = $this->actingAs($user)->post(route('conversions.place', $quickConversion), [
        'title' => 'Budget FY26', 'document_type' => 'go', 'visibility' => 'public', 'language' => 'english',
        'section_id' => $section->id,
    ]);
    $resp->assertOk();

    $document = \App\Models\Document::whereKey($resp->json('document_id'))->firstOrFail();
    expect($document->isNativeFormat())->toBeTrue();
    expect($document->native_path)->not->toBeNull();
    expect(Storage::disk('public')->exists($document->native_path))->toBeTrue();
    expect($document->markdown_path)->not->toBeNull();
    expect(\App\Models\QuickConversion::find($quickConversion->id))->toBeNull();
});

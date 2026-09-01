<?php

use App\Models\Department;
use App\Models\Document;
use App\Models\RuleSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// PolicyDocumentController@store had the same gap as QuickConversionController@store before
// 2026-09-01: it always stored the upload as "{slug}_{timestamp}.pdf" regardless of the real
// file type, with no LibreOffice conversion anywhere in this controller. A Word/Excel policy
// upload would have failed the same way.
test('an xlsx uploaded as a policy document keeps its native format', function () {
    Storage::fake('public');
    $department = Department::create(['name' => 'Excise', 'slug' => 'excise', 'level' => 'department_level']);
    $policy = RuleSet::create([
        'department_id' => $department->id, 'name' => 'UP Excise Policy', 'slug' => 'up-excise-policy',
        'kind' => 'policy', 'state' => 'up', 'policy_type' => 'excise',
    ]);
    $admin = User::factory()->create(['role' => 'system_admin', 'username' => fake()->unique()->userName()]);

    $file = new UploadedFile(
        __DIR__ . '/../Fixtures/sample-multisheet.xlsx', 'policy-budget.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true
    );

    $resp = $this->actingAs($admin)->post(
        route('departments.policy.periods.store', [$department->levelAlias(), $department, $policy]),
        ['name' => 'Excise Policy 2026-27', 'file' => $file]
    );
    $resp->assertRedirect();

    $policyDoc = RuleSet::where('container_id', $policy->id)->firstOrFail();
    $document  = Document::where('rule_set_id', $policyDoc->id)->firstOrFail();

    expect($document->isNativeFormat())->toBeTrue();
    expect($document->original_format)->toBe('xlsx');
    expect($document->original_pdf_path)->toBeNull();
    expect($document->native_path)->not->toBeNull();
    expect(Storage::disk('public')->exists($document->native_path))->toBeTrue();
});

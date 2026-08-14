<?php

use App\Models\Document;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function approvalDoc(array $overrides = []): Document
{
    $section = Section::factory()->create();

    return Document::factory()->create(array_merge([
        'department_id' => $section->department_id,
        'section_id'    => $section->id,
        'status'        => 'pending_approval',
    ], $overrides));
}

test('approve requires the same authorization as the controller action', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $doc    = approvalDoc();

    Livewire::actingAs($viewer)
        ->test('approval-actions')
        ->call('approve', $doc->id)
        ->assertStatus(403);

    expect($doc->fresh()->status)->toBe('pending_approval');
});

test('approve transitions a pending document to uploaded', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $doc   = approvalDoc();

    Livewire::actingAs($admin)
        ->test('approval-actions')
        ->call('approve', $doc->id, 'Looks good')
        ->assertReturned(['ok' => true]);

    expect($doc->fresh()->status)->toBe('uploaded');
});

test('reject requires the same authorization as the controller action', function () {
    $viewer = User::factory()->create(['role' => 'viewer']);
    $doc    = approvalDoc();

    Livewire::actingAs($viewer)
        ->test('approval-actions')
        ->call('reject', $doc->id, 'Missing required pages')
        ->assertStatus(403);

    expect($doc->fresh()->status)->toBe('pending_approval');
});

test('reject transitions a pending document to rejected with a note', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $doc   = approvalDoc();

    Livewire::actingAs($admin)
        ->test('approval-actions')
        ->call('reject', $doc->id, 'Missing required pages')
        ->assertReturned(['ok' => true]);

    expect($doc->fresh()->status)->toBe('rejected');
});

test('reject returns a validation failure instead of throwing when the reason is too short', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $doc   = approvalDoc();

    $result = Livewire::actingAs($admin)
        ->test('approval-actions')
        ->call('reject', $doc->id, 'no');

    expect($result->effects['returns'][0]['ok'])->toBeFalse();
    expect($doc->fresh()->status)->toBe('pending_approval');
});

test('resubmit requires ownership or admin, same as the controller action', function () {
    $owner  = User::factory()->create(['role' => 'operator']);
    $other  = User::factory()->create(['role' => 'operator']);
    $doc    = approvalDoc(['status' => 'rejected', 'user_id' => $owner->id]);

    Livewire::actingAs($other)
        ->test('approval-actions')
        ->call('resubmit', $doc->id)
        ->assertStatus(403);

    expect($doc->fresh()->status)->toBe('rejected');
});

test('resubmit transitions a rejected document back to pending approval', function () {
    $owner = User::factory()->create(['role' => 'operator']);
    $doc   = approvalDoc(['status' => 'rejected', 'user_id' => $owner->id]);

    Livewire::actingAs($owner)
        ->test('approval-actions')
        ->call('resubmit', $doc->id)
        ->assertReturned(['ok' => true]);

    expect($doc->fresh()->status)->toBe('pending_approval');
});

test('reclassify moves a document to a new section and can approve it in the same step', function () {
    $admin      = User::factory()->create(['role' => 'admin']);
    $doc        = approvalDoc();
    $newSection = Section::factory()->create(['department_id' => $doc->department_id]);

    Livewire::actingAs($admin)
        ->test('approval-actions')
        ->call('reclassify', $doc->id, [
            'new_section_id' => (string) $newSection->id,
            'approve'        => '1',
        ])
        ->assertReturned(['ok' => true]);

    $fresh = $doc->fresh();
    expect($fresh->section_id)->toBe($newSection->id);
    expect($fresh->status)->toBe('uploaded');
});

test('bulkApprove processes every id and reports failures without aborting the batch', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $one   = approvalDoc();
    $two   = approvalDoc();

    Livewire::actingAs($admin)
        ->test('approval-actions')
        ->call('bulkApprove', [$one->id, $two->id])
        ->assertReturned(['ok' => true, 'failed' => 0]);

    expect($one->fresh()->status)->toBe('uploaded');
    expect($two->fresh()->status)->toBe('uploaded');
});

test('bulkReject processes every id with the same reason', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $one   = approvalDoc();
    $two   = approvalDoc();

    Livewire::actingAs($admin)
        ->test('approval-actions')
        ->call('bulkReject', [$one->id, $two->id], 'Duplicate submissions')
        ->assertReturned(['ok' => true, 'failed' => 0]);

    expect($one->fresh()->status)->toBe('rejected');
    expect($two->fresh()->status)->toBe('rejected');
});

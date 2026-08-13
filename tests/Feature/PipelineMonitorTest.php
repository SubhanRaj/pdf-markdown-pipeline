<?php

use App\Models\Document;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function pipelineDoc(array $overrides = []): Document
{
    $section = Section::factory()->create();

    return Document::factory()->create(array_merge([
        'department_id' => $section->department_id,
        'section_id'    => $section->id,
    ], $overrides));
}

test('component lists only pipeline-status documents and filters by the active status tab', function () {
    $admin     = User::factory()->create(['role' => 'admin']);
    $uploaded  = pipelineDoc(['status' => 'uploaded', 'title' => 'Uploaded Doc Title']);
    $failed    = pipelineDoc(['status' => 'failed', 'title' => 'Failed Doc Title']);
    $verified  = pipelineDoc(['status' => 'verified', 'title' => 'Verified Doc Title']); // not in the pipeline — must be excluded

    Livewire::actingAs($admin)
        ->test('pipeline-monitor')
        ->assertSee($uploaded->title)
        ->assertSee($failed->title)
        ->assertDontSee($verified->title);

    Livewire::actingAs($admin)
        ->test('pipeline-monitor', ['activeStatus' => 'failed'])
        ->assertSee($failed->title)
        ->assertDontSee($uploaded->title);
});

test('wire:poll is only present while a document is processing or awaiting OCR', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    pipelineDoc(['status' => 'uploaded']);

    Livewire::actingAs($admin)
        ->test('pipeline-monitor')
        ->assertDontSeeHtml('wire:poll');

    pipelineDoc(['status' => 'processing']);

    Livewire::actingAs($admin)
        ->test('pipeline-monitor')
        ->assertSeeHtml('wire:poll');
});

test('convert requires the same admin/department-head authorization as the controller action', function () {
    $viewer  = User::factory()->create(['role' => 'viewer']);
    $doc     = pipelineDoc(['status' => 'uploaded']);

    Livewire::actingAs($viewer)
        ->test('pipeline-monitor')
        ->call('convert', $doc->id)
        ->assertStatus(403);
});

test('convert transitions an admin-owned document to processing and dispatches conversion', function () {
    Illuminate\Support\Facades\Storage::fake('public');
    Illuminate\Support\Facades\Queue::fake();

    $admin = User::factory()->create(['role' => 'admin']);
    $doc   = pipelineDoc(['status' => 'uploaded']);
    Illuminate\Support\Facades\Storage::disk('public')->put($doc->original_pdf_path, 'pdf-bytes');

    Livewire::actingAs($admin)
        ->test('pipeline-monitor')
        ->call('convert', $doc->id)
        ->assertHasNoErrors();

    expect($doc->fresh()->status)->toBe('processing');
    Illuminate\Support\Facades\Queue::assertPushed(App\Jobs\ConvertDocumentToMarkdown::class);
});

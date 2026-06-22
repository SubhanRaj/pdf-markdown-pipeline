<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeleteDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function index(): View
    {
        $query = Document::with(['department', 'section', 'ruleSet', 'user:id,name'])
            ->orderByDesc('created_at');

        if (! auth()->check()) {
            $query->where('visibility', 'public');
        }

        $byDepartment = $query->get()->groupBy('department_id');

        return view('documents.index', compact('byDepartment'));
    }

    public function show(string $level, Department $department, Section $section, Document $document): View
    {
        if (! auth()->check() && $document->visibility !== 'public') {
            abort(403);
        }

        $document->load(['user:id,name', 'statusHistory.actor:id,name']);
        return view('documents.show', compact('document', 'department', 'section'));
    }

    public function pdf(string $level, Department $department, Section $section, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! auth()->check() && $document->visibility !== 'public') {
            abort(403);
        }

        if (! $document->original_pdf_path || ! Storage::disk('public')->exists($document->original_pdf_path)) {
            abort(404, 'PDF file not found.');
        }

        $filename = $document->original_filename ?: 'document.pdf';

        return Storage::disk('public')->response(
            $document->original_pdf_path,
            $filename,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $filename . '"']
        );
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // ── Resolve context: section-based upload vs rule-set amendment upload ──
        if (! empty($validated['rule_set_id'])) {
            $ruleSet    = RuleSet::with('department')->findOrFail($validated['rule_set_id']);
            $department = $ruleSet->department;
            $section    = null;

            $vaultDir = implode('/', [
                'document_vault',
                $department->level,
                $department->slug,
                'rules',
                $ruleSet->slug,
            ]);

            $slug = Document::uniqueSlugForRuleSet($validated['title'], $ruleSet->id);
        } else {
            $section    = Section::with('department')->findOrFail($validated['section_id']);
            $department = $section->department;
            $ruleSet    = null;

            $vaultDir = implode('/', array_filter([
                'document_vault',
                $department->level,
                $department->slug,
                $section->wing,
                $section->slug,
            ]));

            $slug = Document::uniqueSlugForSection($validated['title'], $section->id);
        }

        $timestamp = now()->format('YmdHis');
        $pdfPath   = $request->file('file')->storeAs($vaultDir, "{$slug}_{$timestamp}.pdf", 'public');

        if (! $pdfPath) {
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        try {
            $document = null;

            DB::transaction(function () use ($validated, $section, $ruleSet, $department, $vaultDir, $pdfPath, $slug, $request, &$document) {
                $document = Document::create([
                    'department_id'     => $department->id,
                    'section_id'        => $section?->id,
                    'rule_set_id'       => $ruleSet?->id,
                    'user_id'           => $request->user()->id,
                    'title'             => $validated['title'],
                    'slug'              => $slug,
                    'document_type'     => $validated['document_type'],
                    'original_filename' => $request->file('file')->getClientOriginalName(),
                    'original_pdf_path' => $pdfPath,
                    'vault_path'        => $vaultDir,
                    'status'            => 'uploaded',
                    'visibility'        => $validated['visibility'] ?? 'public',
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => $request->user()->id,
                    'from_status' => null,
                    'to_status'   => 'uploaded',
                    'note'        => 'Document uploaded.',
                ]);
            });

            flash()->success("\"{$validated['title']}\" uploaded successfully.");

            $redirectUrl = $ruleSet
                ? route('departments.rules.show', [$department->levelAlias(), $department, $ruleSet])
                : route('departments.sections.show', [$department->levelAlias(), $department, $section]);

            return response()->json(['redirect' => $redirectUrl]);

        } catch (\Throwable $e) {
            Storage::disk('public')->delete($pdfPath);

            Log::error('DocumentController@store failed', [
                'section_id'  => $validated['section_id'] ?? null,
                'rule_set_id' => $validated['rule_set_id'] ?? null,
                'error'       => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Upload failed. Please try again.'], 500);
        }
    }

    public function edit(string $level, Department $department, Section $section, Document $document): View
    {
        return view('documents.edit', compact('document', 'department', 'section'));
    }

    public function update(UpdateDocumentRequest $request, string $level, Department $department, Section $section, Document $document): RedirectResponse
    {
        $validated = $request->validated();
        $oldStatus = $document->status;

        try {
            DB::transaction(function () use ($validated, $document, $oldStatus) {
                $document->update($validated);
                if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
                    DocumentStatusHistory::create([
                        'document_id' => $document->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => $oldStatus,
                        'to_status'   => $validated['status'],
                        'note'        => 'Status updated via document edit.',
                    ]);
                }
            });
            flash()->success('Document updated successfully.');
            return redirect()->route('documents.show', [$department->levelAlias(), $department, $section, $document]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@update failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            flash()->error('Failed to update document. Please try again.');
            return back()->withInput();
        }
    }

    public function destroy(DeleteDocumentRequest $request, string $level, Department $department, Section $section, Document $document): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $document) {
                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => $document->status,
                    'to_status'   => 'deleted',
                    'note'        => $request->validated('reason'),
                ]);
                $document->delete();
            });

            flash()->success('Document moved to trash.');
            return redirect()->route('departments.sections.show', [$department->levelAlias(), $department, $section]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@destroy failed', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to delete document. Please try again.');
            return back();
        }
    }

    public function trash(): View
    {
        $documents = Document::withTrashed()
            ->onlyTrashed()
            ->with([
                'department',
                'section',
                'ruleSet',
                'user:id,name',
                'statusHistory' => fn ($q) => $q->where('to_status', 'deleted')->with('actor:id,name')->latest('created_at'),
            ])
            ->orderByDesc('deleted_at')
            ->get();

        $trashData = $documents->map(function ($doc) {
            $statusEntry = $doc->statusHistory->first();
            return [
                'id'              => $doc->id,
                'title'           => $doc->title,
                'document_type'   => Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type,
                'status'          => $doc->status,
                'visibility'      => $doc->visibility,
                'department'      => $doc->department->name,
                'context_name'    => $doc->section?->name ?? $doc->ruleSet?->name ?? '—',
                'context_type'    => $doc->section_id ? 'Section' : 'Rule Set',
                'uploaded_by'     => $doc->user?->name ?? '—',
                'uploaded_at'     => $doc->created_at->format('d M Y, H:i'),
                'deleted_at'      => $doc->deleted_at->format('d M Y, H:i'),
                'deleted_by'      => $statusEntry?->actor?->name ?? '—',
                'deletion_reason' => $statusEntry?->note ?? '—',
                'pdf_url'         => $doc->original_pdf_path ? route('documents.trashed.pdf', $doc->id) : null,
                'restore_url'     => route('documents.restore', $doc->id),
                'destroy_url'     => route('documents.force-destroy', $doc->id),
                'is_admin'        => auth()->user()->isAdmin(),
            ];
        });

        return view('documents.trash', compact('documents', 'trashData'));
    }

    public function trashedPdf(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $document = Document::onlyTrashed()->findOrFail($id);

        if (! $document->original_pdf_path || ! Storage::disk('public')->exists($document->original_pdf_path)) {
            abort(404, 'PDF file not found.');
        }

        return Storage::disk('public')->response(
            $document->original_pdf_path,
            null,
            ['Content-Disposition' => 'inline; filename="' . basename($document->original_pdf_path) . '"']
        );
    }

    public function restore(int $id): RedirectResponse
    {
        $document = Document::withTrashed()->findOrFail($id);

        if (! $document->trashed()) {
            return redirect()->route('documents.trash');
        }

        try {
            DB::transaction(function () use ($document) {
                $document->restore();
                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => 'deleted',
                    'to_status'   => $document->status,
                    'note'        => 'Restored from trash.',
                ]);
            });

            flash()->success('Document restored successfully.');
            return redirect()->route('documents.trash');
        } catch (\Throwable $e) {
            Log::error('DocumentController@restore failed', ['document_id' => $id, 'error' => $e->getMessage()]);
            flash()->error('Failed to restore document. Please try again.');
            return back();
        }
    }

    public function forceDestroy(int $id): RedirectResponse
    {
        if (! auth()->user()->isAdmin()) {
            abort(403);
        }

        $document = Document::withTrashed()->findOrFail($id);

        try {
            DB::transaction(function () use ($document) {
                if ($document->original_pdf_path) {
                    Storage::disk('public')->delete($document->original_pdf_path);
                }
                if ($document->markdown_path) {
                    Storage::disk('public')->delete($document->markdown_path);
                }
                $document->forceDelete();
            });

            flash()->success('Document permanently deleted and files removed.');
            return redirect()->route('documents.trash');
        } catch (\Throwable $e) {
            Log::error('DocumentController@forceDestroy failed', ['document_id' => $id, 'error' => $e->getMessage()]);
            flash()->error('Failed to permanently delete document. Please try again.');
            return back();
        }
    }

    // ── Rule-set document variants ─────────────────────────────────────────────

    public function showRuleSetDoc(string $level, Department $department, RuleSet $ruleSet, Document $document): View
    {
        if (! auth()->check() && $document->visibility !== 'public') {
            abort(403);
        }

        $document->load(['user:id,name', 'statusHistory.actor:id,name']);
        return view('documents.show', compact('document', 'department', 'ruleSet'));
    }

    public function pdfRuleSetDoc(string $level, Department $department, RuleSet $ruleSet, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! auth()->check() && $document->visibility !== 'public') {
            abort(403);
        }

        if (! $document->original_pdf_path || ! Storage::disk('public')->exists($document->original_pdf_path)) {
            abort(404, 'PDF file not found.');
        }

        $filename = $document->original_filename ?: 'document.pdf';

        return Storage::disk('public')->response(
            $document->original_pdf_path,
            $filename,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $filename . '"']
        );
    }

    public function editRuleSetDoc(string $level, Department $department, RuleSet $ruleSet, Document $document): View
    {
        return view('documents.edit', compact('document', 'department', 'ruleSet'));
    }

    public function updateRuleSetDoc(UpdateDocumentRequest $request, string $level, Department $department, RuleSet $ruleSet, Document $document): RedirectResponse
    {
        $validated = $request->validated();
        $oldStatus = $document->status;

        try {
            DB::transaction(function () use ($validated, $document, $oldStatus) {
                $document->update($validated);
                if (isset($validated['status']) && $validated['status'] !== $oldStatus) {
                    DocumentStatusHistory::create([
                        'document_id' => $document->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => $oldStatus,
                        'to_status'   => $validated['status'],
                        'note'        => 'Status updated via document edit.',
                    ]);
                }
            });
            flash()->success('Document updated successfully.');
            return redirect()->route('documents.rules.show', [$department->levelAlias(), $department, $ruleSet, $document]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@updateRuleSetDoc failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            flash()->error('Failed to update document. Please try again.');
            return back()->withInput();
        }
    }

    public function destroyRuleSetDoc(DeleteDocumentRequest $request, string $level, Department $department, RuleSet $ruleSet, Document $document): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $document) {
                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => $document->status,
                    'to_status'   => 'deleted',
                    'note'        => $request->validated('reason'),
                ]);
                $document->delete();
            });

            flash()->success('Document moved to trash.');
            return redirect()->route('departments.rules.show', [$department->levelAlias(), $department, $ruleSet]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@destroyRuleSetDoc failed', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to delete document. Please try again.');
            return back();
        }
    }
}

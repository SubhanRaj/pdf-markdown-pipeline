<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkDeleteDocumentsRequest;
use App\Http\Requests\BulkRestoreDocumentsRequest;
use App\Http\Requests\BulkForceDestroyDocumentsRequest;
use App\Http\Requests\DeleteDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Department;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function index(): View
    {
        $query = Document::with(['department', 'section', 'division', 'ruleSet', 'user:id,name'])
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

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at']);
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

        // ── Resolve context: rule-set, division, or direct section upload ──
        $ruleSet  = null;
        $division = null;

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
        } elseif (! empty($validated['division_id'])) {
            $division   = Division::with('section.department')->findOrFail($validated['division_id']);
            $section    = $division->section;
            $department = $section->department;

            $vaultDir = implode('/', array_filter([
                'document_vault',
                $department->level,
                $department->slug,
                $section->wing,
                $section->slug,
                'divisions',
                $division->slug,
            ]));

            $slug = Document::uniqueSlugForDivision($validated['title'], $division->id);
        } else {
            $section    = Section::with('department')->findOrFail($validated['section_id']);
            $department = $section->department;

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

            $metadata = $this->extractMetadata($validated);

            DB::transaction(function () use ($validated, $section, $ruleSet, $division, $department, $vaultDir, $pdfPath, $slug, $request, $metadata, &$document) {
                $document = Document::create([
                    'department_id'     => $department->id,
                    'section_id'        => $section?->id,
                    'division_id'       => $division?->id,
                    'rule_set_id'       => $ruleSet?->id,
                    'parent_id'         => $validated['parent_id'] ?? null,
                    'user_id'           => $request->user()->id,
                    'title'             => $validated['title'],
                    'slug'              => $slug,
                    'document_type'     => $validated['document_type'],
                    'original_filename' => $request->file('file')->getClientOriginalName(),
                    'original_pdf_path' => $pdfPath,
                    'vault_path'        => $vaultDir,
                    'status'            => 'uploaded',
                    'visibility'        => $validated['visibility'] ?? 'public',
                    'metadata'          => ! empty($metadata) ? $metadata : null,
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

            $redirectUrl = match(true) {
                $ruleSet  !== null => route('departments.rules.show', [$department->levelAlias(), $department, $ruleSet]),
                $division !== null => route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]),
                default            => route('departments.sections.show', [$department->levelAlias(), $department, $section]),
            };

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
        $data      = $this->mergeMetadata($validated, $document);

        try {
            DB::transaction(function () use ($data, $validated, $document, $oldStatus) {
                $document->update($data);
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
                'can_restore'     => auth()->user()->hasPrivilege('documents.restore'),
                'can_force_delete' => auth()->user()->hasPrivilege('documents.force-delete'),
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
        if (! auth()->user()->hasPrivilege('documents.restore')) {
            abort(403, 'You do not have permission to restore archived documents.');
        }

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
                    'note'        => 'Restored from archive.',
                ]);
            });

            flash()->success('Document restored from archive successfully.');
            return redirect()->route('documents.trash');
        } catch (\Throwable $e) {
            Log::error('DocumentController@restore failed', ['document_id' => $id, 'actor_id' => auth()->id(), 'error' => $e->getMessage()]);
            flash()->error('Failed to restore document. Please try again.');
            return back();
        }
    }

    public function bulkDestroy(BulkDeleteDocumentsRequest $request): RedirectResponse
    {
        $ids    = $request->validated()['ids'];
        $reason = $request->validated()['reason'];
        $actor  = auth()->id();

        $deleted = 0;

        try {
            DB::transaction(function () use ($ids, $reason, $actor, &$deleted) {
                foreach ($ids as $id) {
                    $document = Document::findOrFail($id);

                    DocumentStatusHistory::create([
                        'document_id' => $document->id,
                        'actor_id'    => $actor,
                        'from_status' => $document->status,
                        'to_status'   => 'deleted',
                        'note'        => $reason,
                    ]);

                    $document->delete();
                    $deleted++;
                }
            });

            flash()->success("{$deleted} " . Str::plural('document', $deleted) . ' moved to trash.');
        } catch (\Throwable $e) {
            Log::error('DocumentController@bulkDestroy failed', ['ids' => $ids, 'error' => $e->getMessage()]);
            flash()->error('Bulk delete failed. Please try again.');
        }

        return redirect()->route('documents.index');
    }

    public function bulkRestore(BulkRestoreDocumentsRequest $request): RedirectResponse
    {
        if (! auth()->user()->hasPrivilege('documents.restore')) {
            abort(403, 'You do not have permission to restore archived documents.');
        }

        $ids     = $request->validated()['ids'];
        $actor   = auth()->id();
        $restored = 0;

        try {
            DB::transaction(function () use ($ids, $actor, &$restored) {
                foreach ($ids as $id) {
                    $document = Document::withTrashed()->find($id);
                    if (! $document || ! $document->trashed()) {
                        continue;
                    }
                    $document->restore();
                    DocumentStatusHistory::create([
                        'document_id' => $document->id,
                        'actor_id'    => $actor,
                        'from_status' => 'deleted',
                        'to_status'   => $document->status,
                        'note'        => 'Restored from archive (bulk action).',
                    ]);
                    $restored++;
                }
            });

            flash()->success("{$restored} " . Str::plural('document', $restored) . ' restored successfully.');
        } catch (\Throwable $e) {
            Log::error('DocumentController@bulkRestore failed', ['ids' => $ids, 'error' => $e->getMessage()]);
            flash()->error('Bulk restore failed. Please try again.');
        }

        return redirect()->route('documents.trash');
    }

    public function bulkForceDestroy(BulkForceDestroyDocumentsRequest $request): RedirectResponse
    {
        if (! auth()->user()->hasPrivilege('documents.force-delete')) {
            abort(403, 'You do not have permission to permanently delete archived documents.');
        }

        $ids     = $request->validated()['ids'];
        $deleted = 0;

        try {
            DB::transaction(function () use ($ids, &$deleted) {
                foreach ($ids as $id) {
                    $document = Document::withTrashed()->find($id);
                    if (! $document) {
                        continue;
                    }
                    if ($document->original_pdf_path) {
                        Storage::disk('public')->delete($document->original_pdf_path);
                    }
                    if ($document->markdown_path) {
                        Storage::disk('public')->delete($document->markdown_path);
                    }
                    $document->forceDelete();
                    $deleted++;
                }
            });

            flash()->success("{$deleted} " . Str::plural('document', $deleted) . ' permanently deleted.');
        } catch (\Throwable $e) {
            Log::error('DocumentController@bulkForceDestroy failed', ['ids' => $ids, 'error' => $e->getMessage()]);
            flash()->error('Bulk permanent delete failed. Please try again.');
        }

        return redirect()->route('documents.trash');
    }

    public function forceDestroy(int $id): RedirectResponse
    {
        if (! auth()->user()->hasPrivilege('documents.force-delete')) {
            abort(403, 'You do not have permission to permanently delete archived documents.');
        }

        $document = Document::withTrashed()->findOrFail($id);

        // Validate reason and letter upload
        $reason = strip_tags(trim(request()->input('reason', '')));
        if (strlen($reason) < 5 || strlen($reason) > 500) {
            flash()->error('A deletion reason (5–500 characters) is required.');
            return back();
        }

        $letterFile = request()->file('letter');
        if (! $letterFile || ! $letterFile->isValid()) {
            flash()->error('A formal letter PDF must be uploaded to authorise permanent deletion.');
            return back();
        }

        if ($letterFile->getMimeType() !== 'application/pdf') {
            flash()->error('The authorisation letter must be a PDF file.');
            return back();
        }

        // Store letter before the transaction — file I/O is not transactional
        $letterPath = null;
        try {
            $letterFilename = "archive_letters/{$document->id}_" . now()->format('YmdHis') . '.pdf';
            Storage::disk('public')->putFileAs('', $letterFile, $letterFilename);
            $letterPath = $letterFilename;
        } catch (\Throwable $e) {
            Log::error('DocumentController@forceDestroy letter upload failed', ['document_id' => $id, 'actor_id' => auth()->id(), 'error' => $e->getMessage()]);
            flash()->error('Failed to store the authorisation letter. Deletion aborted.');
            return back();
        }

        try {
            DB::transaction(function () use ($document, $reason, $letterPath) {
                // Record the force-delete in history BEFORE the cascade-delete wipes it
                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => 'deleted',
                    'to_status'   => 'force_deleted',
                    'note'        => $reason,
                    'metadata'    => ['letter_path' => $letterPath],
                ]);

                if ($document->original_pdf_path) {
                    Storage::disk('public')->delete($document->original_pdf_path);
                }
                if ($document->markdown_path) {
                    Storage::disk('public')->delete($document->markdown_path);
                }
                $document->forceDelete();
            });

            flash()->success('Document permanently deleted from archive. Letter stored for audit.');
            return redirect()->route('documents.trash');
        } catch (\Throwable $e) {
            // Clean up orphaned letter file on transaction failure
            if ($letterPath) {
                Storage::disk('public')->delete($letterPath);
            }
            Log::error('DocumentController@forceDestroy failed', ['document_id' => $id, 'actor_id' => auth()->id(), 'letter_path' => $letterPath, 'error' => $e->getMessage()]);
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

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at']);
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
        $data      = $this->mergeMetadata($validated, $document);

        try {
            DB::transaction(function () use ($data, $validated, $document, $oldStatus) {
                $document->update($data);
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

    // ── Division document methods ─────────────────────────────────────────────

    public function showDivisionDoc(string $level, Department $department, Section $section, Division $division, Document $document): View
    {
        if (! auth()->check() && $document->visibility !== 'public') {
            abort(403);
        }

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at']);
        return view('documents.show', compact('document', 'department', 'section', 'division'));
    }

    public function pdfDivisionDoc(string $level, Department $department, Section $section, Division $division, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
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

    public function editDivisionDoc(string $level, Department $department, Section $section, Division $division, Document $document): View
    {
        return view('documents.edit', compact('document', 'department', 'section', 'division'));
    }

    public function updateDivisionDoc(UpdateDocumentRequest $request, string $level, Department $department, Section $section, Division $division, Document $document): RedirectResponse
    {
        $validated = $request->validated();
        $oldStatus = $document->status;
        $data      = $this->mergeMetadata($validated, $document);

        try {
            DB::transaction(function () use ($data, $validated, $document, $oldStatus) {
                $document->update($data);
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
            return redirect()->route('documents.divisions.show', [$department->levelAlias(), $department, $section, $division, $document]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@updateDivisionDoc failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            flash()->error('Failed to update document. Please try again.');
            return back()->withInput();
        }
    }

    public function destroyDivisionDoc(DeleteDocumentRequest $request, string $level, Department $department, Section $section, Division $division, Document $document): RedirectResponse
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
            return redirect()->route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@destroyDivisionDoc failed', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to delete document. Please try again.');
            return back();
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    /** Build a metadata array from store validated data (null values excluded). */
    private function extractMetadata(array $validated): array
    {
        return array_filter([
            'amendment_number' => $validated['amendment_number'] ?? null,
            'effective_year'   => $validated['effective_year']   ?? null,
            'effective_month'  => $validated['effective_month']  ?? null,
            'effective_day'    => $validated['effective_day']    ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Merge metadata fields from an update request into the existing document
     * metadata, then return a data array safe to pass to $document->update().
     * The 4 raw metadata keys are stripped; 'metadata' is added in their place.
     */
    private function mergeMetadata(array $validated, Document $document): array
    {
        $metaKeys = ['amendment_number', 'effective_year', 'effective_month', 'effective_day'];
        $existing = $document->metadata ?? [];

        foreach ($metaKeys as $key) {
            if (array_key_exists($key, $validated)) {
                if ($validated[$key] !== null) {
                    $existing[$key] = $validated[$key];
                } else {
                    unset($existing[$key]);
                }
            }
        }

        $data = array_diff_key($validated, array_flip($metaKeys));
        $data['metadata'] = ! empty($existing) ? $existing : null;

        return $data;
    }
}

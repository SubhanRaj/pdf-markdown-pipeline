<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesDocumentFiles;
use App\Http\Requests\BulkDeleteDocumentsRequest;
use App\Http\Requests\BulkRestoreDocumentsRequest;
use App\Http\Requests\BulkForceDestroyDocumentsRequest;
use App\Http\Requests\DeleteDocumentRequest;
use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Jobs\ConvertDocumentToMarkdown;
use App\Models\Department;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\Folder;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DocumentController extends Controller
{
    use ManagesDocumentFiles;

    public function bulkUploadForm(): View
    {
        $user = auth()->user();
        $tree = $this->buildUploadScopeTree($user);

        return view('documents.bulk-upload', [
            'tree'     => $tree,
            'scope'    => $user->uploadScope(),
            'storeUrl' => route('documents.store'),
        ]);
    }

    /** Conversion-pipeline monitor — every document not yet verified/archived, with live status. */
    public function pipeline(\Illuminate\Http\Request $request): View
    {
        $pipelineStatuses = ['uploaded', 'processing', 'ocr_pending', 'review', 'failed'];

        $activeStatus = $request->query('status');
        if (! in_array($activeStatus, $pipelineStatuses, true)) {
            $activeStatus = null;
        }

        $counts = Document::whereIn('status', $pipelineStatuses)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $documents = Document::with(['department', 'section', 'division', 'ruleSet', 'folder', 'user:id,name'])
            ->whereIn('status', $activeStatus ? [$activeStatus] : $pipelineStatuses)
            ->orderByDesc('updated_at')
            ->paginate(30)
            ->withQueryString();

        return view('documents.pipeline', compact('documents', 'counts', 'activeStatus', 'pipelineStatuses'));
    }

    /**
     * Departments/sections/divisions/folders/rule-sets the current user may upload
     * to, scoped by User::uploadScope() so the picker never offers a context that
     * would 403 on submit. Mirrors the parentOptions queries already used by each
     * show() controller, just gathered up-front for every eligible context at once.
     */
    private function buildUploadScopeTree(\App\Models\User $user): array
    {
        $scope = $user->uploadScope();

        if ($scope === 'none') {
            return [];
        }

        $departments = Department::query()
            ->when($scope === 'department', fn ($q) => $q->where('id', $user->department_id))
            ->when($scope === 'section', fn ($q) => $q->whereHas('sections', fn ($q2) => $q2->where('id', $user->section_id)))
            ->when($scope === 'division', fn ($q) => $q->whereHas('sections.divisions', fn ($q2) => $q2->where('id', $user->division_id)))
            ->orderBy('name')
            ->get();

        $mapParentOptions = fn ($query) => $query
            ->select('id', 'title', 'created_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'date' => $d->created_at->format('d M Y')])
            ->values();

        return $departments->map(function (Department $department) use ($scope, $user, $mapParentOptions) {
            $sections = $department->sections()
                ->when($scope === 'section', fn ($q) => $q->where('id', $user->section_id))
                ->when($scope === 'division', fn ($q) => $q->whereHas('divisions', fn ($q2) => $q2->where('id', $user->division_id)))
                ->orderBy('name')
                ->get()
                ->map(function (Section $section) use ($scope, $user, $mapParentOptions) {
                    $divisions = $section->divisions()
                        ->when($scope === 'division', fn ($q) => $q->where('id', $user->division_id))
                        ->get()
                        ->map(fn (Division $division) => [
                            'id'      => $division->id,
                            'name'    => $division->name,
                            'folders' => $division->folders()->get()->map(fn ($f) => [
                                'id'            => $f->id,
                                'name'          => $f->name,
                                'parentOptions' => $mapParentOptions($f->documents()->whereNull('parent_id')),
                            ])->values(),
                        ])->values();

                    return [
                        'id'      => $section->id,
                        'name'    => $section->name,
                        'wing'    => $section->wing,
                        'folders' => $scope === 'division' ? [] : $section->folders()->get()->map(fn ($f) => [
                            'id'            => $f->id,
                            'name'          => $f->name,
                            'parentOptions' => $mapParentOptions($f->documents()->whereNull('parent_id')),
                        ])->values(),
                        // Reused for the section itself AND every division under it —
                        // amendments are allowed to cross division boundaries within a section.
                        'parentOptions' => $mapParentOptions($section->documents()->whereNull('division_id')),
                        'divisions'     => $divisions,
                    ];
                })->values();

            $ruleSets = in_array($scope, ['global', 'department'], true)
                ? $department->ruleSets()->rules()->orderBy('name')->get()->map(function (RuleSet $ruleSet) use ($mapParentOptions) {
                    $rootDocs = $ruleSet->documents()->whereNull('parent_id')->get(['id', 'document_type']);

                    return [
                        'id'            => $ruleSet->id,
                        'kind'          => 'rules',
                        'name'          => $ruleSet->name,
                        'hasRuleDoc'    => $rootDocs->where('document_type', 'rule')->isNotEmpty(),
                        'parentOptions' => $mapParentOptions($ruleSet->documents()->whereNull('parent_id')),
                    ];
                })->values()
                : collect();

            // Policy management is stricter than the generic upload scope (admin or the
            // department's own department.head only — see User::canManagePolicy()). Merged into
            // the same "Rule Set" picker rather than a separate tab — both submit via
            // rule_set_id, so a parallel UI mode would just be a duplicate of this one with a
            // different source array. Superseded policies are included — amendments are allowed
            // on any policy regardless of status.
            $policies = $user->canManagePolicyForDepartment($department)
                ? $department->ruleSets()->policy()->orderBy('name')->get()->map(function (RuleSet $ruleSet) use ($mapParentOptions) {
                    $rootDocs = $ruleSet->documents()->whereNull('parent_id')->get(['id', 'document_type']);

                    return [
                        'id'            => $ruleSet->id,
                        'kind'          => 'policy',
                        'name'          => '[Policy] ' . $ruleSet->name . ($ruleSet->policy_status === 'superseded' ? ' (Superseded)' : ''),
                        'hasRuleDoc'    => $rootDocs->where('document_type', 'policy')->isNotEmpty(),
                        'parentOptions' => $mapParentOptions($ruleSet->documents()->whereNull('parent_id')),
                    ];
                })->values()
                : collect();

            return [
                'id'          => $department->id,
                'name'        => $department->name,
                'level'       => $department->level,
                'levelAlias'  => $department->levelAlias(),
                'levelLabel'  => $department->levelLabel(),
                'sections'    => $sections,
                'ruleSets'    => $ruleSets->concat($policies)->values(),
            ];
        })->values()->all();
    }

    public function index(): View
    {
        $query = Document::with(['department', 'section', 'division', 'ruleSet', 'folder', 'user:id,name'])
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

        // ── Resolve context: rule-set, folder, division, or direct section upload ──
        $ruleSet  = null;
        $division = null;
        $folder   = null;

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
        } elseif (! empty($validated['folder_id'])) {
            $folder     = Folder::with('division.section.department', 'section.department')->findOrFail($validated['folder_id']);
            $section    = $folder->section;
            $division   = $folder->division;
            $department = $section->department;

            $vaultDir = implode('/', array_filter([
                'document_vault',
                $department->level,
                $department->slug,
                $section->wing,
                $section->slug,
                $division ? 'divisions' : null,
                $division?->slug,
                'folders',
                $folder->slug,
            ]));

            $slug = Document::uniqueSlugForFolder($validated['title'], $folder->id);
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

        // Determine if this upload requires approval (bulk operator flag or context flag)
        $uploadContext   = $folder ?? $division ?? $section ?? $ruleSet;
        $requireApproval = $uploadContext && $request->user()->shouldRequireApproval($uploadContext);
        $initialStatus   = $requireApproval ? 'pending_approval' : 'uploaded';

        try {
            $document = null;

            $metadata = $this->extractMetadata($validated);

            DB::transaction(function () use ($validated, $section, $ruleSet, $division, $folder, $department, $vaultDir, $pdfPath, $slug, $request, $metadata, $initialStatus, &$document) {
                $document = Document::create([
                    'department_id'     => $department->id,
                    'section_id'        => $section?->id,
                    'division_id'       => $division?->id,
                    'rule_set_id'       => $ruleSet?->id,
                    'folder_id'         => $folder?->id,
                    'parent_id'         => $validated['parent_id'] ?? null,
                    'user_id'           => $request->user()->id,
                    'title'             => $validated['title'],
                    'slug'              => $slug,
                    'document_type'     => $validated['document_type'],
                    'original_filename' => preg_replace('/[^\w\s\-\.\(\)]/', '_', $request->file('file')->getClientOriginalName()),
                    'original_pdf_path' => $pdfPath,
                    'vault_path'        => $vaultDir,
                    'status'            => $initialStatus,
                    'visibility'        => $validated['visibility'] ?? 'public',
                    'metadata'          => ! empty($metadata) ? $metadata : null,
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => $request->user()->id,
                    'from_status' => null,
                    'to_status'   => $initialStatus,
                    'note'        => $initialStatus === 'pending_approval'
                        ? 'Document submitted for approval.'
                        : 'Document uploaded.',
                ]);
            });

            if ($initialStatus === 'pending_approval') {
                flash()->info("\"{$validated['title']}\" submitted and is pending approval before becoming visible.");
            } else {
                flash()->success("\"{$validated['title']}\" uploaded successfully.");
            }

            $redirectUrl = match(true) {
                $ruleSet !== null => route("departments.{$ruleSet->kind}.show", [$department->levelAlias(), $department, $ruleSet]),
                $folder  !== null => $division !== null
                    ? route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder])
                    : route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $folder]),
                $division !== null => route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]),
                default            => route('departments.sections.show', [$department->levelAlias(), $department, $section]),
            };

            return response()->json(['redirect' => $redirectUrl, 'document_id' => $document->id]);

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

            $this->archiveFiles($document);

            flash()->success('Document moved to archive.');
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
        $document  = Document::onlyTrashed()->findOrFail($id);
        $localPath = 'archived_documents/' . $document->id . '.pdf';

        if (! Storage::disk('local')->exists($localPath)) {
            abort(404, 'PDF file not found.');
        }

        return Storage::disk('local')->response(
            $localPath,
            null,
            ['Content-Disposition' => 'inline; filename="' . basename($document->original_pdf_path ?? 'document.pdf') . '"']
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

            $this->restoreFiles($document);

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

        $archived = [];
        $deleted  = 0;

        try {
            DB::transaction(function () use ($ids, $reason, $actor, &$archived, &$deleted) {
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
                    $archived[] = $document;
                    $deleted++;
                }
            });

            foreach ($archived as $document) {
                $this->archiveFiles($document);
            }

            flash()->success("{$deleted} " . Str::plural('document', $deleted) . ' moved to archive.');
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

        $authUser = auth()->user();

        $restoredDocs = [];

        try {
            DB::transaction(function () use ($ids, $actor, $authUser, &$restored, &$restoredDocs) {
                foreach ($ids as $id) {
                    $document = Document::withTrashed()->find($id);
                    if (! $document || ! $document->trashed()) {
                        continue;
                    }

                    // Enforce scope: same boundary as single-restore and canDeleteFrom.
                    // Admins bypass this check unconditionally.
                    if (! $authUser->isAdmin()) {
                        $context = $document->division ?? $document->section ?? $document->ruleSet;
                        if ($context && ! $authUser->canDeleteFrom($context)) {
                            continue;
                        }
                    }

                    $document->restore();
                    DocumentStatusHistory::create([
                        'document_id' => $document->id,
                        'actor_id'    => $actor,
                        'from_status' => 'deleted',
                        'to_status'   => $document->status,
                        'note'        => 'Restored from archive (bulk action).',
                    ]);
                    $restoredDocs[] = $document;
                    $restored++;
                }
            });

            foreach ($restoredDocs as $document) {
                $this->restoreFiles($document);
            }

            flash()->success("{$restored} " . Str::plural('document', $restored) . ' restored successfully.');
        } catch (\Throwable $e) {
            Log::error('DocumentController@bulkRestore failed', ['ids' => $ids, 'error' => $e->getMessage()]);
            flash()->error('Bulk restore failed. Please try again.');
        }

        return redirect()->route('documents.trash');
    }

    public function bulkForceDestroy(BulkForceDestroyDocumentsRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $ids       = $validated['ids'];
        $reason    = $validated['reason'];
        $actor     = auth()->id();
        $deleted   = 0;

        try {
            DB::transaction(function () use ($ids, $reason, $actor, &$deleted) {
                foreach ($ids as $id) {
                    $document = Document::withTrashed()->find($id);
                    if (! $document) {
                        continue;
                    }

                    // Audit row written BEFORE forceDelete so it exists in the
                    // transaction even though cascade-delete will remove it on commit.
                    // The status_history row is the surviving paper trail.
                    DocumentStatusHistory::create([
                        'document_id' => $document->id,
                        'actor_id'    => $actor,
                        'from_status' => 'deleted',
                        'to_status'   => 'force_deleted',
                        'note'        => $reason,
                    ]);

                    $this->deleteArchivedFiles($document);
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

        // Store letter on the PRIVATE local disk (not public) — archive letters must
        // never be web-accessible via the storage symlink. File I/O happens before
        // the DB transaction because filesystem operations are not transactional.
        $letterPath = null;
        try {
            $letterBasename = $document->id . '_' . now()->format('YmdHis') . '.pdf';
            Storage::disk('local')->putFileAs('archive_letters', $letterFile, $letterBasename);
            $letterPath = 'archive_letters/' . $letterBasename;
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

                $this->deleteArchivedFiles($document);
                $document->forceDelete();
            });

            flash()->success('Document permanently deleted from archive. Letter stored for audit.');
            return redirect()->route('documents.trash');
        } catch (\Throwable $e) {
            // Clean up orphaned letter file on transaction failure
            if ($letterPath) {
                Storage::disk('local')->delete($letterPath);
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
            return redirect()->route("documents.{$ruleSet->kind}.show", [$department->levelAlias(), $department, $ruleSet, $document]);
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

            $this->archiveFiles($document);

            flash()->success('Document moved to archive.');
            return redirect()->route("departments.{$ruleSet->kind}.show", [$department->levelAlias(), $department, $ruleSet]);
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

            $this->archiveFiles($document);

            flash()->success('Document moved to archive.');
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

    // ── Section-folder document variants ──────────────────────────────────────

    public function showSectionFolderDoc(string $level, Department $department, Section $section, Folder $folder, Document $document): View
    {
        if (! auth()->check() && $document->visibility !== 'public') {
            abort(403);
        }

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at']);
        return view('documents.show', compact('document', 'department', 'section', 'folder'));
    }

    public function pdfSectionFolderDoc(string $level, Department $department, Section $section, Folder $folder, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
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

    public function editSectionFolderDoc(string $level, Department $department, Section $section, Folder $folder, Document $document): View
    {
        return view('documents.edit', compact('document', 'department', 'section', 'folder'));
    }

    public function updateSectionFolderDoc(UpdateDocumentRequest $request, string $level, Department $department, Section $section, Folder $folder, Document $document): RedirectResponse
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
            return redirect()->route('documents.folders.show', [$department->levelAlias(), $department, $section, $folder, $document]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@updateSectionFolderDoc failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            flash()->error('Failed to update document. Please try again.');
            return back()->withInput();
        }
    }

    public function destroySectionFolderDoc(DeleteDocumentRequest $request, string $level, Department $department, Section $section, Folder $folder, Document $document): RedirectResponse
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

            $this->archiveFiles($document);

            flash()->success('Document moved to archive.');
            return redirect()->route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $folder]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@destroySectionFolderDoc failed', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to delete document. Please try again.');
            return back();
        }
    }

    // ── Division-folder document variants ─────────────────────────────────────

    public function showDivisionFolderDoc(string $level, Department $department, Section $section, Division $division, Folder $folder, Document $document): View
    {
        if (! auth()->check() && $document->visibility !== 'public') {
            abort(403);
        }

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at']);
        return view('documents.show', compact('document', 'department', 'section', 'division', 'folder'));
    }

    public function pdfDivisionFolderDoc(string $level, Department $department, Section $section, Division $division, Folder $folder, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
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

    public function editDivisionFolderDoc(string $level, Department $department, Section $section, Division $division, Folder $folder, Document $document): View
    {
        return view('documents.edit', compact('document', 'department', 'section', 'division', 'folder'));
    }

    public function updateDivisionFolderDoc(UpdateDocumentRequest $request, string $level, Department $department, Section $section, Division $division, Folder $folder, Document $document): RedirectResponse
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
            return redirect()->route('documents.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder, $document]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@updateDivisionFolderDoc failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
            flash()->error('Failed to update document. Please try again.');
            return back()->withInput();
        }
    }

    public function destroyDivisionFolderDoc(DeleteDocumentRequest $request, string $level, Department $department, Section $section, Division $division, Folder $folder, Document $document): RedirectResponse
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

            $this->archiveFiles($document);

            flash()->success('Document moved to archive.');
            return redirect()->route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@destroyDivisionFolderDoc failed', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to delete document. Please try again.');
            return back();
        }
    }

    // ── Markdown conversion (button-triggered, applies to all five doc contexts) ──

    /**
     * Admin, or (for a policy-kind rule-set document only) the owning department's
     * department.head — same lifecycle-management gate used by convert/convertOcr/revertOcr/
     * discardMarkdown/updateMarkdown. Everyone else is view-only.
     */
    private function canManageDocument(Document $document): bool
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return true;
        }

        $ruleSet = $document->ruleSet;

        return $ruleSet !== null && $ruleSet->kind === 'policy' && $user->canManagePolicy($ruleSet);
    }

    public function convert(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canManageDocument($document)) {
            abort(403);
        }

        if (! in_array($document->status, ['uploaded', 'review', 'verified', 'failed'], true)) {
            return response()->json(['message' => 'Document is not in a convertible state.'], 422);
        }

        if (! $document->original_pdf_path || ! Storage::disk('public')->exists($document->original_pdf_path)) {
            return response()->json(['message' => 'Original PDF file not found.'], 404);
        }

        ConvertDocumentToMarkdown::dispatch($document->id);

        return response()->json(['status' => 'processing']);
    }

    /** Explicit, human-triggered OCR re-extraction — never auto-run. See RunOcrExtraction. */
    public function convertOcr(int $id, Request $request): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canManageDocument($document)) {
            abort(403);
        }

        if (! in_array($document->status, ['review', 'verified', 'failed'], true)) {
            return response()->json(['message' => 'Document is not in a state that supports OCR re-extraction.'], 422);
        }

        if (! $document->original_pdf_path || ! Storage::disk('public')->exists($document->original_pdf_path)) {
            return response()->json(['message' => 'Original PDF file not found.'], 404);
        }

        $engine = $request->input('engine', config('ocr.default'));

        if (! array_key_exists($engine, config('ocr.engines'))) {
            return response()->json(['message' => 'Unknown OCR engine selected.'], 422);
        }

        \App\Jobs\RunOcrExtraction::dispatch($document->id, $engine);

        return response()->json(['status' => 'ocr_pending']);
    }

    /** Discard an OCR result and restore the pre-OCR text-layer Markdown saved by RunOcrExtraction. */
    public function revertOcr(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canManageDocument($document)) {
            abort(403);
        }

        if (($document->metadata['extraction_method'] ?? null) !== 'ocr') {
            return response()->json(['message' => 'This document is not currently showing an OCR result.'], 422);
        }

        $preOcrBackupPath = preg_replace('/\.pdf$/i', '.pre-ocr.md', $document->original_pdf_path);

        if (! $document->original_pdf_path || ! Storage::disk('public')->exists($preOcrBackupPath)) {
            return response()->json(['message' => 'No pre-OCR Markdown was saved for this document.'], 404);
        }

        DB::transaction(function () use ($document, $preOcrBackupPath) {
            $oldStatus = $document->status;
            $markdownPath = preg_replace('/\.pdf$/i', '.md', $document->original_pdf_path);
            Storage::disk('public')->put($markdownPath, Storage::disk('public')->get($preOcrBackupPath));

            $metadata = $document->metadata ?? [];
            $metadata['extraction_method'] = 'pdf-text';
            unset($metadata['ocr_engine']);

            $document->update([
                'markdown_path' => $markdownPath,
                'status'        => 'review',
                'metadata'      => $metadata,
            ]);

            DocumentStatusHistory::create([
                'document_id' => $document->id,
                'actor_id'    => auth()->id(),
                'from_status' => $oldStatus,
                'to_status'   => 'review',
                'note'        => 'Reverted OCR result back to the original text-layer extraction.',
            ]);
        });

        return response()->json(['status' => 'reverted']);
    }

    public function conversionStatus(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        return response()->json([
            'status'            => $document->status,
            'extraction_method' => $document->metadata['extraction_method'] ?? null,
            'ocr_engine'        => $document->metadata['ocr_engine'] ?? null,
            'needs_ocr_review'  => (bool) ($document->metadata['needs_ocr_review'] ?? false),
            'has_markdown'      => (bool) ($document->markdown_path && Storage::disk('public')->exists($document->markdown_path)),
        ]);
    }

    /** Save edits made in the compare-and-verify modal, optionally marking the document verified. */
    public function updateMarkdown(int $id, \App\Http\Requests\UpdateDocumentMarkdownRequest $request): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $document->markdown_path) {
            return response()->json(['message' => 'This document has no Markdown to edit yet.'], 422);
        }

        $validated = $request->validated();
        $oldStatus = $document->status;
        $willVerify = $validated['verify'] && $oldStatus !== 'verified';

        try {
            DB::transaction(function () use ($document, $validated, $oldStatus, $willVerify) {
                Storage::disk('public')->put($document->markdown_path, $validated['content']);

                $document->update([
                    'metadata' => array_merge($document->metadata ?? [], ['manually_edited' => true]),
                    'status'   => $willVerify ? 'verified' : $oldStatus,
                ]);

                if ($willVerify) {
                    DocumentStatusHistory::create([
                        'document_id' => $document->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => $oldStatus,
                        'to_status'   => 'verified',
                        'note'        => 'Verified via review comparison.',
                    ]);
                }
            });

            return response()->json(['status' => $document->fresh()->status]);
        } catch (\Throwable $e) {
            Log::error('DocumentController@updateMarkdown failed', ['document_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to save changes. Please try again.'], 500);
        }
    }

    /**
     * Discard an unverified extracted Markdown draft from the Compare & Verify modal and
     * reset the document to its pre-conversion state — re-enables "Convert to Markdown" so
     * the officer can try again (e.g. after choosing OCR review) instead of being stuck with
     * a bad draft. Verified documents are excluded — discarding a verified result isn't a
     * "draft rejection" anymore, it would be destroying an accepted record.
     */
    public function discardMarkdown(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canManageDocument($document)) {
            abort(403);
        }

        if (! $document->markdown_path) {
            return response()->json(['message' => 'This document has no Markdown draft to discard.'], 422);
        }

        if ($document->status === 'verified') {
            return response()->json(['message' => 'Verified documents cannot be discarded.'], 422);
        }

        try {
            DB::transaction(function () use ($document) {
                $oldStatus = $document->status;

                if (Storage::disk('public')->exists($document->markdown_path)) {
                    Storage::disk('public')->delete($document->markdown_path);
                }

                $metadata = $document->metadata ?? [];
                unset($metadata['extraction_method'], $metadata['needs_ocr_review'], $metadata['manually_edited']);

                $document->update([
                    'markdown_path' => null,
                    'status'        => 'uploaded',
                    'metadata'      => $metadata,
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => $oldStatus,
                    'to_status'   => 'uploaded',
                    'note'        => 'Discarded extracted Markdown draft; ready for re-conversion.',
                ]);
            });

            return response()->json(['status' => 'uploaded']);
        } catch (\Throwable $e) {
            Log::error('DocumentController@discardMarkdown failed', ['document_id' => $id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Failed to discard draft. Please try again.'], 500);
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

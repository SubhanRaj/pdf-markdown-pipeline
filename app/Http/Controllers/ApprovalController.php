<?php

namespace App\Http\Controllers;

use App\Http\Requests\ApproveDocumentRequest;
use App\Http\Requests\RejectDocumentRequest;
use App\Http\Requests\ReclassifyDocumentRequest;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function index(Request $request): View
    {
        $user     = auth()->user();
        $tab      = $request->get('tab', 'pending');
        $isApprover = $user->isAdmin() || $user->hasPrivilege('documents.approve');

        // Pending and rejected tabs: approvers see scoped docs; others see own submissions
        $pendingQuery  = Document::with(['department', 'section', 'division', 'ruleSet', 'user:id,name'])
            ->where('status', 'pending_approval');
        $rejectedQuery = Document::with(['department', 'section', 'division', 'ruleSet', 'user:id,name',
                'statusHistory' => fn ($q) => $q->where('to_status', 'rejected')->with('actor:id,name')->latest('created_at'),
            ])
            ->where('status', 'rejected');

        if ($isApprover) {
            $this->applyScopeFilter($pendingQuery, $user);
            $this->applyScopeFilter($rejectedQuery, $user);
        } else {
            $pendingQuery->where('user_id', $user->id);
            $rejectedQuery->where('user_id', $user->id);
        }

        $pendingDocs  = $pendingQuery->orderByDesc('created_at')->get();
        $rejectedDocs = $rejectedQuery->orderByDesc('updated_at')->get();

        // My submissions: own pending + rejected regardless of approve privilege
        $myDocs = Document::with(['department', 'section', 'division', 'ruleSet'])
            ->whereIn('status', ['pending_approval', 'rejected'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        // Prepare JSON data for slide-over drawer and reclassify modal
        $allDepts = \App\Models\Department::orderBy('name')->get(['id', 'name', 'slug', 'level']);
        $allSections = Section::with('department')->orderBy('name')->get(['id', 'department_id', 'wing', 'name', 'slug']);
        $allDivisions = Division::orderBy('name')->get(['id', 'section_id', 'name', 'slug']);
        $allRuleSets  = RuleSet::orderBy('name')->get(['id', 'department_id', 'name', 'slug']);

        // Map documents to array for JS data island
        $pendingData  = $this->mapDocsForJs($pendingDocs, $user, $isApprover);
        $rejectedData = $this->mapDocsForJs($rejectedDocs, $user, $isApprover);
        $myData       = $this->mapDocsForJs($myDocs, $user, false, true);

        return view('approvals.index', compact(
            'tab',
            'isApprover',
            'pendingDocs',
            'rejectedDocs',
            'myDocs',
            'pendingData',
            'rejectedData',
            'myData',
            'allDepts',
            'allSections',
            'allDivisions',
            'allRuleSets',
        ));
    }

    public function approve(int $id, ApproveDocumentRequest $request): RedirectResponse
    {
        $document = Document::findOrFail($id);

        if ($document->status !== 'pending_approval') {
            flash()->warning('This document is not pending approval.');
            return redirect()->route('approvals.index');
        }

        $context = $this->resolveContext($document);

        if (! auth()->user()->canApprove($context)) {
            abort(403, 'You are not authorised to approve documents in this context.');
        }

        try {
            DB::transaction(function () use ($document, $request) {
                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => 'pending_approval',
                    'to_status'   => 'uploaded',
                    'note'        => $request->validated('note') ?: 'Approved.',
                ]);

                $document->update(['status' => 'uploaded']);
            });

            flash()->success("\"{$document->title}\" approved and is now active.");
        } catch (\Throwable $e) {
            Log::error('ApprovalController@approve failed', ['document_id' => $id, 'error' => $e->getMessage()]);
            flash()->error('Failed to approve document. Please try again.');
        }

        return redirect()->route('approvals.index', ['tab' => 'pending']);
    }

    public function reject(int $id, RejectDocumentRequest $request): RedirectResponse
    {
        $document = Document::findOrFail($id);

        if ($document->status !== 'pending_approval') {
            flash()->warning('This document is not pending approval.');
            return redirect()->route('approvals.index');
        }

        $context = $this->resolveContext($document);

        if (! auth()->user()->canApprove($context)) {
            abort(403, 'You are not authorised to reject documents in this context.');
        }

        try {
            DB::transaction(function () use ($document, $request) {
                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => 'pending_approval',
                    'to_status'   => 'rejected',
                    'note'        => $request->validated('reason'),
                ]);

                $document->update(['status' => 'rejected']);
            });

            flash()->success("\"{$document->title}\" has been rejected.");
        } catch (\Throwable $e) {
            Log::error('ApprovalController@reject failed', ['document_id' => $id, 'error' => $e->getMessage()]);
            flash()->error('Failed to reject document. Please try again.');
        }

        return redirect()->route('approvals.index', ['tab' => 'pending']);
    }

    public function reclassify(int $id, ReclassifyDocumentRequest $request): RedirectResponse
    {
        $document  = Document::with(['section.department', 'division', 'ruleSet.department'])->findOrFail($id);
        $validated = $request->validated();
        $user      = auth()->user();

        if (! in_array($document->status, ['pending_approval', 'rejected'], true)) {
            flash()->warning('Only pending or rejected documents can be reclassified.');
            return redirect()->route('approvals.index');
        }

        $oldContext = $this->resolveContext($document);

        if (! $user->canApprove($oldContext)) {
            abort(403, 'You are not authorised to act on documents in the current context.');
        }

        // Resolve new context
        $newRuleSet  = null;
        $newDivision = null;
        $newSection  = null;

        if (! empty($validated['new_rule_set_id'])) {
            $newRuleSet  = RuleSet::with('department')->findOrFail($validated['new_rule_set_id']);
            $newDept     = $newRuleSet->department;
            $newContext  = $newRuleSet;
        } elseif (! empty($validated['new_division_id'])) {
            $newDivision = Division::with('section.department')->findOrFail($validated['new_division_id']);
            $newSection  = $newDivision->section;
            $newDept     = $newSection->department;
            $newContext  = $newDivision;
        } else {
            $newSection = Section::with('department')->findOrFail($validated['new_section_id']);
            $newDept    = $newSection->department;
            $newContext = $newSection;
        }

        if (! $user->canApprove($newContext)) {
            abort(403, 'You are not authorised to move documents into the target context.');
        }

        // Compute new vault path
        if ($newRuleSet) {
            $newVaultDir = implode('/', [
                'document_vault',
                $newDept->level,
                $newDept->slug,
                'rules',
                $newRuleSet->slug,
            ]);
            $newSlug = Document::uniqueSlugForRuleSet($document->title, $newRuleSet->id);
        } elseif ($newDivision) {
            $newVaultDir = implode('/', array_filter([
                'document_vault',
                $newDept->level,
                $newDept->slug,
                $newSection->wing,
                $newSection->slug,
                'divisions',
                $newDivision->slug,
            ]));
            $newSlug = Document::uniqueSlugForDivision($document->title, $newDivision->id);
        } else {
            $newVaultDir = implode('/', array_filter([
                'document_vault',
                $newDept->level,
                $newDept->slug,
                $newSection->wing,
                $newSection->slug,
            ]));
            $newSlug = Document::uniqueSlugForSection($document->title, $newSection->id);
        }

        $timestamp      = now()->format('YmdHis');
        $newPdfFilename = "{$newSlug}_{$timestamp}.pdf";
        $newPdfPath     = $newVaultDir . '/' . $newPdfFilename;

        $oldPdfPath = $document->original_pdf_path;
        $oldMdPath  = $document->markdown_path;

        // Build the context label for the history note
        $oldLabel = $document->division?->name
            ?? $document->section?->name
            ?? $document->ruleSet?->name
            ?? '—';
        $newLabel = $newDivision?->name ?? $newSection?->name ?? $newRuleSet?->name ?? '—';

        $willApprove   = ! empty($validated['approve']);
        $finalStatus   = $willApprove ? 'uploaded' : $document->status;
        $reclassifyNote = trim(($validated['note'] ?? '') ?: "Reclassified from \"{$oldLabel}\" to \"{$newLabel}\".");

        try {
            DB::transaction(function () use (
                $document, $newDept, $newSection, $newDivision, $newRuleSet,
                $newVaultDir, $newPdfPath, $newSlug, $willApprove,
                $finalStatus, $reclassifyNote, $oldMdPath
            ) {
                // Compute new markdown path if one exists
                $newMdPath = null;
                if ($oldMdPath) {
                    $newMdFilename = pathinfo($newPdfPath, PATHINFO_FILENAME) . '.md';
                    $newMdPath     = pathinfo($newPdfPath, PATHINFO_DIRNAME) . '/' . $newMdFilename;
                }

                $document->update([
                    'department_id'     => $newDept->id,
                    'section_id'        => $newSection?->id,
                    'division_id'       => $newDivision?->id,
                    'rule_set_id'       => $newRuleSet?->id,
                    'slug'              => $newSlug,
                    'vault_path'        => $newVaultDir,
                    'original_pdf_path' => $newPdfPath,
                    'markdown_path'     => $newMdPath,
                    'status'            => $finalStatus,
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => $document->getOriginal('status'),
                    'to_status'   => 'pending_approval',
                    'note'        => $reclassifyNote,
                ]);

                if ($willApprove) {
                    DocumentStatusHistory::create([
                        'document_id' => $document->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => 'pending_approval',
                        'to_status'   => 'uploaded',
                        'note'        => 'Approved after reclassification.',
                    ]);
                }
            });

            // Move physical files after the transaction (best-effort, non-fatal)
            $this->movePublicFile($oldPdfPath, $newPdfPath, $document->id, 'pdf');
            if ($oldMdPath) {
                $newMdFilename = pathinfo($newPdfPath, PATHINFO_FILENAME) . '.md';
                $newMdPath     = pathinfo($newPdfPath, PATHINFO_DIRNAME) . '/' . $newMdFilename;
                $this->movePublicFile($oldMdPath, $newMdPath, $document->id, 'md');
            }

            $verb = $willApprove ? 'reclassified and approved' : 'reclassified';
            flash()->success("\"{$document->title}\" has been {$verb}.");
        } catch (\Throwable $e) {
            Log::error('ApprovalController@reclassify failed', ['document_id' => $id, 'error' => $e->getMessage()]);
            flash()->error('Failed to reclassify document. Please try again.');
        }

        return redirect()->route('approvals.index', ['tab' => 'pending']);
    }

    public function resubmit(int $id): RedirectResponse
    {
        $document = Document::findOrFail($id);
        $user     = auth()->user();

        if ($document->user_id !== $user->id && ! $user->isAdmin()) {
            abort(403, 'You may only resubmit your own documents.');
        }

        if ($document->status !== 'rejected') {
            flash()->warning('Only rejected documents can be resubmitted.');
            return redirect()->route('approvals.index', ['tab' => 'mine']);
        }

        try {
            DB::transaction(function () use ($document) {
                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => auth()->id(),
                    'from_status' => 'rejected',
                    'to_status'   => 'pending_approval',
                    'note'        => 'Resubmitted for approval.',
                ]);

                $document->update(['status' => 'pending_approval']);
            });

            flash()->success("\"{$document->title}\" has been resubmitted for approval.");
        } catch (\Throwable $e) {
            Log::error('ApprovalController@resubmit failed', ['document_id' => $id, 'error' => $e->getMessage()]);
            flash()->error('Failed to resubmit document. Please try again.');
        }

        return redirect()->route('approvals.index', ['tab' => 'mine']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /** Resolve the organisational context object (Section, Division, or RuleSet) for a document. */
    private function resolveContext(Document $document): Section|Division|RuleSet
    {
        if ($document->division_id) {
            return $document->division ?? Division::findOrFail($document->division_id);
        }

        if ($document->section_id) {
            return $document->section ?? Section::findOrFail($document->section_id);
        }

        return $document->ruleSet ?? RuleSet::findOrFail($document->rule_set_id);
    }

    /** Apply an Eloquent scope constraint to limit query results to the approver's org boundary. */
    private function applyScopeFilter(\Illuminate\Database\Eloquent\Builder $query, \App\Models\User $user): void
    {
        $scope = $user->uploadScope();

        match ($scope) {
            'department' => $query->where('department_id', $user->department_id),
            'section'    => $query->where('section_id', $user->section_id),
            'division'   => $query->where('division_id', $user->division_id),
            default      => null, // 'global' — no additional WHERE needed
        };
    }

    /** Map a document collection to arrays for a JS data island. */
    private function mapDocsForJs(
        \Illuminate\Support\Collection $docs,
        \App\Models\User $user,
        bool $canApproveTab,
        bool $isMineTab = false,
    ): array {
        return $docs->map(function (Document $doc) use ($user, $canApproveTab, $isMineTab) {
            $context = $doc->division ?? $doc->section ?? $doc->ruleSet;
            $canAct  = $canApproveTab && $context && $user->canApprove($context);

            $rejectionEntry = null;
            if ($doc->status === 'rejected' && $doc->statusHistory) {
                $rejectionEntry = $doc->statusHistory->first();
            }

            return [
                'id'              => $doc->id,
                'title'           => $doc->title,
                'document_type'   => Document::DOCUMENT_TYPES[$doc->document_type] ?? $doc->document_type,
                'status'          => $doc->status,
                'status_label'    => Document::STATUSES[$doc->status]['label'] ?? $doc->status,
                'visibility'      => $doc->visibility,
                'department'      => $doc->department->name ?? '—',
                'context_name'    => $doc->division?->name ?? $doc->section?->name ?? $doc->ruleSet?->name ?? '—',
                'context_type'    => $doc->division_id ? 'Division' : ($doc->section_id ? 'Section' : 'Rule Set'),
                'uploaded_by'     => $doc->user?->name ?? '—',
                'uploaded_at'     => $doc->created_at->format('d M Y, H:i'),
                'rejection_reason' => $rejectionEntry?->note ?? null,
                'rejected_by'     => $rejectionEntry?->actor?->name ?? null,
                'pdf_url'         => $doc->original_pdf_path
                    ? route('approvals.pdf', $doc->id)
                    : null,
                'approve_url'     => route('approvals.approve', $doc->id),
                'reject_url'      => route('approvals.reject', $doc->id),
                'reclassify_url'  => route('approvals.reclassify', $doc->id),
                'resubmit_url'    => route('approvals.resubmit', $doc->id),
                'can_act'         => $canAct,
                'can_resubmit'    => $isMineTab && $doc->status === 'rejected' && $doc->user_id === $user->id,
                'current_section_id'  => $doc->section_id,
                'current_division_id' => $doc->division_id,
                'current_rule_set_id' => $doc->rule_set_id,
                'current_dept_id'     => $doc->department_id,
            ];
        })->values()->all();
    }

    /** Move a file between two paths on the public disk (same-disk rename). Best-effort. */
    private function movePublicFile(?string $from, ?string $to, int $documentId, string $ext): void
    {
        if (! $from || ! $to) {
            return;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($from)) {
                return;
            }
            $dir = dirname($to);
            if (! $disk->exists($dir)) {
                $disk->makeDirectory($dir);
            }
            $disk->move($from, $to);
        } catch (\Throwable $e) {
            Log::warning('ApprovalController: file move failed', [
                'document_id' => $documentId,
                'ext'         => $ext,
                'from'        => $from,
                'to'          => $to,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    public function pdf(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $document = Document::whereIn('status', ['pending_approval', 'rejected'])->findOrFail($id);

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
}

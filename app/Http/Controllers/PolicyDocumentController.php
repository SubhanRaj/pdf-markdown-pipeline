<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ListsRuleSetDocuments;
use App\Http\Controllers\Concerns\ManagesDocumentFiles;
use App\Http\Requests\StorePolicyDocumentRequest;
use App\Http\Requests\UpdatePolicyDocumentRequest;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\RuleSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * A policy document is a specific yearly/cyclical policy (e.g. "Excise Policy 2024-25")
 * added underneath a policy container (state + policy_type, created once via
 * RuleSetController) — "period" refers only to its timeframe (e.g. "2024-25"), never to
 * the document itself. The /periods/ URL and route-name segment describe that timeframe
 * scoping, not the entity type. Policy documents are plain RuleSet rows with container_id set —
 * everything about how one holds its own root document + amendments is identical to
 * today's rule-sets behavior, reused via the ListsRuleSetDocuments trait.
 */
class PolicyDocumentController extends Controller
{
    use ListsRuleSetDocuments, ManagesDocumentFiles;

    /** Aborts 404 if $policyDoc doesn't actually belong to $policy — guards the nested URL. */
    private function assertBelongsTo(RuleSet $policy, RuleSet $policyDoc): void
    {
        abort_unless($policyDoc->container_id === $policy->id, 404);
    }

    /**
     * Whether a newly-added policy document should take over "current" from the existing
     * current one. Only true when both effective_start_dates are known and the new one is
     * on or after the existing current one — an unknown or earlier date means the new
     * document is being backfilled (e.g. an old year added after a newer one already
     * exists) and must not silently supersede the real current document.
     */
    public static function isChronologicallyLater(?\Illuminate\Support\Carbon $newStart, ?\Illuminate\Support\Carbon $previousStart): bool
    {
        return $newStart !== null && $previousStart !== null && $newStart->gte($previousStart);
    }

    public function create(string $level, Department $department, RuleSet $policy): View
    {
        abort_unless(auth()->user()->canManagePolicy($policy), 403);

        return view('rule_sets.policy_documents.create', compact('department', 'policy'));
    }

    public function store(StorePolicyDocumentRequest $request, string $level, Department $department, RuleSet $policy): RedirectResponse
    {
        $validated = $request->validated();
        $slug      = RuleSet::uniqueSlugForDepartment($validated['name'], $department->id);

        // Uploaded up front (outside the transaction, same convention as
        // DocumentController@store) so a failed DB write never leaves an orphaned file
        // and a failed file write never leaves a policy document without one.
        $pdfPath        = null;
        $nativePath     = null;
        $originalFormat = 'pdf';
        $vaultDir       = null;

        if ($request->hasFile('file')) {
            $vaultDir     = implode('/', ['document_vault', $department->level, $department->slug, 'rules', $slug]);
            $file         = $request->file('file');
            $uploadedMime = $file->getMimeType();
            $nativeExt    = Document::NATIVE_MARKITDOWN_MIMES[$uploadedMime] ?? null;
            $timestamp    = now()->format('YmdHis');

            if ($uploadedMime === 'application/pdf') {
                $pdfPath = $file->storeAs($vaultDir, "{$slug}_{$timestamp}.pdf", 'public');
            } elseif ($nativeExt !== null) {
                // Word/Excel/PowerPoint/ODT/RTF/TXT/CSV — same reasoning as
                // DocumentController::createDocumentFromUpload().
                $nativePath     = $file->storeAs($vaultDir, "{$slug}_{$timestamp}.{$nativeExt}", 'public');
                $originalFormat = $nativeExt;
            } else {
                // Images — still need LibreOffice Draw, no native text layer to extract.
                $rawPath = $file->storeAs($vaultDir, "{$slug}_{$timestamp}.upload", 'public');
                if ($rawPath) {
                    try {
                        $convertedPath = (new \App\Services\PdfConversionEngine())->convertToPdf(
                            Storage::disk('public')->path($rawPath),
                            Storage::disk('public')->path($vaultDir)
                        );
                        $pdfPath = "{$vaultDir}/{$slug}_{$timestamp}.pdf";
                        rename($convertedPath, Storage::disk('public')->path($pdfPath));
                        Storage::disk('public')->delete($rawPath);
                    } catch (\Throwable $e) {
                        Storage::disk('public')->delete($rawPath);
                        Log::error('Policy document upload: PDF conversion failed', ['error' => $e->getMessage()]);
                        flash()->error('Could not convert this file to PDF. Please try again or upload a PDF directly.');
                        return back()->withInput();
                    }
                }
            }

            if (! $pdfPath && ! $nativePath) {
                Log::error('Policy document upload: file could not be saved to disk', [
                    'user_id' => $request->user()->id, 'vault_dir' => $vaultDir,
                    'original_filename' => $file->getClientOriginalName(), 'size' => $file->getSize(),
                ]);

                flash()->error('File could not be saved. Please try again.');
                return back()->withInput();
            }
        }

        try {
            DB::transaction(function () use ($request, $department, $policy, $validated, $pdfPath, $nativePath, $originalFormat, $vaultDir, $slug) {
                $newPolicyDoc = $department->ruleSets()->create([
                    'name'                 => $validated['name'],
                    'effective_start_date' => $validated['effective_start_date'] ?? null,
                    'effective_end_date'   => $validated['effective_end_date'] ?? null,
                    'slug'                 => $slug,
                    'kind'                 => 'policy',
                    'state'                => $policy->state,
                    'policy_type'          => $policy->policy_type,
                    'container_id'         => $policy->id,
                ]);

                // A new policy document only supersedes the current one under this same
                // container when it's chronologically later — comparing effective_start_date
                // stops a backfilled older document (e.g. adding "2021-22" after "2024-25"
                // already exists) from wrongly taking over "current". Without both dates to
                // compare, the new document is created as superseded rather than guessing;
                // it can be promoted later via the edit form.
                $previousCurrent = RuleSet::currentPolicy()
                    ->where('container_id', $policy->id)
                    ->where('id', '!=', $newPolicyDoc->id)
                    ->first();

                if ($previousCurrent) {
                    if (self::isChronologicallyLater($newPolicyDoc->effective_start_date, $previousCurrent->effective_start_date)) {
                        $previousCurrent->update(['policy_status' => 'superseded']);
                        $newPolicyDoc->update(['previous_policy_id' => $previousCurrent->id]);
                    } else {
                        $newPolicyDoc->update(['policy_status' => 'superseded']);
                    }
                }

                if ($pdfPath || $nativePath) {
                    $this->storePolicyDocument($request, $department, $newPolicyDoc, $validated, $pdfPath, $nativePath, $originalFormat, $vaultDir);
                }
            });

            flash()->success("Policy \"{$validated['name']}\" created.");
            return redirect()->route('departments.policy.show', [$department->levelAlias(), $department, $policy]);
        } catch (\Throwable $e) {
            if ($pdfPath) {
                Storage::disk('public')->delete($pdfPath);
            }
            if ($nativePath) {
                Storage::disk('public')->delete($nativePath);
            }

            Log::error('PolicyDocumentController@store failed', [
                'policy_id' => $policy->id,
                'error'     => $e->getMessage(),
            ]);
            flash()->error('Failed to create policy. Please try again.');
            return back()->withInput();
        }
    }

    /** Creates the policy document's root Document(s) from the file uploaded alongside it. */
    private function storePolicyDocument(Request $request, Department $department, RuleSet $policyDoc, array $validated, ?string $pdfPath, ?string $nativePath, string $originalFormat, string $vaultDir): void
    {
        $requireApproval = $request->user()->shouldRequireApproval($policyDoc);
        $initialStatus   = $requireApproval ? 'pending_approval' : 'uploaded';
        $language        = $validated['language'] ?? 'english';

        $doc = Document::create([
            'department_id'     => $department->id,
            'rule_set_id'       => $policyDoc->id,
            'user_id'           => $request->user()->id,
            'title'             => $policyDoc->name,
            'slug'              => $policyDoc->slug,
            'document_type'     => 'policy',
            'language'          => $language,
            'original_filename' => preg_replace('/[^\w\s\-\.\(\)]/', '_', $request->file('file')->getClientOriginalName()),
            'original_pdf_path' => $pdfPath,
            'native_path'       => $nativePath,
            'original_format'   => $originalFormat,
            'vault_path'        => $vaultDir,
            'status'            => $initialStatus,
            'visibility'        => $validated['visibility'] ?? 'public',
        ]);

        DocumentStatusHistory::create([
            'document_id' => $doc->id,
            'actor_id'    => $request->user()->id,
            'from_status' => null,
            'to_status'   => $initialStatus,
            'note'        => $initialStatus === 'pending_approval'
                ? 'Document submitted for approval.'
                : 'Document uploaded.',
        ]);
    }

    public function show(Request $request, string $level, Department $department, RuleSet $policy, RuleSet $policyDoc): View
    {
        $this->assertBelongsTo($policy, $policyDoc);

        return view('rule_sets.show', array_merge(
            compact('department', 'policy'),
            ['ruleSet' => $policyDoc],
            $this->loadRuleSetDocuments($policyDoc, $request)
        ));
    }

    public function edit(string $level, Department $department, RuleSet $policy, RuleSet $policyDoc): View
    {
        $this->assertBelongsTo($policy, $policyDoc);
        abort_unless(auth()->user()->canManagePolicy($policyDoc), 403);

        return view('rule_sets.policy_documents.edit', compact('department', 'policy', 'policyDoc'));
    }

    public function update(UpdatePolicyDocumentRequest $request, string $level, Department $department, RuleSet $policy, RuleSet $policyDoc): RedirectResponse
    {
        $this->assertBelongsTo($policy, $policyDoc);
        $validated = $request->validated();
        $markCurrent = (bool) ($validated['mark_as_current'] ?? false);
        unset($validated['mark_as_current']);

        try {
            DB::transaction(function () use ($policyDoc, $policy, $validated, $markCurrent) {
                $policyDoc->update($validated);

                if ($markCurrent && $policyDoc->policy_status !== 'current') {
                    RuleSet::currentPolicy()
                        ->where('container_id', $policy->id)
                        ->where('id', '!=', $policyDoc->id)
                        ->update(['policy_status' => 'superseded']);

                    $policyDoc->update(['policy_status' => 'current']);
                }
            });

            flash()->success("Policy \"{$policyDoc->name}\" updated.");
            return redirect()->route('departments.policy.periods.show', [$department->levelAlias(), $department, $policy, $policyDoc]);
        } catch (\Throwable $e) {
            Log::error('PolicyDocumentController@update failed', [
                'policy_document_id' => $policyDoc->id,
                'error'              => $e->getMessage(),
            ]);
            flash()->error('Failed to update policy. Please try again.');
            return back()->withInput();
        }
    }

    public function destroy(string $level, Department $department, RuleSet $policy, RuleSet $policyDoc): RedirectResponse
    {
        $this->assertBelongsTo($policy, $policyDoc);
        abort_unless(auth()->user()->canManagePolicy($policyDoc), 403);

        $docsToArchive = [];

        try {
            DB::transaction(function () use ($policyDoc, &$docsToArchive) {
                $policyDoc->documents()->each(function (Document $doc) use (&$docsToArchive) {
                    DocumentStatusHistory::create([
                        'document_id' => $doc->id,
                        'actor_id'    => auth()->id(),
                        'from_status' => $doc->status,
                        'to_status'   => 'deleted',
                        'note'        => 'Deleted with parent policy.',
                    ]);
                    $doc->delete();
                    $docsToArchive[] = $doc;
                });

                $policyDoc->delete();
            });

            foreach ($docsToArchive as $doc) {
                $this->archiveFiles($doc);
            }

            flash()->success("Policy \"{$policyDoc->name}\" and all its documents deleted.");
            return redirect()->route('departments.policy.show', [$department->levelAlias(), $department, $policy]);
        } catch (\Throwable $e) {
            Log::error('PolicyDocumentController@destroy failed', [
                'policy_document_id' => $policyDoc->id,
                'error'              => $e->getMessage(),
            ]);
            flash()->error('Failed to delete policy. Please try again.');
            return back();
        }
    }
}

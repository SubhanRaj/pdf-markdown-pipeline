<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsUploadScopeTree;
use App\Http\Requests\PlaceQuickConversionRequest;
use App\Http\Requests\StoreQuickConversionRequest;
use App\Jobs\ConvertQuickConversionToMarkdown;
use App\Jobs\PruneQuickConversion;
use App\Jobs\RunQuickConversionOcrExtraction;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\Folder;
use App\Models\QuickConversion;
use App\Models\RuleSet;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Standalone "New Conversion" flow — upload a single PDF, get it converted to Markdown, then
 * decide whether to save it into the real vault (as a Document), download the Markdown, or
 * discard it. See NEW_CONVERSION_PLAN.md.
 */
class QuickConversionController extends Controller
{
    use BuildsUploadScopeTree;

    /** Every action here uses this inline check instead of a policy class — same convention as DocumentController::authorizeEdit(). */
    private function authorizeOwner(QuickConversion $quickConversion): void
    {
        $user = auth()->user();
        abort_unless($quickConversion->user_id === $user->id || $user->isAdmin(), 403);
    }

    public function create(): View
    {
        return view('quick_conversions.create');
    }

    /** "My Conversions" — lets a user get back to one after navigating away, instead of re-uploading. */
    public function index(): View
    {
        $quickConversions = QuickConversion::where('user_id', auth()->id())
            ->orderByDesc('created_at')
            ->get();

        return view('quick_conversions.index', compact('quickConversions'));
    }

    public function store(StoreQuickConversionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $ttlHours = (int) config('quick-conversions.ttl_hours', 48);

        try {
            $quickConversion = null;

            DB::transaction(function () use ($request, $validated, $ttlHours, &$quickConversion) {
                $quickConversion = QuickConversion::create([
                    'user_id'            => $request->user()->id,
                    'title'              => $validated['title'] ?: null,
                    'original_filename'  => preg_replace('/[^\w\s\-\.\(\)]/', '_', $request->file('file')->getClientOriginalName()),
                    'pdf_path'           => '', // set below once we know the id
                    'status'             => 'uploaded',
                    'expires_at'         => now()->addHours($ttlHours),
                ]);

                $pdfPath = $request->file('file')->storeAs("quick_conversions/{$quickConversion->id}", 'original.pdf', 'public');

                if (! $pdfPath) {
                    throw new \RuntimeException('File could not be saved.');
                }

                $quickConversion->update(['pdf_path' => $pdfPath]);
            });

            ConvertQuickConversionToMarkdown::dispatch($quickConversion->id);
            PruneQuickConversion::dispatch($quickConversion->id)->delay($quickConversion->expires_at);

            return response()->json(['redirect' => route('conversions.show', $quickConversion)]);
        } catch (\Throwable $e) {
            Log::error('QuickConversionController@store failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Upload failed. Please try again.'], 500);
        }
    }

    public function show(QuickConversion $quickConversion): View
    {
        $this->authorizeOwner($quickConversion);

        $mdRaw = ($quickConversion->markdown_path && Storage::disk('public')->exists($quickConversion->markdown_path))
            ? Storage::disk('public')->get($quickConversion->markdown_path)
            : null;

        $tree = $this->buildUploadScopeTree(auth()->user());

        return view('quick_conversions.show', compact('quickConversion', 'mdRaw', 'tree'));
    }

    public function status(QuickConversion $quickConversion): JsonResponse
    {
        $this->authorizeOwner($quickConversion);

        $queuedElsewhere = false;
        if (in_array($quickConversion->status, ['processing', 'ocr_pending'], true)) {
            $queuedElsewhere = DB::table('jobs')->whereNotNull('reserved_at')->pluck('payload')
                ->contains(function (string $payload) use ($quickConversion) {
                    $command = json_decode($payload, true)['data']['command'] ?? '';
                    preg_match('/"quickConversionId";i:(\d+);/', $command, $m);

                    return isset($m[1]) && (int) $m[1] !== $quickConversion->id;
                });
        }

        return response()->json([
            'status'                  => $quickConversion->status,
            'queued_behind_other_job' => $queuedElsewhere,
            'extraction_method'       => $quickConversion->metadata['extraction_method'] ?? null,
            'ocr_engine'              => $quickConversion->metadata['ocr_engine'] ?? null,
            'needs_ocr_review'        => (bool) ($quickConversion->metadata['needs_ocr_review'] ?? false),
            'has_markdown'            => (bool) ($quickConversion->markdown_path && Storage::disk('public')->exists($quickConversion->markdown_path)),
            'error_message'           => $quickConversion->error_message,
        ]);
    }

    /** Manual OCR retry — mirrors DocumentController::convertOcr(). */
    public function runOcr(QuickConversion $quickConversion, Request $request): JsonResponse
    {
        $this->authorizeOwner($quickConversion);

        if (! in_array($quickConversion->status, ['review', 'failed'], true)) {
            return response()->json(['message' => 'Conversion is not in a state that supports OCR re-extraction.'], 422);
        }

        if (! $quickConversion->pdf_path || ! Storage::disk('public')->exists($quickConversion->pdf_path)) {
            return response()->json(['message' => 'Original PDF file not found.'], 404);
        }

        $engine = $request->input('engine', config('ocr.default'));

        if (! array_key_exists($engine, config('ocr.engines'))) {
            return response()->json(['message' => 'Unknown OCR engine selected.'], 422);
        }

        $quickConversion->update(['status' => 'ocr_pending']);

        RunQuickConversionOcrExtraction::dispatch($quickConversion->id, $engine);

        return response()->json(['status' => 'ocr_pending']);
    }

    /** Save edits made to the Markdown, pre-placement. Mirrors DocumentController::updateMarkdown() minus verify/history bookkeeping. */
    public function updateMarkdown(QuickConversion $quickConversion, Request $request): JsonResponse
    {
        $this->authorizeOwner($quickConversion);

        if (! $quickConversion->markdown_path) {
            return response()->json(['message' => 'This conversion has no Markdown to edit yet.'], 422);
        }

        $validated = $request->validate(['content' => ['required', 'string', 'max:2000000']]);

        try {
            Storage::disk('public')->put($quickConversion->markdown_path, $validated['content']);
            $quickConversion->update(['metadata' => array_merge($quickConversion->metadata ?? [], ['manually_edited' => true])]);

            return response()->json(['status' => 'saved']);
        } catch (\Throwable $e) {
            Log::error('QuickConversionController@updateMarkdown failed', ['quick_conversion_id' => $quickConversion->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to save changes. Please try again.'], 500);
        }
    }

    /** Download the converted Markdown directly — for users who just want the file, nothing saved into the vault. */
    public function download(QuickConversion $quickConversion): StreamedResponse
    {
        $this->authorizeOwner($quickConversion);

        if (! $quickConversion->markdown_path || ! Storage::disk('public')->exists($quickConversion->markdown_path)) {
            abort(404, 'Markdown file not found.');
        }

        $filename = preg_replace('/\.pdf$/i', '.md', $quickConversion->original_filename ?: 'document.pdf');

        return Storage::disk('public')->download($quickConversion->markdown_path, $filename);
    }

    /** Discard — delete files + row immediately. */
    public function destroy(QuickConversion $quickConversion): JsonResponse
    {
        $this->authorizeOwner($quickConversion);

        foreach ([$quickConversion->pdf_path, $quickConversion->markdown_path, $quickConversion->structure_path] as $path) {
            if ($path) {
                Storage::disk('public')->delete($path);
            }
        }

        $quickConversion->delete();

        return response()->json(['status' => 'discarded']);
    }

    /**
     * "Save to…" — promote this QuickConversion into a real, permanent Document. Reuses the
     * same four destination branches and Document::uniqueSlugFor*() calls as
     * DocumentController::store(), but moves the already-converted files instead of storing a
     * freshly uploaded one.
     */
    public function place(PlaceQuickConversionRequest $request, QuickConversion $quickConversion): JsonResponse
    {
        $this->authorizeOwner($quickConversion);

        if (! in_array($quickConversion->status, ['review', 'failed'], true)) {
            return response()->json(['message' => 'This conversion is not ready to be saved yet.'], 422);
        }

        $validated = $request->validated();

        $ruleSet  = null;
        $division = null;
        $folder   = null;

        if (! empty($validated['rule_set_id'])) {
            $ruleSet    = RuleSet::with('department')->findOrFail($validated['rule_set_id']);
            $department = $ruleSet->department;
            $section    = null;

            $vaultDir = implode('/', [
                'document_vault', $department->level, $department->slug, 'rules', $ruleSet->slug,
            ]);

            $slug = Document::uniqueSlugForRuleSet($validated['title'], $ruleSet->id);
        } elseif (! empty($validated['folder_id'])) {
            $folder     = Folder::with('division.section.department', 'section.department')->findOrFail($validated['folder_id']);
            $section    = $folder->section;
            $division   = $folder->division;
            $department = $section->department;

            $vaultDir = implode('/', array_filter([
                'document_vault', $department->level, $department->slug, $section->wing, $section->slug,
                $division ? 'divisions' : null, $division?->slug, 'folders', $folder->slug,
            ]));

            $slug = Document::uniqueSlugForFolder($validated['title'], $folder->id);
        } elseif (! empty($validated['division_id'])) {
            $division   = Division::with('section.department')->findOrFail($validated['division_id']);
            $section    = $division->section;
            $department = $section->department;

            $vaultDir = implode('/', array_filter([
                'document_vault', $department->level, $department->slug, $section->wing, $section->slug,
                'divisions', $division->slug,
            ]));

            $slug = Document::uniqueSlugForDivision($validated['title'], $division->id);
        } else {
            $section    = Section::with('department')->findOrFail($validated['section_id']);
            $department = $section->department;

            $vaultDir = implode('/', array_filter([
                'document_vault', $department->level, $department->slug, $section->wing, $section->slug,
            ]));

            $slug = Document::uniqueSlugForSection($validated['title'], $section->id);
        }

        $timestamp = now()->format('YmdHis');
        $baseName  = "{$slug}_{$timestamp}";

        $newPdfPath = "{$vaultDir}/{$baseName}.pdf";
        $newMarkdownPath = $quickConversion->markdown_path ? "{$vaultDir}/{$baseName}.md" : null;

        $uploadContext   = $folder ?? $division ?? $section ?? $ruleSet;
        $requireApproval = $uploadContext && $request->user()->shouldRequireApproval($uploadContext);
        $initialStatus   = $requireApproval ? 'pending_approval' : $quickConversion->status;

        try {
            Storage::disk('public')->move($quickConversion->pdf_path, $newPdfPath);
            if ($newMarkdownPath) {
                Storage::disk('public')->move($quickConversion->markdown_path, $newMarkdownPath);
            }
            if ($quickConversion->structure_path && Storage::disk('public')->exists($quickConversion->structure_path)) {
                Storage::disk('public')->move($quickConversion->structure_path, "{$vaultDir}/{$baseName}.structure.json");
            }

            $document = null;
            $language = $validated['language'] ?? 'english';

            DB::transaction(function () use ($validated, $section, $ruleSet, $division, $folder, $department, $vaultDir, $newPdfPath, $newMarkdownPath, $slug, $request, $initialStatus, $language, $quickConversion, &$document) {
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
                    'language'          => $language,
                    'original_filename' => $quickConversion->original_filename,
                    'original_pdf_path' => $newPdfPath,
                    'markdown_path'     => $newMarkdownPath,
                    'vault_path'        => $vaultDir,
                    'status'            => $initialStatus,
                    'visibility'        => $validated['visibility'] ?? 'public',
                    'metadata'          => $quickConversion->metadata,
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => $request->user()->id,
                    'from_status' => null,
                    'to_status'   => $initialStatus,
                    'note'        => $initialStatus === 'pending_approval'
                        ? 'Document submitted for approval (via New Conversion).'
                        : 'Document created from New Conversion.',
                ]);

                $quickConversion->delete();
            });

            if ($initialStatus === 'pending_approval') {
                flash()->info("\"{$validated['title']}\" submitted and is pending approval before becoming visible.");
            } else {
                flash()->success("\"{$validated['title']}\" saved successfully.");
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
            Log::error('QuickConversionController@place failed', ['quick_conversion_id' => $quickConversion->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to save document. Please try again.'], 500);
        }
    }
}

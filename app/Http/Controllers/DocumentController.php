<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\BuildsUploadScopeTree;
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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class DocumentController extends Controller
{
    use BuildsUploadScopeTree, ManagesDocumentFiles;

    /**
     * Same authorize() logic as UpdateDocumentRequest, duplicated here because the review/edit
     * GET forms render outside a FormRequest. See SECURITY.md H-04.
     */
    /**
     * Mirrors UpdateDocumentRequest::authorize() exactly (this gates the GET edit-form routes,
     * that gates the PATCH) — system_admin unconditionally; a policy document's department.head;
     * or (M79, 2026-08-19) any user holding documents.edit scoped via canUploadTo() against the
     * document's own context. Kept in sync by hand; if this drifts, merge the two.
     */
    private function authorizeEdit(Document $document): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        $ruleSet = $document->ruleSet;

        if ($ruleSet !== null && $ruleSet->kind === 'policy' && $user->canManagePolicy($ruleSet)) {
            return;
        }

        $context = $document->division ?? $document->section ?? $ruleSet;

        abort_unless($context !== null && $user->hasPrivilege('documents.edit') && $user->canUploadTo($context), 403);
    }

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
            ->viewableBy(auth()->user())
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $documents = Document::with(['department', 'section', 'division', 'ruleSet', 'folder', 'user:id,name'])
            ->whereIn('status', $activeStatus ? [$activeStatus] : $pipelineStatuses)
            ->viewableBy(auth()->user())
            ->orderByDesc('updated_at')
            ->paginate(30)
            ->withQueryString();

        return view('documents.pipeline', compact('documents', 'counts', 'activeStatus', 'pipelineStatuses'));
    }

    /**
     * Health check for the queue worker itself — separate from Laravel's built-in `/up`, which
     * only proves the app boots, not that background jobs are actually moving. "Healthy" here
     * means a DocumentStatusHistory row (written by every job status transition) landed recently;
     * if the queue worker died/hung, this timestamp goes stale even though `/up` would still
     * report fine. Meant to be checked remotely (e.g. through a tunnel) without SSHing in.
     *
     * Content-negotiated on one URL: a browser (Accept: text/html) gets the dashboard view below;
     * a script/monitor explicitly asking for JSON (Accept: application/json, or ?format=json for
     * curl without headers) gets the same data as JSON.
     */
    public function pipelineHealth(Request $request): View|JsonResponse
    {
        $pipelineStatuses = ['uploaded', 'processing', 'ocr_pending', 'review', 'failed'];

        $counts = Document::whereIn('status', $pipelineStatuses)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $lastActivity = DocumentStatusHistory::latest('created_at')->value('created_at');
        $pendingJobs  = DB::table('jobs')->count();
        $failedJobs   = DB::table('failed_jobs')->count();

        // Nothing queued means there's nothing to be stalled on — only judge staleness against
        // last-activity when there's an actual backlog waiting to be worked through.
        $isStalled = $pendingJobs > 0 && (! $lastActivity || $lastActivity->lt(now()->subMinutes(15)));

        $data = [
            'status'            => $isStalled ? 'stalled' : 'healthy',
            'checked_at'        => now(),
            'last_job_activity' => $lastActivity,
            'last_activity_ago' => $lastActivity?->diffForHumans(),
            'pending_jobs'      => $pendingJobs,
            'failed_jobs'       => $failedJobs,
            'documents'         => $counts,
            'server'            => $this->serverVitals(),
            'log_signals'       => $this->recentLogSignals(),
        ];

        $wantsJson = $request->query('format') === 'json' || ($request->wantsJson() && $request->query('format') !== 'html');

        if ($wantsJson) {
            return response()->json([...$data, 'checked_at' => $data['checked_at']->toIso8601String(), 'last_job_activity' => $lastActivity?->toIso8601String()]);
        }

        return view('documents.pipeline-health', ['pipelineStatuses' => $pipelineStatuses, ...$data]);
    }

    /**
     * Load average, memory, and CPU temperature — read straight from /proc and /sys, no shell-out
     * and no extra monitoring software needed. Added so the same tunnel-exposed, auth-gated route
     * that reports queue health can also answer "is the machine itself okay" (this box runs with
     * only case fans, no AC, so temperature in particular matters when it's left unattended).
     * Best-effort: every read is wrapped so a missing /proc or /sys path (e.g. a container, or a
     * kernel without hwmon) degrades to null instead of a 500.
     */
    private function serverVitals(): array
    {
        $loadAvg = sys_getloadavg();

        $memInfo = @file_get_contents('/proc/meminfo');
        $memTotalKb = $memAvailableKb = null;
        if ($memInfo !== false) {
            if (preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m)) $memTotalKb = (int) $m[1];
            if (preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $m)) $memAvailableKb = (int) $m[1];
        }

        // Highest reading across all thermal zones — on a multi-sensor board (coretemp per-core,
        // acpi, etc.) that's the one worth alerting on, not any single zone.
        $tempC = null;
        foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $zoneFile) {
            $raw = (int) trim((string) @file_get_contents($zoneFile));
            if ($raw > 0) {
                $tempC = max($tempC ?? 0, $raw / 1000);
            }
        }

        $cpuCount = null;
        try {
            $nproc = Process::run(['nproc']);
            $cpuCount = $nproc->successful() ? (int) trim($nproc->output()) : null;
        } catch (\Throwable) {
            // best-effort only
        }

        return [
            'load_avg_1m'         => $loadAvg[0] ?? null,
            'load_avg_5m'         => $loadAvg[1] ?? null,
            'load_avg_15m'        => $loadAvg[2] ?? null,
            'cpu_count'           => $cpuCount,
            'memory_total_mb'     => $memTotalKb ? round($memTotalKb / 1024) : null,
            'memory_available_mb' => $memAvailableKb ? round($memAvailableKb / 1024) : null,
            'cpu_temp_c'          => $tempC,
        ];
    }

    /**
     * Native replacement for two of Pulse's cards (Exceptions, SlowQueries) — removed
     * 2026-07-26 along with the rest of Pulse/Livewire (see AppServiceProvider's
     * configureSlowQueryLogging()). Rather than a package with its own storage tables and a
     * Livewire dashboard, this just tails the existing log file (already written to on every
     * exception and now on every slow query too) and counts recent lines — same information,
     * no extra moving parts. Only reads the last 2MB so this stays cheap even once the log
     * file itself is large.
     */
    private function recentLogSignals(): array
    {
        $path = storage_path('logs/laravel.log');
        if (! is_file($path)) {
            return ['errors_last_hour' => 0, 'slow_queries_last_hour' => 0];
        }

        $size = filesize($path);
        $handle = fopen($path, 'r');
        if ($size > 2_000_000) {
            fseek($handle, -2_000_000, SEEK_END);
        }
        $tail = stream_get_contents($handle);
        fclose($handle);

        $cutoff = now()->subHour();
        $errors = 0;
        $slowQueries = 0;

        foreach (explode("\n", $tail) as $line) {
            if (! preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $m)) {
                continue;
            }
            if (\Illuminate\Support\Carbon::parse($m[1])->lt($cutoff)) {
                continue;
            }
            if (str_contains($line, '.ERROR:')) {
                $errors++;
            } elseif (str_contains($line, 'SLOW_QUERY')) {
                $slowQueries++;
            }
        }

        return ['errors_last_hour' => $errors, 'slow_queries_last_hour' => $slowQueries];
    }

    public function index(): View
    {
        $query = Document::with(['department', 'section', 'division', 'ruleSet', 'folder', 'user:id,name'])
            ->viewableBy(auth()->user())
            ->orderByDesc('created_at');

        if (! auth()->check()) {
            $query->publiclyVisible();
        }

        $byDepartment = $query->get()->groupBy('department_id');

        return view('documents.index', compact('byDepartment'));
    }

    /**
     * Guest check (Document::isPubliclyVisible()) plus, for authenticated non-global users, an
     * org-scope ceiling (User::canView()) against the document's own section/division —
     * mirrors the folder-visibility-ceiling pattern (M-04/SECURITY.md) but for organisational
     * scope instead of the public/authenticated visibility flag. Never applied to rule-set
     * documents (Acts/Rules/Policies stay department-wide — see User::canView()'s docblock),
     * so callers for those routes keep the plain guest-only check inline instead of calling this.
     */
    private function authorizeDocumentView(Document $document, object $context): void
    {
        if (! auth()->check()) {
            abort_unless($document->isPubliclyVisible(), 403);
            return;
        }

        abort_unless(auth()->user()->canView($context) || $document->isPubliclyVisible(), 403);
    }

    public function show(string $level, Department $department, Section $section, Document $document): View
    {
        $this->authorizeDocumentView($document, $section);

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at', 'siblingDocument:id,title,slug,language,rule_set_id,section_id,division_id,folder_id']);
        return view('documents.show', compact('document', 'department', 'section'));
    }

    public function pdf(string $level, Department $department, Section $section, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeDocumentView($document, $section);

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

    /**
     * Page-1 thumbnail used as the og:image/twitter:image for a document's share preview —
     * generated on first request (rasterizing 300+ pages upfront for a batch import would be
     * wasted work for documents that never get shared) and cached as a sibling file on the
     * public disk from then on, same convention as .md/.structure.json. Social-media crawlers
     * (WhatsApp, Slack, etc.) fetch this unauthenticated, so it only ever renders for documents
     * that are already public — everything else falls back to the site's generic OG banner
     * (see documents.show / rule_sets.show passing null image when visibility isn't public).
     */
    public function ogImage(Document $document): \Symfony\Component\HttpFoundation\StreamedResponse|RedirectResponse
    {
        if (! $document->isPubliclyVisible() || ! $document->original_pdf_path || ! Storage::disk('public')->exists($document->original_pdf_path)) {
            return redirect(asset('og-default.jpg'));
        }

        $thumbPath = preg_replace('/\.pdf$/i', '.og.jpg', $document->original_pdf_path);

        if (! Storage::disk('public')->exists($thumbPath)) {
            $absolutePdfPath = Storage::disk('public')->path($document->original_pdf_path);
            $absoluteThumbPath = Storage::disk('public')->path($thumbPath);

            $result = Process::timeout(30)->run([
                'pdftoppm', '-jpeg', '-f', '1', '-l', '1', '-singlefile',
                '-scale-to-x', '1200', '-scale-to-y', '-1',
                $absolutePdfPath, preg_replace('/\.jpg$/', '', $absoluteThumbPath),
            ]);

            if (! $result->successful() || ! Storage::disk('public')->exists($thumbPath)) {
                Log::warning('OG thumbnail generation failed', ['document_id' => $document->id, 'error' => $result->errorOutput()]);
                return redirect(asset('og-default.jpg'));
            }
        }

        // Explicit public/long-lived cache headers — without this, the default web middleware
        // stack's session cookie makes the response implicitly private, and Cloudflare's edge
        // won't cache a response carrying Set-Cookie at all (bypasses to origin every hit,
        // confirmed via cf-cache-status: BYPASS). A page-1 render never changes for a given
        // document, so it's safe to cache aggressively; verifying/re-uploading changes the
        // filename (new timestamp suffix), which naturally busts this.
        return Storage::disk('public')->response($thumbPath, null, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
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

        $timestamp   = now()->format('YmdHis');
        $uploadedMime = $request->file('file')->getMimeType();
        $storedName  = "{$slug}_{$timestamp}." . ($uploadedMime === 'application/pdf' ? 'pdf' : 'upload');
        $storedPath  = $request->file('file')->storeAs($vaultDir, $storedName, 'public');

        if (! $storedPath) {
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        $pdfPath = "{$vaultDir}/{$slug}_{$timestamp}.pdf";

        if ($uploadedMime === 'application/pdf') {
            $pdfPath = $storedPath;
        } else {
            // Non-PDF accepted types (Word/Excel/PowerPoint/ODT/RTF/TXT/CSV/images) must be
            // converted to a real PDF before the OCR/markdown pipeline (which expects actual
            // PDF bytes) ever sees them — LibreOffice headless handles every accepted type,
            // images included, via its Draw component.
            $absoluteDir     = Storage::disk('public')->path($vaultDir);
            $absoluteUpload  = Storage::disk('public')->path($storedPath);
            // -env:UserInstallation avoids relying on a writable $HOME for the www-data user
            // (it has none — /var/www is root-owned); a per-conversion profile dir under
            // storage/app also keeps concurrent uploads from sharing/locking one profile.
            $profileDir      = storage_path('app/soffice-profile-' . Str::random(16));
            $convertResult   = Process::timeout(120)->run([
                'soffice', '--headless', '--convert-to', 'pdf', '--outdir', $absoluteDir,
                '-env:UserInstallation=file://' . $profileDir, $absoluteUpload,
            ]);
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($profileDir);

            $convertedPath = $absoluteDir . '/' . pathinfo($storedName, PATHINFO_FILENAME) . '.pdf';

            if (! $convertResult->successful() || ! is_file($convertedPath)) {
                Storage::disk('public')->delete($storedPath);
                Log::error('Document upload PDF conversion failed', [
                    'file'   => $storedName,
                    'output' => $convertResult->errorOutput(),
                ]);

                return response()->json(['message' => 'Could not convert this file to PDF. Please try again or upload a PDF directly.'], 500);
            }

            rename($convertedPath, Storage::disk('public')->path($pdfPath));
            Storage::disk('public')->delete($storedPath);
        }

        // Determine if this upload requires approval (bulk operator flag or context flag)
        $uploadContext   = $folder ?? $division ?? $section ?? $ruleSet;
        $requireApproval = $uploadContext && $request->user()->shouldRequireApproval($uploadContext);
        $initialStatus   = $requireApproval ? 'pending_approval' : 'uploaded';

        try {
            $document = null;

            $metadata = $this->extractMetadata($validated);

            // 'both' is a real, standalone language value (a bilingual document in its own
            // right) — it coexists alongside separate english/hindi uploads for the same
            // rule_set rather than expanding into a pair of them.
            $language = $validated['language'] ?? 'english';

            DB::transaction(function () use ($validated, $section, $ruleSet, $division, $folder, $department, $vaultDir, $pdfPath, $slug, $request, $metadata, $initialStatus, $language, &$document) {
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
        $this->authorizeEdit($document);

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
        $ids      = $request->validated()['ids'];
        $reason   = $request->validated()['reason'];
        $actor    = auth()->id();
        $authUser = auth()->user();

        $archived = [];
        $deleted  = 0;

        try {
            DB::transaction(function () use ($ids, $reason, $actor, $authUser, &$archived, &$deleted) {
                foreach ($ids as $id) {
                    $document = Document::findOrFail($id);

                    // Enforce scope: same boundary as single-delete and canDeleteFrom() —
                    // admins bypass unconditionally, matching bulkRestore()/bulkForceDestroy().
                    if (! $authUser->isAdmin()) {
                        $context = $document->division ?? $document->section ?? $document->ruleSet;
                        if ($context && ! $authUser->canDeleteFrom($context)) {
                            continue;
                        }
                    }

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
        if (! auth()->check() && ! $document->isPubliclyVisible()) {
            abort(403);
        }

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at', 'siblingDocument:id,title,slug,language,rule_set_id,section_id,division_id,folder_id']);
        return view('documents.show', compact('document', 'department', 'ruleSet'));
    }

    public function pdfRuleSetDoc(string $level, Department $department, RuleSet $ruleSet, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! auth()->check() && ! $document->isPubliclyVisible()) {
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
        $this->authorizeEdit($document);

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
        $this->authorizeDocumentView($document, $division);

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at', 'siblingDocument:id,title,slug,language,rule_set_id,section_id,division_id,folder_id']);
        return view('documents.show', compact('document', 'department', 'section', 'division'));
    }

    public function pdfDivisionDoc(string $level, Department $department, Section $section, Division $division, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeDocumentView($document, $division);

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
        $this->authorizeEdit($document);

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
        $this->authorizeDocumentView($document, $section);

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at', 'siblingDocument:id,title,slug,language,rule_set_id,section_id,division_id,folder_id']);
        return view('documents.show', compact('document', 'department', 'section', 'folder'));
    }

    public function pdfSectionFolderDoc(string $level, Department $department, Section $section, Folder $folder, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeDocumentView($document, $section);

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
        $this->authorizeEdit($document);

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
        $this->authorizeDocumentView($document, $division);

        $document->load(['user:id,name', 'statusHistory.actor:id,name', 'parentDocument:id,title,slug,created_at', 'amendments:id,parent_id,title,slug,status,visibility,created_at', 'siblingDocument:id,title,slug,language,rule_set_id,section_id,division_id,folder_id']);
        return view('documents.show', compact('document', 'department', 'section', 'division', 'folder'));
    }

    public function pdfDivisionFolderDoc(string $level, Department $department, Section $section, Division $division, Folder $folder, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeDocumentView($document, $division);

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
        $this->authorizeEdit($document);

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
     * System admin; or (for a policy-kind rule-set document only) the owning department's
     * department.head; or any user holding documents.verify scoped to this document's own
     * context (division/section/rule-set) — gate for the actual approval action (verify()).
     * Everyone else is view-only for approval.
     */
    private function canManageDocument(Document $document): bool
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return true;
        }

        $ruleSet = $document->ruleSet;

        if ($ruleSet !== null && $ruleSet->kind === 'policy' && $user->canManagePolicy($ruleSet)) {
            return true;
        }

        $context = $document->division ?? $document->section ?? $ruleSet;

        return $context !== null && $user->hasPrivilege('documents.verify') && $user->canUploadTo($context);
    }

    /**
     * Same shape as canManageDocument(), but for producing/refining the Markdown draft —
     * convert/convertOcr/revertOcr/discardMarkdown/structureJson — which only needs upload
     * scope, not the stricter documents.verify approval privilege. Whoever can put a document
     * in this section/division should be able to run it through the pipeline; only the final
     * "mark verified" step (verify(), UpdateDocumentMarkdownRequest's Save & Verify) needs
     * documents.verify.
     */
    private function canConvertDocument(Document $document): bool
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return true;
        }

        $ruleSet = $document->ruleSet;

        if ($ruleSet !== null && $ruleSet->kind === 'policy' && $user->canManagePolicy($ruleSet)) {
            return true;
        }

        $context = $document->division ?? $document->section ?? $ruleSet;

        return $context !== null && $user->hasPrivilege('documents.upload') && $user->canUploadTo($context);
    }

    public function convert(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canConvertDocument($document)) {
            abort(403);
        }

        if (! in_array($document->status, ['uploaded', 'review', 'verified', 'failed'], true)) {
            return response()->json(['message' => 'Document is not in a convertible state.'], 422);
        }

        if (! $document->original_pdf_path || ! Storage::disk('public')->exists($document->original_pdf_path)) {
            return response()->json(['message' => 'Original PDF file not found.'], 404);
        }

        $structureEngine = request()->input('structure_engine', config('docling.default_ocr_engine'));

        if (! array_key_exists($structureEngine, config('docling.ocr_engines'))) {
            return response()->json(['message' => 'Unknown structure OCR engine selected.'], 422);
        }

        DB::transaction(function () use ($document) {
            $oldStatus = $document->status;
            $document->update(['status' => 'processing']);

            DocumentStatusHistory::create([
                'document_id' => $document->id,
                'actor_id'    => auth()->id(),
                'from_status' => $oldStatus,
                'to_status'   => 'processing',
                'note'        => 'Convert to Markdown queued.',
            ]);
        });

        ConvertDocumentToMarkdown::dispatch($document->id, $structureEngine);

        return response()->json(['status' => 'processing']);
    }

    /**
     * Accept a document's extracted Markdown as-is, no edits — the same "Verified" transition
     * updateMarkdown() makes when its `verify` flag is set, but without requiring the caller to
     * resubmit the (potentially multi-MB) Markdown content just to flip a status. Used by the
     * Conversion Pipeline's per-row "Accept" button and its bulk "Verify Selected" action.
     */
    public function verify(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canManageDocument($document)) {
            abort(403);
        }

        if ($document->status !== 'review') {
            return response()->json(['message' => 'Document is not awaiting review.'], 422);
        }

        if (! $document->markdown_path || ! Storage::disk('public')->exists($document->markdown_path)) {
            return response()->json(['message' => 'No Markdown draft to verify.'], 422);
        }

        DB::transaction(function () use ($document) {
            $document->update(['status' => 'verified']);

            DocumentStatusHistory::create([
                'document_id' => $document->id,
                'actor_id'    => auth()->id(),
                'from_status' => 'review',
                'to_status'   => 'verified',
                'note'        => 'Verified via Conversion Pipeline.',
            ]);
        });

        return response()->json(['status' => 'verified']);
    }

    /**
     * Returns the compact Docling structure map (headings + table cells + bboxes) produced by
     * ConvertDocumentToMarkdown's Pass 0, for the informational "Structure" panel in the review
     * UI. Gated the same as every other document-mutation-adjacent endpoint in this
     * controller — this is new code added after H-04/H-05/L-04 (see SECURITY.md), so it does
     * not repeat the "forgot the authorization check" pattern those fixes closed.
     */
    public function structureJson(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canConvertDocument($document)) {
            abort(403);
        }

        $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $document->original_pdf_path ?? '');

        if (! $structurePath || ! Storage::disk('public')->exists($structurePath)) {
            return response()->json(['message' => 'No structure analysis available for this document.'], 404);
        }

        return response()->json(json_decode(Storage::disk('public')->get($structurePath), true));
    }

    /** Explicit, human-triggered OCR re-extraction — never auto-run. See RunOcrExtraction. */
    public function convertOcr(int $id, Request $request): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canConvertDocument($document)) {
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

        DB::transaction(function () use ($document) {
            $oldStatus = $document->status;
            $document->update(['status' => 'ocr_pending']);

            DocumentStatusHistory::create([
                'document_id' => $document->id,
                'actor_id'    => auth()->id(),
                'from_status' => $oldStatus,
                'to_status'   => 'ocr_pending',
                'note'        => 'OCR re-extraction queued.',
            ]);
        });

        \App\Jobs\RunOcrExtraction::dispatch($document->id, $engine);

        return response()->json(['status' => 'ocr_pending']);
    }

    /** Discard an OCR result and restore the pre-OCR text-layer Markdown saved by RunOcrExtraction. */
    public function revertOcr(int $id): JsonResponse
    {
        $document = Document::findOrFail($id);

        if (! $this->canConvertDocument($document)) {
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

        $queuedElsewhere = false;
        if (in_array($document->status, ['processing', 'ocr_pending'], true)) {
            // Single serial queue worker (see CLAUDE.md) — while this document's job sits in
            // the `jobs` table waiting its turn, another document's job may currently be
            // `reserved_at`'d (actively running). Surface that so the UI can say "waiting in
            // queue" instead of looking like conversion silently stalled.
            //
            // Fixed 2026-07-26 — this used to build a `LIKE '%"documentId";i:{id};%'` pattern
            // and match it against the raw payload in SQL, which produced a false positive
            // (flagged "waiting in queue" even when this document's own reserved job was the
            // only row in the table). Parsing the payload properly in PHP instead of pattern
            // matching serialized bytes in SQL is both more correct and easier to reason about
            // — there's normally at most one reserved row anyway.
            $queuedElsewhere = DB::table('jobs')->whereNotNull('reserved_at')->pluck('payload')
                ->contains(function (string $payload) use ($document) {
                    $command = json_decode($payload, true)['data']['command'] ?? '';
                    preg_match('/"documentId";i:(\d+);/', $command, $m);

                    return isset($m[1]) && (int) $m[1] !== $document->id;
                });
        }

        return response()->json([
            'status'                   => $document->status,
            'queued_behind_other_job'  => $queuedElsewhere,
            'extraction_method'       => $document->metadata['extraction_method'] ?? null,
            'ocr_engine'              => $document->metadata['ocr_engine'] ?? null,
            'needs_ocr_review'        => (bool) ($document->metadata['needs_ocr_review'] ?? false),
            'has_markdown'            => (bool) ($document->markdown_path && Storage::disk('public')->exists($document->markdown_path)),
            'structure_analyzed'      => (bool) ($document->metadata['structure_analyzed'] ?? false),
            'structure_engine'        => $document->metadata['structure_engine'] ?? null,
            'structure_headings_count' => $document->metadata['structure_headings_count'] ?? null,
            'structure_tables_count'  => $document->metadata['structure_tables_count'] ?? null,
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

        if (! $this->canConvertDocument($document)) {
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

                // Structure map is scoped to the draft it was produced alongside — discard it
                // the same way the Markdown itself is discarded, not kept around indefinitely.
                $structurePath = preg_replace('/\.pdf$/i', '.structure.json', $document->original_pdf_path);
                if ($structurePath && Storage::disk('public')->exists($structurePath)) {
                    Storage::disk('public')->delete($structurePath);
                }

                $metadata = $document->metadata ?? [];
                unset(
                    $metadata['extraction_method'], $metadata['needs_ocr_review'], $metadata['manually_edited'],
                    $metadata['structure_analyzed'], $metadata['structure_engine'],
                    $metadata['structure_headings_count'], $metadata['structure_tables_count'],
                );

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

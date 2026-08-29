<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ManagesDocumentFiles;
use App\Http\Requests\StoreFolderRequest;
use App\Http\Requests\UpdateFolderRequest;
use App\Models\Department;
use App\Models\Division;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\Folder;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class FolderController extends Controller
{
    use ManagesDocumentFiles;

    /**
     * Same authorize() logic as Store/UpdateFolderRequest, duplicated here because
     * create/edit/destroy render or mutate state outside a FormRequest. See SECURITY.md H-04.
     */
    private function authorizeManage(Section $section, ?Division $division = null): void
    {
        abort_unless(auth()->user()->canUploadTo($division ?? $section), 403);
    }

    /** Deleting a folder is destructive (subfolders + every document inside go with it) — gated
     *  on the 'folders.delete' privilege, not just upload scope. */
    private function authorizeDelete(Section $section, ?Division $division = null): void
    {
        abort_unless(auth()->user()->canDeleteFolder($division ?? $section), 403);
    }

    // ── Section folders ─────────────────────────────────────────────────────

    public function create(string $level, Department $department, Section $section): View
    {
        $this->authorizeManage($section);

        return view('folders.create', compact('department', 'section'));
    }

    public function store(StoreFolderRequest $request, string $level, Department $department, Section $section): RedirectResponse|JsonResponse
    {
        // Folder-upload (webkitdirectory) posts one file at a time but only needs the folder
        // created once — reuse an existing same-named root folder instead of piling up "Sub-2".
        if ($request->boolean('find_or_create')) {
            $existing = $section->folders()->whereRaw('LOWER(name) = ?', [mb_strtolower($request->validated()['name'])])->first();
            if ($existing) {
                return response()->json(['id' => $existing->id, 'name' => $existing->name, 'slug' => $existing->slug]);
            }
        }

        try {
            $folder = null;

            DB::transaction(function () use ($request, $department, $section, &$folder) {
                $slug = Folder::uniqueSlugForSection($request->validated()['name'], $section->id);

                $folder = $section->folders()->create([
                    ...$request->validated(),
                    'department_id' => $department->id,
                    'section_id'    => $section->id,
                    'slug'          => $slug,
                ]);
            });

            if ($request->wantsJson()) {
                return response()->json(['id' => $folder->id, 'name' => $folder->name, 'slug' => $folder->slug]);
            }

            flash()->success("Folder \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.sections.show', [$department->levelAlias(), $department, $section]);
        } catch (\Throwable $e) {
            Log::error('FolderController@store failed', ['section_id' => $section->id, 'error' => $e->getMessage()]);

            if ($request->wantsJson()) {
                return response()->json(['message' => 'Failed to create folder.'], 500);
            }

            flash()->error('Failed to create folder. Please try again.');
            return back()->withInput();
        }
    }

    public function show(Request $request, string $level, Department $department, Section $section, Folder $folder): View
    {
        if ($folder->visibility === 'authenticated' && ! auth()->check()) {
            abort(403);
        }

        return $this->renderShow($request, $department, $section, null, $folder);
    }

    public function edit(string $level, Department $department, Section $section, Folder $folder): View
    {
        $this->authorizeManage($section);

        return view('folders.edit', compact('department', 'section', 'folder'));
    }

    public function update(UpdateFolderRequest $request, string $level, Department $department, Section $section, Folder $folder): RedirectResponse
    {
        return $this->doUpdate($request, $department, $section, null, $folder);
    }

    public function destroy(string $level, Department $department, Section $section, Folder $folder): RedirectResponse
    {
        $this->authorizeDelete($section);

        return $this->doDestroy($department, $section, null, $folder);
    }

    // ── Section-folder subfolders (one level deep — see folders.parent_id migration) ──────────

    public function createSubfolder(string $level, Department $department, Section $section, Folder $folder): View
    {
        $this->authorizeManage($section);
        abort_if($folder->parent_id !== null, 404);

        return view('folders.create', compact('department', 'section', 'folder'));
    }

    public function storeSubfolder(StoreFolderRequest $request, string $level, Department $department, Section $section, Folder $folder): RedirectResponse|JsonResponse
    {
        abort_if($folder->parent_id !== null, 404);

        // Folder-upload (webkitdirectory) posts one file at a time but only needs the subfolder
        // created once — reuse an existing same-named child instead of piling up "Sub-2", "Sub-3".
        if ($request->boolean('find_or_create')) {
            $existing = $folder->children()->whereRaw('LOWER(name) = ?', [mb_strtolower($request->validated()['name'])])->first();
            if ($existing) {
                return response()->json(['id' => $existing->id, 'name' => $existing->name, 'slug' => $existing->slug]);
            }
        }

        try {
            $child = null;

            DB::transaction(function () use ($request, $department, $section, $folder, &$child) {
                $slug = Folder::uniqueSlugForSection($request->validated()['name'], $section->id);

                $child = $folder->children()->create([
                    ...$request->validated(),
                    'department_id' => $department->id,
                    'section_id'    => $section->id,
                    'division_id'   => $folder->division_id,
                    'slug'          => $slug,
                ]);
            });

            if ($request->wantsJson()) {
                return response()->json(['id' => $child->id, 'name' => $child->name, 'slug' => $child->slug]);
            }

            flash()->success("Subfolder \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $folder]);
        } catch (\Throwable $e) {
            Log::error('FolderController@storeSubfolder failed', ['folder_id' => $folder->id, 'error' => $e->getMessage()]);

            if ($request->wantsJson()) {
                return response()->json(['message' => 'Failed to create subfolder.'], 500);
            }

            flash()->error('Failed to create subfolder. Please try again.');
            return back()->withInput();
        }
    }

    // ── Division folders ────────────────────────────────────────────────────

    public function createForDivision(string $level, Department $department, Section $section, Division $division): View
    {
        $this->authorizeManage($section, $division);

        return view('folders.create', compact('department', 'section', 'division'));
    }

    public function storeForDivision(StoreFolderRequest $request, string $level, Department $department, Section $section, Division $division): RedirectResponse|JsonResponse
    {
        if ($request->boolean('find_or_create')) {
            $existing = $division->folders()->whereRaw('LOWER(name) = ?', [mb_strtolower($request->validated()['name'])])->first();
            if ($existing) {
                return response()->json(['id' => $existing->id, 'name' => $existing->name, 'slug' => $existing->slug]);
            }
        }

        try {
            $folder = null;

            DB::transaction(function () use ($request, $department, $section, $division, &$folder) {
                $slug = Folder::uniqueSlugForDivision($request->validated()['name'], $division->id);

                $folder = $division->folders()->create([
                    ...$request->validated(),
                    'department_id' => $department->id,
                    'section_id'    => $section->id,
                    'slug'          => $slug,
                ]);
            });

            if ($request->wantsJson()) {
                return response()->json(['id' => $folder->id, 'name' => $folder->name, 'slug' => $folder->slug]);
            }

            flash()->success("Folder \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]);
        } catch (\Throwable $e) {
            Log::error('FolderController@storeForDivision failed', ['division_id' => $division->id, 'error' => $e->getMessage()]);

            if ($request->wantsJson()) {
                return response()->json(['message' => 'Failed to create folder.'], 500);
            }

            flash()->error('Failed to create folder. Please try again.');
            return back()->withInput();
        }
    }

    public function showForDivision(Request $request, string $level, Department $department, Section $section, Division $division, Folder $folder): View
    {
        if ($folder->visibility === 'authenticated' && ! auth()->check()) {
            abort(403);
        }

        return $this->renderShow($request, $department, $section, $division, $folder);
    }

    public function editForDivision(string $level, Department $department, Section $section, Division $division, Folder $folder): View
    {
        $this->authorizeManage($section, $division);

        return view('folders.edit', compact('department', 'section', 'division', 'folder'));
    }

    public function updateForDivision(UpdateFolderRequest $request, string $level, Department $department, Section $section, Division $division, Folder $folder): RedirectResponse
    {
        return $this->doUpdate($request, $department, $section, $division, $folder);
    }

    public function destroyForDivision(string $level, Department $department, Section $section, Division $division, Folder $folder): RedirectResponse
    {
        $this->authorizeDelete($section, $division);

        return $this->doDestroy($department, $section, $division, $folder);
    }

    // ── Division-folder subfolders (one level deep) ─────────────────────────

    public function createSubfolderForDivision(string $level, Department $department, Section $section, Division $division, Folder $folder): View
    {
        $this->authorizeManage($section, $division);
        abort_if($folder->parent_id !== null, 404);

        return view('folders.create', compact('department', 'section', 'division', 'folder'));
    }

    public function storeSubfolderForDivision(StoreFolderRequest $request, string $level, Department $department, Section $section, Division $division, Folder $folder): RedirectResponse|JsonResponse
    {
        abort_if($folder->parent_id !== null, 404);

        if ($request->boolean('find_or_create')) {
            $existing = $folder->children()->whereRaw('LOWER(name) = ?', [mb_strtolower($request->validated()['name'])])->first();
            if ($existing) {
                return response()->json(['id' => $existing->id, 'name' => $existing->name, 'slug' => $existing->slug]);
            }
        }

        try {
            $child = null;

            DB::transaction(function () use ($request, $department, $section, $folder, &$child) {
                $slug = Folder::uniqueSlugForDivision($request->validated()['name'], $folder->division_id);

                $child = $folder->children()->create([
                    ...$request->validated(),
                    'department_id' => $department->id,
                    'section_id'    => $section->id,
                    'division_id'   => $folder->division_id,
                    'slug'          => $slug,
                ]);
            });

            if ($request->wantsJson()) {
                return response()->json(['id' => $child->id, 'name' => $child->name, 'slug' => $child->slug]);
            }

            flash()->success("Subfolder \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder]);
        } catch (\Throwable $e) {
            Log::error('FolderController@storeSubfolderForDivision failed', ['folder_id' => $folder->id, 'error' => $e->getMessage()]);

            if ($request->wantsJson()) {
                return response()->json(['message' => 'Failed to create subfolder.'], 500);
            }

            flash()->error('Failed to create subfolder. Please try again.');
            return back()->withInput();
        }
    }

    // ── Shared implementation ───────────────────────────────────────────────

    private function renderShow(Request $request, Department $department, Section $section, ?Division $division, Folder $folder): View
    {
        // Out-of-scope authenticated users aren't blocked outright — they're dropped to the same
        // public-only view a guest gets (see SectionController::show()). Document::isPubliclyVisible()
        // still requires the folder's own visibility to be public too, so this doesn't loosen
        // anything below the document-level check.
        $publicOnly = ! auth()->check() || ! auth()->user()->canView($division ?? $section);

        $sort       = $request->get('sort', 'amendment_number_desc');
        $filterYear = (int) $request->get('year', 0);

        $rootDocuments = $folder->documents()
            ->publishable()
            ->with([
                'user:id,name',
                'amendments' => fn ($q) => $q
                    ->publishable()
                    ->with('user:id,name')
                    ->when($publicOnly, fn ($q) => $q->where('visibility', 'public'))
                    ->orderBy('created_at'),
            ])
            ->whereNull('parent_id')
            ->when($publicOnly, fn ($q) => $q->where('visibility', 'public'))
            ->orderBy('created_at')
            ->get();

        $availableYears = $rootDocuments
            ->flatMap(fn ($root) => $root->amendments)
            ->map(fn ($a) => $a->metadata['effective_year'] ?? null)
            ->filter()->unique()->sort()->values();

        $rootDocuments->each(function ($root) use ($sort, $filterYear) {
            $amendments = $root->amendments;

            if ($filterYear) {
                $amendments = $amendments->filter(
                    fn ($a) => ($a->metadata['effective_year'] ?? null) == $filterYear
                );
            }

            $amendments = match ($sort) {
                'amendment_number_asc' => $amendments->sortBy(fn ($a) => $a->metadata['amendment_number'] ?? PHP_INT_MAX),
                'year_desc'            => $amendments->sortByDesc(fn ($a) => $a->metadata['effective_year'] ?? 0),
                'year_asc'             => $amendments->sortBy(fn ($a) => $a->metadata['effective_year'] ?? PHP_INT_MAX),
                'uploaded_asc'         => $amendments->sortBy('created_at'),
                'uploaded_desc'        => $amendments->sortByDesc('created_at'),
                default                => $amendments->sortByDesc(fn ($a) => $a->metadata['amendment_number'] ?? -PHP_INT_MAX),
            };

            $root->setRelation('amendments', $amendments->values());
        });

        $visibilityScope = fn ($q) => $q->publishable()->when(! auth()->check(), fn ($q2) => $q2->where('visibility', 'public'));

        // Parent options for amendments — root documents within this folder only.
        $parentOptions = auth()->check()
            ? $folder->documents()
                ->select('id', 'title', 'created_at')
                ->whereNull('parent_id')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'date' => $d->created_at->format('d M Y')])
                ->values()
            : collect();

        // Only a root folder has subfolders (one level deep) — a subfolder's own $folder->children
        // is always empty, so this naturally renders nothing on a subfolder's own show page.
        $subfolders = $folder->children()->withCount(['documents' => $visibilityScope])->get();

        // Every document under this folder AND its subfolders — a folder whose own documents
        // all live one level down, in a subfolder, must not read as empty.
        $totalCount = $visibilityScope($folder->documents())->count() + $subfolders->sum('documents_count');

        // Full delete-confirmation impact: same reach as $totalCount, but regardless of
        // publish/visibility status — doDestroy() removes all of them, not just the
        // publicly-listed ones counted above.
        $deleteImpactCount = $folder->documents()->count()
            + $folder->children()->withCount('documents')->get()->sum('documents_count');

        return view('folders.show', compact('department', 'section', 'division', 'folder', 'rootDocuments', 'totalCount', 'parentOptions', 'sort', 'filterYear', 'availableYears', 'subfolders', 'deleteImpactCount'));
    }

    private function doUpdate(UpdateFolderRequest $request, Department $department, Section $section, ?Division $division, Folder $folder): RedirectResponse
    {
        try {
            DB::transaction(fn () => $folder->update($request->validated()));

            flash()->success("Folder \"{$folder->name}\" updated.");
            return $division
                ? redirect()->route('departments.sections.divisions.folders.show', [$department->levelAlias(), $department, $section, $division, $folder])
                : redirect()->route('departments.sections.folders.show', [$department->levelAlias(), $department, $section, $folder]);
        } catch (\Throwable $e) {
            Log::error('FolderController@update failed', ['folder_id' => $folder->id, 'error' => $e->getMessage()]);
            flash()->error('Failed to update folder. Please try again.');
            return back()->withInput();
        }
    }

    /** Soft-deletes every document directly inside $folder, appending each to &$docsToArchive. */
    private function deleteFolderDocuments(Folder $folder, array &$docsToArchive): void
    {
        $folder->documents()->each(function (Document $doc) use (&$docsToArchive) {
            DocumentStatusHistory::create([
                'document_id' => $doc->id,
                'actor_id'    => auth()->id(),
                'from_status' => $doc->status,
                'to_status'   => 'deleted',
                'note'        => 'Deleted with parent folder.',
            ]);
            $doc->delete();
            $docsToArchive[] = $doc;
        });
    }

    private function doDestroy(Department $department, Section $section, ?Division $division, Folder $folder): RedirectResponse
    {
        $docsToArchive = [];

        try {
            DB::transaction(function () use ($folder, &$docsToArchive) {
                $this->deleteFolderDocuments($folder, $docsToArchive);

                // Subfolders aren't covered by a DB cascade (soft deletes don't trigger FK
                // cascades), so walk them explicitly — one level deep, see folders.parent_id.
                $folder->children()->each(function (Folder $child) use (&$docsToArchive) {
                    $this->deleteFolderDocuments($child, $docsToArchive);
                    $child->delete();
                });

                $folder->delete();
            });

            foreach ($docsToArchive as $doc) {
                $this->archiveFiles($doc);
            }

            flash()->success("Folder \"{$folder->name}\" and all its documents deleted.");
            return $division
                ? redirect()->route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division])
                : redirect()->route('departments.sections.show', [$department->levelAlias(), $department, $section]);
        } catch (\Throwable $e) {
            Log::error('FolderController@destroy failed', ['folder_id' => $folder->id, 'error' => $e->getMessage()]);
            flash()->error('Failed to delete folder. Please try again.');
            return back();
        }
    }
}

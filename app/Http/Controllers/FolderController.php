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

    // ── Section folders ─────────────────────────────────────────────────────

    public function create(string $level, Department $department, Section $section): View
    {
        $this->authorizeManage($section);

        return view('folders.create', compact('department', 'section'));
    }

    public function store(StoreFolderRequest $request, string $level, Department $department, Section $section): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $department, $section) {
                $slug = Folder::uniqueSlugForSection($request->validated()['name'], $section->id);

                $section->folders()->create([
                    ...$request->validated(),
                    'department_id' => $department->id,
                    'section_id'    => $section->id,
                    'slug'          => $slug,
                ]);
            });

            flash()->success("Folder \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.sections.show', [$department->levelAlias(), $department, $section]);
        } catch (\Throwable $e) {
            Log::error('FolderController@store failed', ['section_id' => $section->id, 'error' => $e->getMessage()]);
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
        $this->authorizeManage($section);

        return $this->doDestroy($department, $section, null, $folder);
    }

    // ── Division folders ────────────────────────────────────────────────────

    public function createForDivision(string $level, Department $department, Section $section, Division $division): View
    {
        $this->authorizeManage($section, $division);

        return view('folders.create', compact('department', 'section', 'division'));
    }

    public function storeForDivision(StoreFolderRequest $request, string $level, Department $department, Section $section, Division $division): RedirectResponse
    {
        try {
            DB::transaction(function () use ($request, $department, $section, $division) {
                $slug = Folder::uniqueSlugForDivision($request->validated()['name'], $division->id);

                $division->folders()->create([
                    ...$request->validated(),
                    'department_id' => $department->id,
                    'section_id'    => $section->id,
                    'slug'          => $slug,
                ]);
            });

            flash()->success("Folder \"{$request->validated()['name']}\" created.");
            return redirect()->route('departments.sections.divisions.show', [$department->levelAlias(), $department, $section, $division]);
        } catch (\Throwable $e) {
            Log::error('FolderController@storeForDivision failed', ['division_id' => $division->id, 'error' => $e->getMessage()]);
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
        $this->authorizeManage($section, $division);

        return $this->doDestroy($department, $section, $division, $folder);
    }

    // ── Shared implementation ───────────────────────────────────────────────

    private function renderShow(Request $request, Department $department, Section $section, ?Division $division, Folder $folder): View
    {
        $sort       = $request->get('sort', 'amendment_number_desc');
        $filterYear = (int) $request->get('year', 0);

        $rootDocuments = $folder->documents()
            ->publishable()
            ->with([
                'user:id,name',
                'amendments' => fn ($q) => $q
                    ->publishable()
                    ->with('user:id,name')
                    ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
                    ->orderBy('created_at'),
            ])
            ->whereNull('parent_id')
            ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
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

        $totalCount = $folder->documents()
            ->publishable()
            ->when(! auth()->check(), fn ($q) => $q->where('visibility', 'public'))
            ->count();

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

        return view('folders.show', compact('department', 'section', 'division', 'folder', 'rootDocuments', 'totalCount', 'parentOptions', 'sort', 'filterYear', 'availableYears'));
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

    private function doDestroy(Department $department, Section $section, ?Division $division, Folder $folder): RedirectResponse
    {
        $docsToArchive = [];

        try {
            DB::transaction(function () use ($folder, &$docsToArchive) {
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

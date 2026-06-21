<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Document;
use App\Models\DocumentStatusHistory;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function index(): View
    {
        $documents = Document::with(['department', 'section', 'user:id,name'])
            ->where('status', 'verified')
            ->orderByDesc('updated_at')
            ->paginate(30);

        return view('documents.index', compact('documents'));
    }

    public function show(Document $document): View
    {
        $document->load(['department', 'section', 'user:id,name', 'statusHistory.actor:id,name']);
        return view('documents.show', compact('document'));
    }

    public function store(StoreDocumentRequest $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validated();
        $ajax      = $request->expectsJson();

        $section    = Section::with('department')->findOrFail($validated['section_id']);
        $department = $section->department;

        // Build vault directory path from hierarchy (wing is optional)
        $vaultDir = implode('/', array_filter([
            'document_vault',
            $department->level,
            $department->slug,
            $section->wing,
            $section->slug,
        ]));

        // Store file before transaction — file I/O cannot be rolled back
        $uuid    = (string) Str::uuid();
        $pdfPath = $request->file('file')->storeAs("uploads/{$uuid}", 'original.pdf', 'local');

        if (! $pdfPath) {
            flash()->error('File could not be saved. Please try again.');
            return $ajax
                ? response()->json(['message' => 'File could not be saved. Please try again.'], 500)
                : back()->withInput();
        }

        try {
            $document = null;

            DB::transaction(function () use ($validated, $section, $department, $vaultDir, $pdfPath, $request, &$document) {
                // Ensure vault directory exists
                Storage::disk('local')->makeDirectory($vaultDir);

                $document = Document::create([
                    'department_id'     => $department->id,
                    'section_id'        => $section->id,
                    'user_id'           => $request->user()->id,
                    'title'             => $validated['title'],
                    'document_type'     => $validated['document_type'],
                    'original_filename' => $request->file('file')->getClientOriginalName(),
                    'original_pdf_path' => $pdfPath,
                    'vault_path'        => $vaultDir,
                    'status'            => 'uploaded',
                ]);

                DocumentStatusHistory::create([
                    'document_id' => $document->id,
                    'actor_id'    => $request->user()->id,
                    'from_status' => null,
                    'to_status'   => 'uploaded',
                    'note'        => 'Document uploaded.',
                ]);
            });

            flash()->success("\"{$validated['title']}\" uploaded successfully. Queued for extraction.");
            $redirectUrl = route('departments.sections.show', [$department, $section]);

            return $ajax
                ? response()->json(['redirect' => $redirectUrl])
                : redirect($redirectUrl);

        } catch (\Throwable $e) {
            // DB failed — clean up the uploaded file so we don't orphan it
            Storage::disk('local')->deleteDirectory("uploads/{$uuid}");

            Log::error('DocumentController@store failed', [
                'section_id' => $validated['section_id'],
                'error'      => $e->getMessage(),
            ]);

            flash()->error('Upload failed. Please try again.');
            return $ajax
                ? response()->json(['message' => 'Upload failed. Please try again.'], 500)
                : back()->withInput();
        }
    }

    public function create(): View
    {
        return view('documents.create');
    }

    public function edit(Document $document): View
    {
        $document->load(['department', 'section']);
        return view('documents.edit', compact('document'));
    }

    public function update(UpdateDocumentRequest $request, Document $document): RedirectResponse
    {
        // Review/verify workflow implemented in next iteration
        flash()->info('Review workflow coming soon.');
        return redirect()->route('departments.sections.show', [$document->department, $document->section]);
    }

    public function destroy(Document $document): RedirectResponse
    {
        try {
            DB::transaction(fn () => $document->delete());

            flash()->success('Document deleted.');
            return redirect()->route('documents.index');
        } catch (\Throwable $e) {
            Log::error('DocumentController@destroy failed', [
                'document_id' => $document->id,
                'error'       => $e->getMessage(),
            ]);
            flash()->error('Failed to delete document. Please try again.');
            return back();
        }
    }
}

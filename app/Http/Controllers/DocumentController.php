<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\UpdateDocumentRequest;
use App\Models\Department;
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
        $query = Document::with(['department', 'section', 'user:id,name'])
            ->orderByDesc('created_at');

        if (! auth()->check()) {
            $query->where('status', 'verified');
        }

        $byDepartment = $query->get()->groupBy('department_id');

        return view('documents.index', compact('byDepartment'));
    }

    public function show(Department $department, Section $section, Document $document): View
    {
        $document->load(['user:id,name', 'statusHistory.actor:id,name']);
        return view('documents.show', compact('document', 'department', 'section'));
    }

    public function pdf(Department $department, Section $section, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! auth()->check() && $document->status !== 'verified') {
            abort(403);
        }

        if (! $document->original_pdf_path || ! Storage::disk('local')->exists($document->original_pdf_path)) {
            abort(404, 'PDF file not found.');
        }

        $filename = $document->original_filename ?: 'document.pdf';

        return Storage::disk('local')->response(
            $document->original_pdf_path,
            $filename,
            ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $filename . '"']
        );
    }

    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $validated  = $request->validated();
        $section    = Section::with('department')->findOrFail($validated['section_id']);
        $department = $section->department;

        $vaultDir = implode('/', array_filter([
            'document_vault',
            $department->level,
            $department->slug,
            $section->wing,
            $section->slug,
        ]));

        $uuid    = (string) Str::uuid();
        $pdfPath = $request->file('file')->storeAs("uploads/{$uuid}", 'original.pdf', 'local');

        if (! $pdfPath) {
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        try {
            $document = null;

            DB::transaction(function () use ($validated, $section, $department, $vaultDir, $pdfPath, $request, &$document) {
                Storage::disk('local')->makeDirectory($vaultDir);

                $slug = Document::uniqueSlugForSection($validated['title'], $section->id);

                $document = Document::create([
                    'department_id'     => $department->id,
                    'section_id'        => $section->id,
                    'user_id'           => $request->user()->id,
                    'title'             => $validated['title'],
                    'slug'              => $slug,
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

            flash()->success("\"{$validated['title']}\" uploaded successfully.");
            $redirectUrl = route('departments.sections.show', [$department, $section]);

            return response()->json(['redirect' => $redirectUrl]);

        } catch (\Throwable $e) {
            Storage::disk('local')->deleteDirectory("uploads/{$uuid}");

            Log::error('DocumentController@store failed', [
                'section_id' => $validated['section_id'],
                'error'      => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Upload failed. Please try again.'], 500);
        }
    }

    public function edit(Department $department, Section $section, Document $document): View
    {
        return view('documents.edit', compact('document', 'department', 'section'));
    }

    public function update(UpdateDocumentRequest $request, Department $department, Section $section, Document $document): RedirectResponse
    {
        flash()->info('Review workflow coming soon.');
        return redirect()->route('departments.sections.show', [$department, $section]);
    }

    public function destroy(Department $department, Section $section, Document $document): RedirectResponse
    {
        try {
            DB::transaction(fn () => $document->delete());

            flash()->success('Document deleted.');
            return redirect()->route('departments.sections.show', [$department, $section]);
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

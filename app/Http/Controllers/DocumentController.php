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

    public function show(string $level, Department $department, Section $section, Document $document): View
    {
        $document->load(['user:id,name', 'statusHistory.actor:id,name']);
        return view('documents.show', compact('document', 'department', 'section'));
    }

    public function pdf(string $level, Department $department, Section $section, Document $document): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        if (! auth()->check() && $document->status !== 'verified') {
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

        // Slug is generated before the transaction so the filename is known before file I/O
        $slug      = Document::uniqueSlugForSection($validated['title'], $section->id);
        $timestamp = now()->format('YmdHis');
        $pdfName   = "{$slug}_{$timestamp}.pdf";
        $pdfPath   = $request->file('file')->storeAs($vaultDir, $pdfName, 'public');

        if (! $pdfPath) {
            return response()->json(['message' => 'File could not be saved. Please try again.'], 500);
        }

        try {
            $document = null;

            DB::transaction(function () use ($validated, $section, $department, $vaultDir, $pdfPath, $slug, $request, &$document) {
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
            $redirectUrl = route('departments.sections.show', [$department->levelAlias(), $department, $section]);

            return response()->json(['redirect' => $redirectUrl]);

        } catch (\Throwable $e) {
            Storage::disk('public')->delete($pdfPath);

            Log::error('DocumentController@store failed', [
                'section_id' => $validated['section_id'],
                'error'      => $e->getMessage(),
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
        flash()->info('Review workflow coming soon.');
        return redirect()->route('departments.sections.show', [$department->levelAlias(), $department, $section]);
    }

    public function destroy(string $level, Department $department, Section $section, Document $document): RedirectResponse
    {
        try {
            DB::transaction(fn () => $document->delete());

            flash()->success('Document deleted.');
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
}

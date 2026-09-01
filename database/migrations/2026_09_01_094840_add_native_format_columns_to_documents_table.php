<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Word/Excel/PowerPoint (and other MarkItDown-native formats) no longer force a lossy LibreOffice
 * PDF conversion on upload — the original file is kept as-is (native_path) and converted straight
 * to Markdown. original_pdf_path becomes optional: null until the original was already a PDF, or
 * until someone explicitly asks to convert one. See DocumentController::createDocumentFromUpload().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('original_pdf_path')->nullable()->change();
            $table->string('native_path')->nullable()->after('original_pdf_path');
            // The real extension of the uploaded file (docx, xlsx, pdf, jpg, ...) — 'pdf' for
            // every pre-existing row, since every document was PDF-only before this.
            $table->string('original_format')->default('pdf')->after('native_path');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['native_path', 'original_format']);
            $table->string('original_pdf_path')->nullable(false)->change();
        });
    }
};

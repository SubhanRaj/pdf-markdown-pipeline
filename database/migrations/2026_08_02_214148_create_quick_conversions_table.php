<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quick_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('original_filename');
            $table->string('pdf_path');
            $table->string('markdown_path')->nullable();
            $table->string('structure_path')->nullable();
            // uploaded | processing | ocr_pending | review | failed — same vocabulary as
            // Document::STATUSES, minus verified/pending_approval which don't apply here.
            $table->string('status')->default('uploaded');
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_conversions');
    }
};

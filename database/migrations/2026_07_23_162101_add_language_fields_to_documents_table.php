<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->enum('language', ['english', 'hindi'])->default('english')->after('document_type');
            $table->foreignId('sibling_document_id')->nullable()->after('parent_id')
                ->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['sibling_document_id']);
            $table->dropColumn(['language', 'sibling_document_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_status_histories', function (Blueprint $table) {
            // Stores extra context per transition. On to_status='force_deleted':
            // {"letter_path": "archive_letters/...pdf", "reason": "..."}
            $table->json('metadata')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('document_status_histories', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};

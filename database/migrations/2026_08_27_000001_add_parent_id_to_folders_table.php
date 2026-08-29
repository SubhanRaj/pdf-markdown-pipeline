<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            // One level of nesting only — a folder whose own parent_id is already set can't
            // have its own subfolders (enforced in FolderController@createSubfolder/storeSubfolder).
            $table->foreignId('parent_id')->nullable()->after('division_id')
                ->constrained('folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('division_id')->nullable()->after('section_id')
                  ->constrained('divisions')->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table) {
            // Add the new composite unique BEFORE dropping the old one so MariaDB
            // always has an index to satisfy the section_id FK constraint.
            // (section_id, division_id, slug) enforces uniqueness per context:
            //   direct section docs:  (section_id, NULL,        slug)
            //   division docs:        (section_id, division_id, slug)
            $table->unique(['section_id', 'division_id', 'slug'], 'documents_section_division_slug_unique');
            $table->dropUnique('documents_section_id_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_section_division_slug_unique');
            $table->dropConstrainedForeignId('division_id');
            $table->unique(['section_id', 'slug']);
        });
    }
};

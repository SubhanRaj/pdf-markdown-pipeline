<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // Make section_id nullable — rule-amendment documents belong to a rule_set, not a section
            $table->foreignId('section_id')->nullable()->change();
            // Link to the rule set this amendment belongs to (null = standalone section document)
            $table->foreignId('rule_set_id')->nullable()->after('section_id')
                  ->constrained('rule_sets')->nullOnDelete();
            // Slug uniqueness per rule set (mirrors the existing section+slug unique index)
            $table->unique(['rule_set_id', 'slug'], 'documents_rule_set_id_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique('documents_rule_set_id_slug_unique');
            $table->dropConstrainedForeignId('rule_set_id');
            $table->foreignId('section_id')->nullable(false)->change();
        });
    }
};

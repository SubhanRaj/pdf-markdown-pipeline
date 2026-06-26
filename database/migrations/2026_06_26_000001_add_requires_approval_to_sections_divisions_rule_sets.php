<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('slug');
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('slug');
        });

        Schema::table('rule_sets', function (Blueprint $table) {
            $table->boolean('requires_approval')->default(false)->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });

        Schema::table('divisions', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });

        Schema::table('rule_sets', function (Blueprint $table) {
            $table->dropColumn('requires_approval');
        });
    }
};

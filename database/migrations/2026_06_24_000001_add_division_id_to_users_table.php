<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// division_id was added directly to the base create_users_table migration.
// This migration is kept for historical continuity but is a safe no-op on fresh runs.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'division_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('division_id')->nullable()->index()->after('section_id');
            });
        }
    }

    public function down(): void
    {
        // Column is owned by the base migration; do not drop here.
    }
};

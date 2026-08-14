<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * 'both' becomes a real, independently-stored language value — a bilingual policy
     * document is a third document in its own right, coexisting alongside separate
     * English-only and Hindi-only uploads for the same policy period (not, as before,
     * an upload-time instruction to duplicate the file into an english + hindi pair).
     */
    public function up(): void
    {
        // MySQL-only: SQLite (the test suite's driver) has no native ENUM type — the
        // `language` column there is already an unconstrained TEXT column that accepts
        // 'both' with no migration needed, so skip it there rather than break the whole
        // migration chain (and every RefreshDatabase-backed test) on an incompatible ALTER.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE documents MODIFY language ENUM('english', 'hindi', 'both') NOT NULL DEFAULT 'english'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("UPDATE documents SET language = 'english' WHERE language = 'both'");
            DB::statement("ALTER TABLE documents MODIFY language ENUM('english', 'hindi') NOT NULL DEFAULT 'english'");
        }
    }
};

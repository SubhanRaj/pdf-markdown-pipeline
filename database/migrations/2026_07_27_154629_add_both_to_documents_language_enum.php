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
        // MySQL/MariaDB-only syntax — sqlite (used by the test suite) has no ENUM/MODIFY and
        // already accepts any string in this column, so 'both' just works there with no DDL.
        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE documents MODIFY language ENUM('english', 'hindi', 'both') NOT NULL DEFAULT 'english'");
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE documents SET language = 'english' WHERE language = 'both'");

        if (DB::connection()->getDriverName() !== 'sqlite') {
            DB::statement("ALTER TABLE documents MODIFY language ENUM('english', 'hindi') NOT NULL DEFAULT 'english'");
        }
    }
};

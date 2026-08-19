<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Splits the old god-mode 'admin' into two tiers: 'system_admin' (true site/technical
     * bypass, IT/dev only) and 'admin' (org-scoped officer — full document authority within
     * their own department/section, no site console). See claude.md's "users" schema section
     * and DESIGNATIONS_PLAN.md for why role=admin kept getting reused as a scope workaround.
     */
    public function up(): void
    {
        // MySQL/MariaDB-only: SQLite (the test suite's driver) has no native ENUM type — the
        // `role` column there is already an unconstrained TEXT column that accepts
        // 'system_admin' with no migration needed, same reasoning as the documents.language
        // enum widening. This app's own connection is 'mariadb' (config/database.php,
        // DB_CONNECTION=mariadb) — Laravel 13's native MariaDB driver reports
        // DB::getDriverName() as 'mariadb', not 'mysql', so both must be checked.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE users MODIFY role ENUM('system_admin', 'admin', 'operator', 'viewer') NOT NULL DEFAULT 'viewer'");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("UPDATE users SET role = 'admin' WHERE role = 'system_admin'");
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin', 'operator', 'viewer') NOT NULL DEFAULT 'viewer'");
        }
    }
};

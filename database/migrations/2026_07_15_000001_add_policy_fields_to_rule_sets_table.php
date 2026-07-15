<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->enum('kind', ['rules', 'policy'])->default('rules')->after('slug');
            $table->string('state')->nullable()->after('kind');
            $table->string('policy_type')->nullable()->after('state');
            $table->date('effective_start_date')->nullable()->after('policy_type');
            $table->date('effective_end_date')->nullable()->after('effective_start_date');
            $table->enum('policy_status', ['current', 'superseded'])->default('current')->after('effective_end_date');
            $table->foreignId('previous_policy_id')->nullable()->after('policy_status')
                ->constrained('rule_sets')->nullOnDelete();

            $table->index(['department_id', 'kind', 'state', 'policy_type', 'policy_status'], 'rule_sets_policy_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->dropForeign(['previous_policy_id']);
            $table->dropIndex('rule_sets_policy_lookup_idx');
            $table->dropColumn(['kind', 'state', 'policy_type', 'effective_start_date', 'effective_end_date', 'policy_status', 'previous_policy_id']);
        });
    }
};

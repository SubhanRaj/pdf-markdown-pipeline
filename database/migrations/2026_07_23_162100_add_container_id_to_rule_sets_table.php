<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Splits today's single kind=policy RuleSet row (which doubles as both "the state's
     * policy container" and "its one-and-only period") into a real two-level tree using
     * one more self-referencing FK: container_id = null means the row IS a container
     * (state + policy_type, created once); container_id = <id> means it's a period
     * underneath that container. Documents keep pointing at rule_set_id unchanged —
     * a period is still a plain RuleSet row, so no document ever needs to move.
     */
    public function up(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->foreignId('container_id')->nullable()->after('previous_policy_id')
                ->constrained('rule_sets')->restrictOnDelete();
        });

        // Backfill: for every existing (department_id, state, policy_type) group of
        // kind=policy rows, create one container row above it and point the existing
        // row(s) at it as their first period.
        $rows = DB::table('rule_sets')->where('kind', 'policy')->whereNull('container_id')->get();

        foreach ($rows->groupBy(fn ($r) => "{$r->department_id}|{$r->state}|{$r->policy_type}") as $group) {
            $first = $group->first();

            $slug = $first->slug;
            $base = $slug;
            $i = 2;
            while (DB::table('rule_sets')->where('department_id', $first->department_id)->where('slug', $slug)->exists()) {
                $slug = "{$base}-{$i}";
                $i++;
            }

            $containerId = DB::table('rule_sets')->insertGetId([
                'department_id'      => $first->department_id,
                'name'               => $first->name,
                'slug'               => $slug,
                'description'        => $first->description,
                'kind'               => 'policy',
                'state'              => $first->state,
                'policy_type'        => $first->policy_type,
                'requires_approval'  => $first->requires_approval,
                'policy_status'      => 'current',
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            DB::table('rule_sets')->whereIn('id', $group->pluck('id'))
                ->update(['container_id' => $containerId]);
        }
    }

    /**
     * One-way-safe only pre-launch: does not delete the backfilled container rows
     * (they aren't tagged), so rolling back after real periods/documents have
     * accumulated under a container leaves those container rows behind as harmless
     * orphaned kind=policy rows.
     */
    public function down(): void
    {
        Schema::table('rule_sets', function (Blueprint $table) {
            $table->dropForeign(['container_id']);
            $table->dropColumn('container_id');
        });
    }
};

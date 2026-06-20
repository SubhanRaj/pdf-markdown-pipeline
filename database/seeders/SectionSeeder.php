<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Section;
use Illuminate\Database\Seeder;

class SectionSeeder extends Seeder
{
    public function run(): void
    {
        // ── Excise Department HQ sections ─────────────────────────────────────
        $exciseDept = Department::where('slug', 'excise')
            ->where('level', 'department_level')
            ->first();

        if ($exciseDept) {
            $hqSections = [
                ['name' => 'Establishment Section',       'slug' => 'establishment_section'],
                ['name' => 'Accounts Section',            'slug' => 'accounts_section'],
                ['name' => 'Audit Section',               'slug' => 'audit_section'],
                ['name' => 'Statistics Section',          'slug' => 'statistics_section'],
                ['name' => 'License Section',             'slug' => 'license_section'],
                ['name' => 'Technical Section',           'slug' => 'technical_section'],
                ['name' => 'Molasses Section',            'slug' => 'molasses_section'],
                ['name' => 'Alcohol Section',             'slug' => 'alcohol_section'],
                ['name' => 'Excise Intelligence Bureau',  'slug' => 'excise_intelligence_bureau'],
                ['name' => 'Legal Section',               'slug' => 'legal_section'],
                ['name' => 'Task Force',                  'slug' => 'task_force'],
            ];

            foreach ($hqSections as $sec) {
                Section::firstOrCreate(
                    ['department_id' => $exciseDept->id, 'slug' => $sec['slug'], 'wing' => 'headquarter'],
                    ['name' => $sec['name']]
                );
            }
        }

        // ── Excise Secretariat wings ──────────────────────────────────────────
        $exciseSecretariat = Department::where('slug', 'excise')
            ->where('level', 'secretariat_level')
            ->first();

        if ($exciseSecretariat) {
            $wingSections = [
                ['name' => 'Joint Secretary Wing',  'slug' => 'joint_secretary_wing',  'wing' => 'joint_secretary_wing'],
                ['name' => 'Deputy Secretary Wing', 'slug' => 'deputy_secretary_wing', 'wing' => 'deputy_secretary_wing'],
            ];

            foreach ($wingSections as $sec) {
                Section::firstOrCreate(
                    ['department_id' => $exciseSecretariat->id, 'slug' => $sec['slug'], 'wing' => $sec['wing']],
                    ['name' => $sec['name']]
                );
            }
        }
    }
}

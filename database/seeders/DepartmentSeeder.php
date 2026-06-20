<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            // ── Department Level ──────────────────────────────────────────────
            [
                'name'  => 'Excise Department',
                'slug'  => 'excise',
                'level' => 'department_level',
            ],
            [
                'name'  => 'Sugarcane & Sugar Industries',
                'slug'  => 'sugarcane_sugar',
                'level' => 'department_level',
            ],
            [
                'name'  => 'Sugar Mill Corporation',
                'slug'  => 'sugar_mill_corp',
                'level' => 'department_level',
            ],
            [
                'name'  => 'Cane Federation',
                'slug'  => 'cane_federation',
                'level' => 'department_level',
            ],

            // ── Secretariat Level ─────────────────────────────────────────────
            [
                'name'  => 'Excise Secretariat',
                'slug'  => 'excise',
                'level' => 'secretariat_level',
            ],
            [
                'name'  => 'Sugarcane Secretariat',
                'slug'  => 'sugarcane',
                'level' => 'secretariat_level',
            ],
        ];

        foreach ($departments as $dept) {
            Department::firstOrCreate(
                ['slug' => $dept['slug'], 'level' => $dept['level']],
                ['name' => $dept['name']]
            );
        }
    }
}

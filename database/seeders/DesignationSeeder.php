<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Designation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DesignationSeeder extends Seeder
{
    public function run(): void
    {
        $generic = [
            ['name' => 'Officer',                    'default_scope' => 'none',       'default_privileges' => []],
            ['name' => 'Section Officer',             'default_scope' => 'section',    'default_privileges' => ['section.head', 'documents.upload', 'documents.edit']],
            ['name' => 'HoD',                         'default_scope' => 'department', 'default_privileges' => ['department.head', 'documents.verify', 'documents.approve']],
            ['name' => 'Chief Secretary',             'default_scope' => 'global',     'default_privileges' => ['organization.head', 'documents.verify', 'documents.approve']],
            ['name' => 'Additional Chief Secretary',  'default_scope' => 'global',     'default_privileges' => ['organization.head', 'documents.verify', 'documents.approve']],
            ['name' => 'Principal Secretary',         'default_scope' => 'department', 'default_privileges' => ['department.head', 'documents.verify', 'documents.approve']],
            ['name' => 'Secretary',                   'default_scope' => 'department', 'default_privileges' => ['department.head', 'documents.verify', 'documents.approve']],
            ['name' => 'Special Secretary',           'default_scope' => 'department', 'default_privileges' => ['department.head', 'documents.verify']],
            ['name' => 'Deputy Secretary',             'default_scope' => 'department', 'default_privileges' => ['documents.verify']],
            ['name' => 'Joint Secretary',             'default_scope' => 'department', 'default_privileges' => ['documents.verify']],
            ['name' => 'Review Officer',              'default_scope' => 'section',    'default_privileges' => ['documents.edit']],
            ['name' => 'Additional Review Officer',   'default_scope' => 'section',    'default_privileges' => ['documents.edit']],
            ['name' => 'Clerk',                       'default_scope' => 'none',       'default_privileges' => ['documents.upload']],
            ['name' => 'Finance Controller',          'default_scope' => 'section',    'default_privileges' => ['section.head', 'documents.verify']],
            ['name' => 'Auditor',                     'default_scope' => 'none',       'default_privileges' => []],
            ['name' => 'Accounting Officer',          'default_scope' => 'section',    'default_privileges' => ['documents.verify']],
        ];

        foreach ($generic as $i => $designation) {
            $this->upsert(null, $designation, $i);
        }

        $departmentSpecific = [
            'excise' => [
                ['name' => 'Excise Commissioner',            'default_scope' => 'department', 'default_privileges' => ['department.head', 'documents.verify', 'documents.approve']],
                ['name' => 'Additional Excise Commissioner', 'default_scope' => 'department', 'default_privileges' => ['department.head', 'documents.verify', 'documents.approve']],
                ['name' => 'Deputy Commissioner (P&E)',      'default_scope' => 'section',    'default_privileges' => ['section.head', 'documents.verify']],
                ['name' => 'Deputy Excise Commissioner (P)', 'default_scope' => 'section',    'default_privileges' => ['section.head', 'documents.verify']],
            ],
            'sugarcane_sugar' => [
                ['name' => 'Cane Commissioner',            'default_scope' => 'department', 'default_privileges' => ['department.head', 'documents.verify', 'documents.approve']],
                ['name' => 'Additional Cane Commissioner', 'default_scope' => 'department', 'default_privileges' => ['department.head', 'documents.verify', 'documents.approve']],
            ],
        ];

        foreach ($departmentSpecific as $deptSlug => $designations) {
            $department = Department::where('slug', $deptSlug)->where('level', 'department_level')->first();
            if (! $department) {
                continue;
            }

            foreach ($designations as $i => $designation) {
                $this->upsert($department->id, $designation, $i);
            }
        }
    }

    private function upsert(?int $departmentId, array $designation, int $sortOrder): void
    {
        $slug = Str::slug($designation['name'], '_');

        Designation::firstOrCreate(
            ['department_id' => $departmentId, 'slug' => $slug],
            [
                'name'                => $designation['name'],
                'default_scope'       => $designation['default_scope'],
                'default_privileges'  => $designation['default_privileges'],
                'sort_order'          => $sortOrder,
            ]
        );
    }
}

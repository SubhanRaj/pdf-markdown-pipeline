<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $users = [
            // ── Admin ────────────────────────────────────────────────────────
            [
                'email'    => 'shubhanraj2002@gmail.com',
                'defaults' => [
                    'name'              => 'Subhan Raj',
                    'username'          => 'subhan_raj',
                    'mobile'            => null,
                    'password'          => Hash::make('Admin@1234'),
                    'post'              => 'Lead Engineer',
                    'role'              => 'admin',
                    'privileges'        => ['*'],
                    'email_verified_at' => now(),
                ],
            ],

            // ── Demo Admin ───────────────────────────────────────────────────
            [
                'email'    => 'admin.demo@excise.up.gov.in',
                'defaults' => [
                    'name'              => 'Rajesh Kumar Verma',
                    'username'          => 'rajesh_verma',
                    'mobile'            => '9876543210',
                    'landline'          => '0522-223456',
                    'password'          => Hash::make('Admin@1234'),
                    'post'              => 'Deputy Commissioner (IT)',
                    'role'              => 'admin',
                    'privileges'        => ['*'],
                    'email_verified_at' => now(),
                ],
            ],

            // ── Full-privilege Operator ───────────────────────────────────────
            // Can upload, edit, review, verify, delete, restore — everything
            // except user management (that's admin-only via IsAdmin middleware).
            [
                'email'    => 'operator.full@excise.up.gov.in',
                'defaults' => [
                    'name'              => 'Priya Srivastava',
                    'username'          => 'priya_srivastava',
                    'mobile'            => '9812345678',
                    'landline'          => '0522-2614001',
                    'password'          => Hash::make('Operator@1234'),
                    'post'              => 'Section Officer',
                    'role'              => 'operator',
                    'privileges'        => [
                        'documents.upload',
                        'documents.edit',
                        'documents.delete',
                        'documents.restore',
                        'documents.verify',
                    ],
                    'email_verified_at' => now(),
                ],
            ],

            // ── Upload-only Operator ──────────────────────────────────────────
            // Clerk who can only upload documents — cannot edit, delete, or verify.
            [
                'email'    => 'operator.upload@excise.up.gov.in',
                'defaults' => [
                    'name'              => 'Amit Yadav',
                    'username'          => 'amit_yadav',
                    'mobile'            => '9845612300',
                    'password'          => Hash::make('Operator@1234'),
                    'post'              => 'Junior Clerk',
                    'role'              => 'operator',
                    'privileges'        => ['documents.upload'],
                    'email_verified_at' => now(),
                ],
            ],

            // ── Review/Verify Operator (no upload, no delete) ─────────────────
            // QA reviewer role — reviews and verifies documents uploaded by clerks.
            [
                'email'    => 'operator.review@excise.up.gov.in',
                'defaults' => [
                    'name'              => 'Sunita Mishra',
                    'username'          => 'sunita_mishra',
                    'mobile'            => null,
                    'password'          => Hash::make('Operator@1234'),
                    'post'              => 'Assistant Commissioner',
                    'role'              => 'operator',
                    'privileges'        => [
                        'documents.edit',
                        'documents.verify',
                    ],
                    'email_verified_at' => now(),
                ],
            ],

            // ── Viewer ────────────────────────────────────────────────────────
            // Read-only authenticated access — can view authenticated-visibility
            // documents but cannot mutate anything.
            [
                'email'    => 'viewer@excise.up.gov.in',
                'defaults' => [
                    'name'              => 'Deepak Sharma',
                    'username'          => 'deepak_sharma',
                    'mobile'            => '9011223344',
                    'password'          => Hash::make('Viewer@1234'),
                    'post'              => 'Data Entry Operator',
                    'role'              => 'viewer',
                    'privileges'        => [],
                    'email_verified_at' => now(),
                ],
            ],
        ];

        foreach ($users as $entry) {
            User::firstOrCreate(['email' => $entry['email']], $entry['defaults']);
        }
    }
}

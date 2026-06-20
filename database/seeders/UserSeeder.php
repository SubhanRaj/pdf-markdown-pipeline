<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // firstOrCreate on email — idempotent, safe to re-run without resetting password
        User::firstOrCreate(
            ['email' => 'shubhanraj2002@gmail.com'],
            [
                'name'              => 'Subhan Raj',
                'username'          => 'subhan_raj',
                'mobile'            => null,
                'password'          => Hash::make('Admin@1234'),
                'post'              => 'Lead Engineer',
                'role'              => 'admin',
                'privileges'        => ['*'],   // wildcard — all privileges
                'email_verified_at' => now(),
            ]
        );
    }
}

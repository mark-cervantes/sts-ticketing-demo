<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed 5 users including a predictable demo user for dev login.
     *
     * Uses firstOrCreate for idempotency.
     */
    public function run(): void
    {
        // Demo user — predictable credentials for dev/staging login convenience.
        User::firstOrCreate(
            ['email' => 'demo@example.com'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );

        // 4 additional realistic users.
        $additionalUsers = [
            ['name' => 'Alice Johnson', 'email' => 'alice@example.com'],
            ['name' => 'Bob Martinez', 'email' => 'bob@example.com'],
            ['name' => 'Carol Chen', 'email' => 'carol@example.com'],
            ['name' => 'David Kim', 'email' => 'david@example.com'],
        ];

        foreach ($additionalUsers as $userData) {
            User::firstOrCreate(
                ['email' => $userData['email']],
                [
                    'name' => $userData['name'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ]
            );
        }

        $this->command->info('UserSeeder: seeded '.User::count().' users total.');
    }
}

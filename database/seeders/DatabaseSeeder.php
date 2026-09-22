<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the central application's database.
     */
    public function run(): void
    {
        // 👑 GLOBAL PLATFORM SUPER ADMIN
        \App\Models\User::updateOrCreate(
            ['email' => 'admin@platform.test'],
            [
                'name' => 'Platform Super Admin',
                'password' => \Illuminate\Support\Facades\Hash::make('12345678'),
                'is_super_admin' => true,
            ]
        );
    }
}

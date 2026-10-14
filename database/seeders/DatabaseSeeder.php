<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the central application's database.
     */
    public function run(): void
    {
        // 👑 GLOBAL PLATFORM SUPER ADMIN
        User::updateOrCreate(
            ['email' => 'admin@platform.test'],
            [
                'name' => 'Platform Super Admin',
                'password' => Hash::make('12345678'),
                'is_super_admin' => true,
            ]
        );

        $this->call(UserTenantSeeder::class);
    }
}

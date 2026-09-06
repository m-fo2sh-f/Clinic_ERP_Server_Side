<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Seed Multi-Tenant Users, Roles & Dedicated Branches
        $this->call(UserTenantSeeder::class);

        // 2. Seed Medical Data (Drugs, Patients, Appointments, Queues, Prescriptions)
        $this->call(MedicalDataSeeder::class);
    }
}

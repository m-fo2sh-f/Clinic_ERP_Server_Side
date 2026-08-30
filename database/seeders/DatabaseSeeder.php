<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $defaultPassword = Hash::make('12345678');

        // ==========================================
        // 1. TENANT 1: عيادة د. علي المتخصصة (clinic1.my-saas.test)
        // ==========================================
        $tenant1 = Tenant::firstOrCreate(['id' => 'tenant-1']);
        $tenant1->domains()->firstOrCreate(['domain' => 'clinic1.my-saas.test']);

        $tenant1->run(function () use ($defaultPassword) {
            // Roles initialization
            $clinicOwnerRole = Role::firstOrCreate(['name' => 'clinic_owner']);
            $doctorRole      = Role::firstOrCreate(['name' => 'doctor']);
            $receptionRole   = Role::firstOrCreate(['name' => 'receptionist']);

            // Branches creation
            $maadiBranch   = Branch::firstOrCreate(['name' => 'فرع المعادي']);
            $tagamoaBranch = Branch::firstOrCreate(['name' => 'فرع التجمع']);

            // Dr. Ali (Clinic Owner & Primary Doctor - Assigned to Both Branches)
            $drAli = User::firstOrCreate(
                ['email' => 'dr.ali@clinic1.com'],
                [
                    'name' => 'Dr. Ali',
                    'password' => $defaultPassword,
                ]
            );
            $drAli->syncRoles([$clinicOwnerRole, $doctorRole]);
            $drAli->branches()->syncWithoutDetaching([$maadiBranch->id, $tagamoaBranch->id]);

            // Nada (Receptionist - Dedicated strictly to فرع المعادي)
            $nada = User::firstOrCreate(
                ['email' => 'nada@clinic1.com'],
                [
                    'name' => 'Nada',
                    'password' => $defaultPassword,
                ]
            );
            $nada->syncRoles([$receptionRole]);
            $nada->branches()->syncWithoutDetaching([$maadiBranch->id]);

            // Mona (Receptionist - Dedicated strictly to فرع التجمع)
            $mona = User::firstOrCreate(
                ['email' => 'mona@clinic1.com'],
                [
                    'name' => 'Mona',
                    'password' => $defaultPassword,
                ]
            );
            $mona->syncRoles([$receptionRole]);
            $mona->branches()->syncWithoutDetaching([$tagamoaBranch->id]);
        });

        // ==========================================
        // 2. TENANT 2: مجمع عيادات الشفاء الطبية (clinic2.my-saas.test)
        // ==========================================
        $tenant2 = Tenant::firstOrCreate(['id' => 'tenant-2']);
        $tenant2->domains()->firstOrCreate(['domain' => 'clinic2.my-saas.test']);

        $tenant2->run(function () use ($defaultPassword) {
            // Roles initialization
            $clinicOwnerRole = Role::firstOrCreate(['name' => 'clinic_owner']);
            $doctorRole      = Role::firstOrCreate(['name' => 'doctor']);
            $receptionRole   = Role::firstOrCreate(['name' => 'receptionist']);

            // Branches creation
            $nasrCity    = Branch::firstOrCreate(['name' => 'فرع مدينة نصر']);
            $heliopolis  = Branch::firstOrCreate(['name' => 'فرع مصر الجديدة']);
            $sheikhZayed = Branch::firstOrCreate(['name' => 'فرع الشيخ زايد']);

            // Dr. Hassan (Doctor - Assigned to فرع مدينة نصر AND فرع مصر الجديدة)
            $drHassan = User::firstOrCreate(
                ['email' => 'dr.hassan@clinic2.com'],
                [
                    'name' => 'Dr. Hassan',
                    'password' => $defaultPassword,
                ]
            );
            $drHassan->syncRoles([$doctorRole]);
            $drHassan->branches()->syncWithoutDetaching([$nasrCity->id, $heliopolis->id]);

            // Dr. Youssef (Doctor - Dedicated strictly to فرع الشيخ زايد)
            $drYoussef = User::firstOrCreate(
                ['email' => 'dr.youssef@clinic2.com'],
                [
                    'name' => 'Dr. Youssef',
                    'password' => $defaultPassword,
                ]
            );
            $drYoussef->syncRoles([$doctorRole]);
            $drYoussef->branches()->syncWithoutDetaching([$sheikhZayed->id]);

            // Sarah (Shared Receptionist - Assigned to All 3 Branches)
            $sarah = User::firstOrCreate(
                ['email' => 'sarah@clinic2.com'],
                [
                    'name' => 'Sarah',
                    'password' => $defaultPassword,
                ]
            );
            $sarah->syncRoles([$receptionRole]);
            $sarah->branches()->syncWithoutDetaching([$nasrCity->id, $heliopolis->id, $sheikhZayed->id]);
        });

        // 3. Seed Multi-Tenant Users & Dedicated Branches
        $this->call(UserTenantSeeder::class);

        // 4. Seed Medical Data (Drugs, Patients, Appointments, Queues, Prescriptions)
        $this->call(MedicalDataSeeder::class);
    }
}

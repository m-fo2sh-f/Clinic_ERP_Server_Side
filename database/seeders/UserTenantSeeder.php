<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Stancl\Tenancy\Database\Models\Domain;

class UserTenantSeeder extends Seeder
{
    /**
     * Run the multi-tenant user and branch database seeds.
     */
    public function run(): void
    {
        $universalPasswordPlain = '12345678';
        $universalPasswordHash = Hash::make($universalPasswordPlain);

        // =========================================================================
        // 👑 0. GLOBAL PLATFORM SUPER ADMIN (Central Database)
        // =========================================================================
        $superAdmin = User::updateOrCreate(
            ['email' => 'admin@platform.test'],
            [
                'name'           => 'Platform Super Admin',
                'tenant_id'      => null,
                'password'       => $universalPasswordHash,
                'is_super_admin' => true,
            ]
        );
        $superAdmin->is_super_admin = true;
        $superAdmin->save();

        $assignDomain = function (Tenant $tenant, string $domainName) {
            $existing = Domain::where('domain', $domainName)->first();
            if ($existing) {
                if ($existing->tenant_id !== $tenant->id) {
                    $existing->tenant_id = $tenant->id;
                    $existing->save();
                }
            } else {
                $tenant->domains()->create(['domain' => $domainName]);
            }
        };

        // =========================================================================
        // 🏥 1. TENANT 1: Clinic A (Polyclinic Multi-Branch)
        // - Mode: polyclinic
        // - 2 Branches: Branch 1 (المعادي) & Branch 2 (مدينة نصر)
        // - 1 Doctor assigned to both branches, 2 Receptionists (one per branch)
        // =========================================================================
        $tenant1 = Tenant::firstOrCreate(
            ['id' => 'tenant-1'],
            [
                'clinic_name'    => 'مجموعة عيادات النور (Clinic A - Polyclinic)',
                'owner_email'    => 'dr.ahmed@clinica.test',
                'is_active'      => true,
                'admin_name'     => 'د. أحمد علي (Dr. Ahmed Ali)',
                'admin_password' => $universalPasswordPlain,
            ]
        );
        $tenant1->is_active = true;
        $tenant1->save();
        $assignDomain($tenant1, 'clinic1.my-saas.test');
        $assignDomain($tenant1, 'al-noor.my-saas.test');
        $assignDomain($tenant1, 'clinic1.localhost');

        $tenant1->run(function () {
            (new TenantDatabaseSeeder())->run();
        });

        // =========================================================================
        // 🏥 2. TENANT 2: Clinic B (Dual Doctor Shared Reception)
        // - Mode: polyclinic
        // - 2 Branches: East Wing (الجناح الشرقي) & West Wing (الجناح الغربي)
        // - 2 Doctors (strictly separated per wing), 1 Shared Receptionist
        // =========================================================================
        $tenant2 = Tenant::firstOrCreate(
            ['id' => 'tenant-2'],
            [
                'clinic_name'    => 'مستشفى الأمل (Clinic B - Dual Doctor)',
                'owner_email'    => 'dr.tarek@clinicb.test',
                'is_active'      => true,
                'admin_name'     => 'د. طارق خليل (Dr. Tarek)',
                'admin_password' => $universalPasswordPlain,
            ]
        );
        $tenant2->is_active = true;
        $tenant2->save();
        $assignDomain($tenant2, 'clinic2.my-saas.test');
        $assignDomain($tenant2, 'al-amal.my-saas.test');
        $assignDomain($tenant2, 'clinic2.localhost');

        $tenant2->run(function () {
            (new TenantDatabaseSeeder())->run();
        });

        // =========================================================================
        // 🏥 3. TENANT 3: Clinic C (Solo Doctor Walk-In)
        // - Mode: solo
        // - 1 Single Branch: Main Branch (فرع العيادة الرئيسي)
        // - 1 Doctor only, dynamic vitals, instant checkout, no reception/cashier
        // =========================================================================
        $tenant3 = Tenant::firstOrCreate(
            ['id' => 'tenant-3'],
            [
                'clinic_name'    => 'عيادة د. شريف (Clinic C - Solo Doctor)',
                'owner_email'    => 'dr.sherif@solo.test',
                'is_active'      => true,
                'admin_name'     => 'د. شريف عبد المنعم (Dr. Sherif)',
                'admin_password' => $universalPasswordPlain,
            ]
        );
        $tenant3->is_active = true;
        $tenant3->save();
        $assignDomain($tenant3, 'clinic3.my-saas.test');
        $assignDomain($tenant3, 'solo.my-saas.test');
        $assignDomain($tenant3, 'clinic3.localhost');
        $assignDomain($tenant3, 'solo.localhost');

        $tenant3->run(function () {
            (new TenantDatabaseSeeder())->run();
        });

        // =========================================================================
        // 📊 PRINT CONSOLE SUMMARY
        // =========================================================================
        if ($this->command) {
            $this->command->newLine();
            $this->command->info('================================================================================');
            $this->command->info(' 🚀 3 DISTINCT CLINIC ENVIRONMENTS SEEDED SUCCESSFULLY (PASSWORD: 12345678)');
            $this->command->info('================================================================================');

            $this->command->newLine();
            $this->command->warn('👑 [PLATFORM CENTRAL ADMIN]');
            $this->command->table(
                ['Role', 'Email', 'Password', 'Domain'],
                [
                    ['Super Admin', 'admin@platform.test', '12345678', 'localhost:5173 / platform.my-saas.test'],
                ]
            );

            $this->command->newLine();
            $this->command->warn('🏥 [CLINIC A (tenant-1) — Polyclinic Multi-Branch]');
            $this->command->table(
                ['Name', 'Email', 'Password', 'Roles', 'Assigned Branches'],
                [
                    ['د. أحمد علي (Dr. Ahmed)', 'dr.ahmed@clinica.test', '12345678', 'clinic_owner, doctor', 'Branch 1 + Branch 2'],
                    ['سارة (Receptionist Branch 1)', 'reception.branch1@clinica.test', '12345678', 'receptionist', 'Branch 1 (المعادي)'],
                    ['منى (Receptionist Branch 2)', 'reception.branch2@clinica.test', '12345678', 'receptionist', 'Branch 2 (مدينة نصر)'],
                ]
            );

            $this->command->newLine();
            $this->command->warn('🏥 [CLINIC B (tenant-2) — Dual Doctor Shared Reception]');
            $this->command->table(
                ['Name', 'Email', 'Password', 'Roles', 'Assigned Branches'],
                [
                    ['د. طارق خليل (Dr. Tarek)', 'dr.tarek@clinicb.test', '12345678', 'clinic_owner, doctor', 'East Wing (الجناح الشرقي)'],
                    ['د. خالد عبد الرحمن (Dr. Khaled)', 'dr.khaled@clinicb.test', '12345678', 'doctor', 'West Wing (الجناح الغربي)'],
                    ['هدى (Shared Receptionist)', 'reception.shared@clinicb.test', '12345678', 'receptionist', 'East Wing + West Wing'],
                ]
            );

            $this->command->newLine();
            $this->command->warn('🏥 [CLINIC C (tenant-3) — Solo Doctor Walk-In]');
            $this->command->table(
                ['Name', 'Email', 'Password', 'Roles', 'Assigned Branches'],
                [
                    ['د. شريف عبد المنعم (Dr. Sherif)', 'dr.sherif@solo.test', '12345678', 'clinic_owner, doctor', 'Main Branch (Single Branch)'],
                ]
            );
            $this->command->info('================================================================================');
            $this->command->newLine();
        }
    }
}

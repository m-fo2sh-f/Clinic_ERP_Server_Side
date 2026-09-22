<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\User;
use App\Models\Service;
use App\Models\BranchService;
use App\Models\ClinicSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

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

        // Permissions list for tenants
        $standardPermissions = [
            'appointments.view',
            'appointments.create',
            'appointments.edit',
            'appointments.delete',
            'patients.view',
            'patients.create',
            'patients.edit',
            'prescriptions.create',
            'prescriptions.view',
            'live_queue.view',
            'live_queue.manage',
            'invoices.view',
            'invoices.create',
            'payments.create',
            'clinic_settings.manage',
            'manage staff',
            'manage branches',
            'view patients',
        ];

        // =========================================================================
        // 🏥 1. TENANT 1: عيادة النور التخصصية (Al-Noor Clinic)
        // Scenario 1: Multi-Branch (الدقي + مدينة نصر)
        // - Dr. Ahmed: [clinic_owner, doctor] -> فرع الدقي
        // - Dr. Sara: [doctor] -> فرع مدينة نصر
        // - Receptionist Mona: [receptionist] -> فرع الدقي + فرع مدينة نصر
        // =========================================================================
        $tenant1 = Tenant::firstOrCreate(
            ['id' => 'tenant-1'],
            [
                'clinic_name'    => 'عيادة النور (Al-Noor Clinic)',
                'owner_email'    => 'dr.ahmed@alnoor.com',
                'is_active'      => true,
                'admin_name'     => 'د. أحمد علي (Dr. Ahmed)',
                'admin_password' => $universalPasswordPlain,
            ]
        );
        $assignDomain = function (Tenant $tenant, string $domainName) {
            $existing = \Stancl\Tenancy\Database\Models\Domain::where('domain', $domainName)->first();
            if ($existing) {
                if ($existing->tenant_id !== $tenant->id) {
                    $existing->tenant_id = $tenant->id;
                    $existing->save();
                }
            } else {
                $tenant->domains()->create(['domain' => $domainName]);
            }
        };

        $tenant1->is_active = true;
        $tenant1->save();
        $assignDomain($tenant1, 'clinic1.my-saas.test');
        $assignDomain($tenant1, 'al-noor.my-saas.test');
        $assignDomain($tenant1, 'clinic1.localhost');

        $tenant1->run(function () use ($tenant1, $universalPasswordHash, $standardPermissions) {
            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($tenant1->id);
            }

            // 1. Ensure Standard Permissions exist
            foreach ($standardPermissions as $perm) {
                Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            }

            // 2. The 3 Standard Roles
            $ownerRole = Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
            $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
            $receptionRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

            $ownerRole->syncPermissions(Permission::where('guard_name', 'web')->get());
            $doctorRole->syncPermissions([
                'appointments.view',
                'appointments.create',
                'appointments.edit',
                'patients.view',
                'prescriptions.create',
                'prescriptions.view',
                'live_queue.view',
                'live_queue.manage',
                'view patients',
            ]);
            $receptionRole->syncPermissions([
                'appointments.view',
                'appointments.create',
                'appointments.edit',
                'patients.view',
                'patients.create',
                'live_queue.view',
                'live_queue.manage',
                'invoices.view',
                'invoices.create',
                'payments.create',
                'view patients',
            ]);

            // 3. Branches
            $branchDokki = Branch::firstOrCreate(
                ['name' => 'فرع الدقي'],
                ['address' => 'الدقي - شارع التحرير', 'is_active' => true]
            );

            $branchNasrCity = Branch::firstOrCreate(
                ['name' => 'فرع مدينة نصر'],
                ['address' => 'مدينة نصر - شارع عباس العقاد', 'is_active' => true]
            );

            ClinicSetting::firstOrCreate(
                ['branch_id' => $branchDokki->id],
                ['queue_strategy' => 'hybrid', 'avg_appointment_duration' => 15]
            );
            ClinicSetting::firstOrCreate(
                ['branch_id' => $branchNasrCity->id],
                ['queue_strategy' => 'hybrid', 'avg_appointment_duration' => 15]
            );

            $service = Service::firstOrCreate(
                ['code' => 'GEN-01'],
                ['name' => 'كشف باطنة عام', 'default_price' => 250.00, 'is_active' => true]
            );
            BranchService::firstOrCreate(
                ['branch_id' => $branchDokki->id, 'service_id' => $service->id],
                ['price' => 250.00, 'is_available' => true]
            );
            BranchService::firstOrCreate(
                ['branch_id' => $branchNasrCity->id, 'service_id' => $service->id],
                ['price' => 250.00, 'is_available' => true]
            );

            // 4. Staff Users
            // Doctor 1: Dr. Ahmed [clinic_owner, doctor] -> فرع الدقي
            $drAhmed = User::updateOrCreate(
                ['email' => 'dr.ahmed@alnoor.com'],
                [
                    'name'           => 'د. أحمد علي (Dr. Ahmed)',
                    'password'       => $universalPasswordHash,
                    'is_super_admin' => false,
                ]
            );
            $drAhmed->syncRoles([$ownerRole, $doctorRole]);
            $drAhmed->branches()->sync([$branchDokki->id]);

            // Doctor 2: Dr. Sara [doctor] -> فرع مدينة نصر
            $drSara = User::updateOrCreate(
                ['email' => 'dr.sara@alnoor.com'],
                [
                    'name'           => 'د. سارة محمود (Dr. Sara)',
                    'password'       => $universalPasswordHash,
                    'is_super_admin' => false,
                ]
            );
            $drSara->syncRoles([$doctorRole]);
            $drSara->branches()->sync([$branchNasrCity->id]);

            // Receptionist: Mona [receptionist] -> فرع الدقي + فرع مدينة نصر
            $recMona = User::updateOrCreate(
                ['email' => 'reception.mona@alnoor.com'],
                [
                    'name'           => 'منى - ريسبشن (Receptionist Mona)',
                    'password'       => $universalPasswordHash,
                    'is_super_admin' => false,
                ]
            );
            $recMona->syncRoles([$receptionRole]);
            $recMona->branches()->sync([$branchDokki->id, $branchNasrCity->id]);
        });

        // =========================================================================
        // 🏥 2. TENANT 2: عيادة الأمل (Al-Amal Clinic)
        // Scenario 2: Multi-Branch with Shared Doctor & Dedicated Receptionists
        // - Dr. Mahmoud: [clinic_owner, doctor] -> فرع المعادي + فرع التجمع
        // - Receptionist Hoda: [receptionist] -> فرع المعادي
        // - Receptionist Nour: [receptionist] -> فرع التجمع
        // =========================================================================
        $tenant2 = Tenant::firstOrCreate(
            ['id' => 'tenant-2'],
            [
                'clinic_name'    => 'عيادة الأمل (Al-Amal Clinic)',
                'owner_email'    => 'dr.mahmoud@alamal.com',
                'is_active'      => true,
                'admin_name'     => 'د. محمود حسني (Dr. Mahmoud)',
                'admin_password' => $universalPasswordPlain,
            ]
        );
        $tenant2->is_active = true;
        $tenant2->save();
        $assignDomain($tenant2, 'clinic2.my-saas.test');
        $assignDomain($tenant2, 'al-amal.my-saas.test');
        $assignDomain($tenant2, 'clinic2.localhost');

        $tenant2->run(function () use ($tenant2, $universalPasswordHash, $standardPermissions) {
            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($tenant2->id);
            }

            // 1. Ensure Standard Permissions exist
            foreach ($standardPermissions as $perm) {
                Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
            }

            // 2. The 3 Standard Roles
            $ownerRole = Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
            $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
            $receptionRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

            $ownerRole->syncPermissions(Permission::where('guard_name', 'web')->get());
            $doctorRole->syncPermissions([
                'appointments.view',
                'appointments.create',
                'appointments.edit',
                'patients.view',
                'prescriptions.create',
                'prescriptions.view',
                'live_queue.view',
                'live_queue.manage',
                'view patients',
            ]);
            $receptionRole->syncPermissions([
                'appointments.view',
                'appointments.create',
                'appointments.edit',
                'patients.view',
                'patients.create',
                'live_queue.view',
                'live_queue.manage',
                'invoices.view',
                'invoices.create',
                'payments.create',
                'view patients',
            ]);

            // 3. Branches
            $branchMaadi = Branch::firstOrCreate(
                ['name' => 'فرع المعادي'],
                ['address' => 'المعادي - شارع 9', 'is_active' => true]
            );

            $branchTagamoa = Branch::firstOrCreate(
                ['name' => 'فرع التجمع'],
                ['address' => 'التجمع الخامس - شارع التسعين', 'is_active' => true]
            );

            ClinicSetting::firstOrCreate(
                ['branch_id' => $branchMaadi->id],
                ['queue_strategy' => 'hybrid', 'avg_appointment_duration' => 20]
            );
            ClinicSetting::firstOrCreate(
                ['branch_id' => $branchTagamoa->id],
                ['queue_strategy' => 'hybrid', 'avg_appointment_duration' => 20]
            );

            $service = Service::firstOrCreate(
                ['code' => 'DEN-01'],
                ['name' => 'كشف أسنان تخصصي', 'default_price' => 300.00, 'is_active' => true]
            );
            BranchService::firstOrCreate(
                ['branch_id' => $branchMaadi->id, 'service_id' => $service->id],
                ['price' => 300.00, 'is_available' => true]
            );
            BranchService::firstOrCreate(
                ['branch_id' => $branchTagamoa->id, 'service_id' => $service->id],
                ['price' => 300.00, 'is_available' => true]
            );

            // 4. Staff Users
            // Doctor: Dr. Mahmoud [clinic_owner, doctor] -> فرع المعادي + فرع التجمع
            $drMahmoud = User::updateOrCreate(
                ['email' => 'dr.mahmoud@alamal.com'],
                [
                    'name'           => 'د. محمود حسني (Dr. Mahmoud)',
                    'password'       => $universalPasswordHash,
                    'is_super_admin' => false,
                ]
            );
            $drMahmoud->syncRoles([$ownerRole, $doctorRole]);
            $drMahmoud->branches()->sync([$branchMaadi->id, $branchTagamoa->id]);

            // Receptionist 1: Hoda [receptionist] -> فرع المعادي فقط
            $recHoda = User::updateOrCreate(
                ['email' => 'reception.hoda@alamal.com'],
                [
                    'name'           => 'هدى - استقبال المعادي (Receptionist Hoda)',
                    'password'       => $universalPasswordHash,
                    'is_super_admin' => false,
                ]
            );
            $recHoda->syncRoles([$receptionRole]);
            $recHoda->branches()->sync([$branchMaadi->id]);

            // Receptionist 2: Nour [receptionist] -> فرع التجمع فقط
            $recNour = User::updateOrCreate(
                ['email' => 'reception.nour@alamal.com'],
                [
                    'name'           => 'نور - استقبال التجمع (Receptionist Nour)',
                    'password'       => $universalPasswordHash,
                    'is_super_admin' => false,
                ]
            );
            $recNour->syncRoles([$receptionRole]);
            $recNour->branches()->sync([$branchTagamoa->id]);
        });

        // =========================================================================
        // 📊 PRINT CONSOLE SUMMARY
        // =========================================================================
        if ($this->command) {
            $this->command->newLine();
            $this->command->info('================================================================================');
            $this->command->info(' 🚀 MULTI-TENANT TEST DATA SEEDED SUCCESSFULLY (PASSWORD: 12345678)');
            $this->command->info('================================================================================');

            $this->command->newLine();
            $this->command->warn('👑 [PLATFORM CENTRAL ADMIN]');
            $this->command->table(
                ['Role', 'Email', 'Password', 'Notes'],
                [
                    ['Super Admin', 'admin@platform.test', '12345678', 'Platform Management & Tenant Overseer'],
                ]
            );

            $this->command->newLine();
            $this->command->warn('🏥 [TENANT 1: عيادة النور - Al-Noor Clinic (tenant-1)]');
            $this->command->table(
                ['Name', 'Email', 'Password', 'Roles', 'Assigned Branches'],
                [
                    ['د. أحمد علي (Dr. Ahmed)', 'dr.ahmed@alnoor.com', '12345678', 'clinic_owner, doctor', 'فرع الدقي'],
                    ['د. سارة محمود (Dr. Sara)', 'dr.sara@alnoor.com', '12345678', 'doctor', 'فرع مدينة نصر'],
                    ['منى (Receptionist Mona)', 'reception.mona@alnoor.com', '12345678', 'receptionist', 'فرع الدقي + فرع مدينة نصر'],
                ]
            );

            $this->command->newLine();
            $this->command->warn('🏥 [TENANT 2: عيادة الأمل - Al-Amal Clinic (tenant-2)]');
            $this->command->table(
                ['Name', 'Email', 'Password', 'Roles', 'Assigned Branches'],
                [
                    ['د. محمود حسني (Dr. Mahmoud)', 'dr.mahmoud@alamal.com', '12345678', 'clinic_owner, doctor', 'فرع المعادي + فرع التجمع'],
                    ['هدى (Receptionist Hoda)', 'reception.hoda@alamal.com', '12345678', 'receptionist', 'فرع المعادي فقط'],
                    ['نور (Receptionist Nour)', 'reception.nour@alamal.com', '12345678', 'receptionist', 'فرع التجمع فقط'],
                ]
            );
            $this->command->info('================================================================================');
            $this->command->newLine();
        }
    }
}

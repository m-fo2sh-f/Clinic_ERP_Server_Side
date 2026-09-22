<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Branch;
use App\Models\ClinicSetting;
use App\Models\Service;
use App\Models\BranchService;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        $tenantId = $tenant ? $tenant->getTenantKey() : null;

        if ($tenantId && function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenantId);
        }

        // 1. إنشاء الصلاحيات الطبية والإدارية
        $permissions = [
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

        foreach ($permissions as $permName) {
            Permission::firstOrCreate(['name' => $permName, 'guard_name' => 'web']);
        }

        // 2. إنشاء الأدوار الثلاثة المعتمدة
        $ownerRole = Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
        $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        $receptionistRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

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

        $receptionistRole->syncPermissions([
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

        // 3. إنشاء الفرع الرئيسي الافتراضي
        $mainBranch = Branch::firstOrCreate(
            ['name' => 'الفرع الرئيسي'],
            [
                'address' => 'المركز الرئيسي',
                'phone' => $tenant->phone ?? null,
                'is_active' => true,
            ]
        );

        // 4. إعدادات العيادة للفرع
        ClinicSetting::firstOrCreate(
            ['branch_id' => $mainBranch->id],
            [
                'queue_strategy' => 'hybrid',
                'avg_appointment_duration' => 15,
            ]
        );

        // 5. خدمة كشف افتراضية
        $consultationService = Service::firstOrCreate(
            ['name' => 'كشف عام'],
            [
                'code' => 'GEN-01',
                'default_price' => 200.00,
                'is_active' => true,
            ]
        );

        BranchService::firstOrCreate(
            [
                'branch_id' => $mainBranch->id,
                'service_id' => $consultationService->id,
            ],
            [
                'price' => 200.00,
                'is_available' => true,
            ]
        );

        // 6. إنشاء حساب مدير العيادة (Clinic Owner) في قاعدة بيانات العيادة
        $ownerEmail = $tenant->owner_email ?? ($tenant->data['admin_email'] ?? 'admin@' . ($tenantId ?: 'clinic') . '.test');
        $ownerName = $tenant->admin_name ?? ($tenant->data['admin_name'] ?? 'مدير العيادة');
        $ownerPassword = $tenant->admin_password ?? ($tenant->data['admin_password'] ?? 'Password123!');

        $owner = User::firstOrCreate(
            ['email' => $ownerEmail],
            [
                'name' => $ownerName,
                'password' => Hash::needsRehash($ownerPassword) ? Hash::make($ownerPassword) : $ownerPassword,
                'is_super_admin' => false,
            ]
        );

        if ($tenantId && function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenantId);
        }

        $owner->assignRole(['clinic_owner']);
        $owner->branches()->syncWithoutDetaching([$mainBranch->id]);
    }
}

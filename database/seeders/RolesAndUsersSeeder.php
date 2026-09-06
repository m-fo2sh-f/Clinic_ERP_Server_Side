<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Branch;
use App\Models\Tenant; // 👈 استدعاء موديل Tenant الخاص بالباكدج
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

class RolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        // 0️⃣ إنشاء الـ Tenant التجريبي الأول في جدول tenants
        // (تأكد من اسم الموديل الخاص بالـ Tenancy عندك، غالباً يكون App\Models\Tenant)
        $tenant = Tenant::firstOrCreate(
            ['id' => 'tenant-1'],
            ['tenancy_db_name' => 'tenant1_db'] // أو أي الحقول المطلوبة في جدول tenants عندك
        );

        // 1️⃣ تعيين معزل الصلاحيات للـ Tenant
        setPermissionsTeamId($tenant->id);

        // 1️⃣ إنشاء الصلاحيات (Permissions)
        $manageStaff = Permission::firstOrCreate(['name' => 'manage staff']);
        $manageBranches = Permission::firstOrCreate(['name' => 'manage branches']);
        $viewPatients = Permission::firstOrCreate(['name' => 'view patients']);

        // 2️⃣ إنشاء الأدوار وتعيين الصلاحيات
        $adminRole = Role::firstOrCreate(['name' => 'tenant_admin']);
        $adminRole->givePermissionTo([$manageStaff, $manageBranches, $viewPatients]);

        $doctorRole = Role::firstOrCreate(['name' => 'doctor']);
        $doctorRole->givePermissionTo([$viewPatients]);

        $receptionRole = Role::firstOrCreate(['name' => 'receptionist']);

        // 3️⃣ إنشاء الفروع باستخدام id الـ Tenant المنشأ
        $branchMaadi = Branch::create([
            'tenant_id' => $tenant->id, // 👈 ربط بالـ Tenant الفعلي
            'name' => 'فرع المعادي',
            'address' => 'المعادي - شارع 9',
        ]);

        $branchTagamoa = Branch::create([
            'tenant_id' => $tenant->id, // 👈 ربط بالـ Tenant الفعلي
            'name' => 'فرع التجمع',
            'address' => 'التجمع الخامس - شارع التسعين',
        ]);

        // 4️⃣ إنشاء الدكتور الرئيسي (صاحب العيادة - Admin + Doctor)
        $mainDoctor = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'د. أحمد علي (صاحب العيادة)',
            'email' => 'admin_doctor@clinic.com',
            'password' => Hash::make('12345678'),
        ]);
        setPermissionsTeamId($tenant->id);
        $mainDoctor->assignRole([$adminRole, $doctorRole]);
        $mainDoctor->branches()->attach([$branchMaadi->id, $branchTagamoa->id]);

        // 5️⃣ إنشاء دكتور مساعد
        $subDoctor = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'د. محمد حسن (دكتور مساعد)',
            'email' => 'doctor2@clinic.com',
            'password' => Hash::make('12345678'),
        ]);
        setPermissionsTeamId($tenant->id);
        $subDoctor->assignRole($doctorRole);
        $subDoctor->branches()->attach([$branchMaadi->id]);

        // 6️⃣ إنشاء موظفة ريسبشن
        $receptionist = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'سارة - ريسبشن',
            'email' => 'reception@clinic.com',
            'password' => Hash::make('12345678'),
        ]);
        setPermissionsTeamId($tenant->id);
        $receptionist->assignRole($receptionRole);
        $receptionist->branches()->attach([$branchMaadi->id]);
    }
}
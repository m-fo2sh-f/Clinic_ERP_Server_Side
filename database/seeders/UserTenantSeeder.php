<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserTenantSeeder extends Seeder
{
    /**
     * Run the multi-tenant user and branch database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            $universalPassword = Hash::make('12345678');

            // =========================================================================
            // 1. TENANT 1: عيادة النور التخصصية (tenant-1)
            // Single Branch with 2 Doctors & 1 Shared Receptionist
            // =========================================================================
            $tenant1 = Tenant::firstOrCreate(['id' => 'tenant-1']);
            $tenant1->domains()->firstOrCreate(['domain' => 'clinic1.my-saas.test']);

            $tenant1->run(function () use ($tenant1, $universalPassword) {
                // ضبط معزل الفرق لصلاحيات التينانت 1
                setPermissionsTeamId($tenant1->id);

                // Ensure Roles exist for both web and sanctum guards
                $doctorRole    = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
                Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'sanctum']);

                $receptionRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);
                Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'sanctum']);

                // Branch: الفرع الرئيسي
                $mainBranch = Branch::firstOrCreate(
                    ['name' => 'الفرع الرئيسي'],
                    ['address' => 'الفرع الرئيسي - عيادة النور']
                );

                // 1. Doctor 1: د. أحمد علي
                $drAhmed = User::updateOrCreate(
                    ['email' => 'dr.ahmed@tenant1.com'],
                    [
                        'name' => 'د. أحمد علي',
                        'tenant_id' => $tenant1->id,
                        'password' => $universalPassword,
                    ]
                );
                setPermissionsTeamId($tenant1->id);
                $drAhmed->syncRoles([$doctorRole]);
                $drAhmed->branches()->syncWithoutDetaching([$mainBranch->id]);

                // 2. Doctor 2: د. سارة محمود
                $drSara = User::updateOrCreate(
                    ['email' => 'dr.sara@tenant1.com'],
                    [
                        'name' => 'د. سارة محمود',
                        'tenant_id' => $tenant1->id,
                        'password' => $universalPassword,
                    ]
                );
                setPermissionsTeamId($tenant1->id);
                $drSara->syncRoles([$doctorRole]);
                $drSara->branches()->syncWithoutDetaching([$mainBranch->id]);

                // 3. Shared Receptionist: استقبال الفرع الرئيسي
                $receptionTenant1 = User::updateOrCreate(
                    ['email' => 'reception@tenant1.com'],
                    [
                        'name' => 'استقبال الفرع الرئيسي',
                        'tenant_id' => $tenant1->id,
                        'password' => $universalPassword,
                    ]
                );
                setPermissionsTeamId($tenant1->id);
                $receptionTenant1->syncRoles([$receptionRole]);
                $receptionTenant1->branches()->syncWithoutDetaching([$mainBranch->id]);
            });

            // =========================================================================
            // 2. TENANT 2: مجموعة عيادات الأمل الطبية (tenant-2)
            // Multi-Branch: Each branch has its dedicated Doctor and Receptionist
            // =========================================================================
            $tenant2 = Tenant::firstOrCreate(['id' => 'tenant-2']);
            $tenant2->domains()->firstOrCreate(['domain' => 'clinic2.my-saas.test']);

            $tenant2->run(function () use ($tenant2, $universalPassword) {
                // ضبط معزل الفرق لصلاحيات التينانت 2
                setPermissionsTeamId($tenant2->id);

                // Ensure Roles exist for both web and sanctum guards
                $doctorRole    = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
                Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'sanctum']);

                $receptionRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);
                Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'sanctum']);

                // Branch A: فرع مدينة نصر
                $branchNasr = Branch::firstOrCreate(
                    ['name' => 'فرع مدينة نصر'],
                    ['address' => 'مدينة نصر - شارع عباس العقاد']
                );

                // Branch B: فرع التجمع الخامس
                $branchTagamoa = Branch::firstOrCreate(
                    ['name' => 'فرع التجمع الخامس'],
                    ['address' => 'التجمع الخامس - شارع التسعين']
                );

                // --- فرع مدينة نصر ---
                // Doctor A: د. طارق خليل
                $drTarek = User::updateOrCreate(
                    ['email' => 'dr.tarek@tenant2.com'],
                    [
                        'name' => 'د. طارق خليل',
                        'tenant_id' => $tenant2->id,
                        'password' => $universalPassword,
                    ]
                );
                setPermissionsTeamId($tenant2->id);
                $drTarek->syncRoles([$doctorRole]);
                $drTarek->branches()->sync([$branchNasr->id]);

                // Receptionist A: استقبال فرع مدينة نصر
                $receptionNasr = User::updateOrCreate(
                    ['email' => 'reception.nasr@tenant2.com'],
                    [
                        'name' => 'استقبال فرع مدينة نصر',
                        'tenant_id' => $tenant2->id,
                        'password' => $universalPassword,
                    ]
                );
                setPermissionsTeamId($tenant2->id);
                $receptionNasr->syncRoles([$receptionRole]);
                $receptionNasr->branches()->sync([$branchNasr->id]);

                // --- فرع التجمع الخامس ---
                // Doctor B: د. خالد عبد الرحمن
                $drKhaled = User::updateOrCreate(
                    ['email' => 'dr.khaled@tenant2.com'],
                    [
                        'name' => 'د. خالد عبد الرحمن',
                        'tenant_id' => $tenant2->id,
                        'password' => $universalPassword,
                    ]
                );
                setPermissionsTeamId($tenant2->id);
                $drKhaled->syncRoles([$doctorRole]);
                $drKhaled->branches()->sync([$branchTagamoa->id]);

                // Receptionist B: استقبال فرع التجمع
                $receptionTagamoa = User::updateOrCreate(
                    ['email' => 'reception.tagamoa@tenant2.com'],
                    [
                        'name' => 'استقبال فرع التجمع',
                        'tenant_id' => $tenant2->id,
                        'password' => $universalPassword,
                    ]
                );
                setPermissionsTeamId($tenant2->id);
                $receptionTagamoa->syncRoles([$receptionRole]);
                $receptionTagamoa->branches()->sync([$branchTagamoa->id]);
            });
        });
    }
}

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

            $tenant1->run(function () use ($universalPassword) {
                // Ensure Roles
                $doctorRole    = Role::firstOrCreate(['name' => 'doctor']);
                $receptionRole = Role::firstOrCreate(['name' => 'receptionist']);

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
                        'password' => $universalPassword,
                    ]
                );
                $drAhmed->syncRoles([$doctorRole]);
                $drAhmed->branches()->syncWithoutDetaching([$mainBranch->id]);

                // 2. Doctor 2: د. سارة محمود
                $drSara = User::updateOrCreate(
                    ['email' => 'dr.sara@tenant1.com'],
                    [
                        'name' => 'د. سارة محمود',
                        'password' => $universalPassword,
                    ]
                );
                $drSara->syncRoles([$doctorRole]);
                $drSara->branches()->syncWithoutDetaching([$mainBranch->id]);

                // 3. Shared Receptionist: استقبال الفرع الرئيسي
                $receptionTenant1 = User::updateOrCreate(
                    ['email' => 'reception@tenant1.com'],
                    [
                        'name' => 'استقبال الفرع الرئيسي',
                        'password' => $universalPassword,
                    ]
                );
                $receptionTenant1->syncRoles([$receptionRole]);
                $receptionTenant1->branches()->syncWithoutDetaching([$mainBranch->id]);
            });

            // =========================================================================
            // 2. TENANT 2: مجموعة عيادات الأمل الطبية (tenant-2)
            // Multi-Branch with 1 Shared Doctor & Dedicated Receptionists
            // =========================================================================
            $tenant2 = Tenant::firstOrCreate(['id' => 'tenant-2']);
            $tenant2->domains()->firstOrCreate(['domain' => 'clinic2.my-saas.test']);

            $tenant2->run(function () use ($universalPassword) {
                // Ensure Roles
                $doctorRole    = Role::firstOrCreate(['name' => 'doctor']);
                $receptionRole = Role::firstOrCreate(['name' => 'receptionist']);

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

                // 1. Shared Doctor: د. طارق خليل (Assigned to Both Branches)
                $drTarek = User::updateOrCreate(
                    ['email' => 'dr.tarek@tenant2.com'],
                    [
                        'name' => 'د. طارق خليل',
                        'password' => $universalPassword,
                    ]
                );
                $drTarek->syncRoles([$doctorRole]);
                $drTarek->branches()->syncWithoutDetaching([$branchNasr->id, $branchTagamoa->id]);

                // 2. Receptionist Branch A: استقبال فرع مدينة نصر
                $receptionNasr = User::updateOrCreate(
                    ['email' => 'reception.nasr@tenant2.com'],
                    [
                        'name' => 'استقبال فرع مدينة نصر',
                        'password' => $universalPassword,
                    ]
                );
                $receptionNasr->syncRoles([$receptionRole]);
                $receptionNasr->branches()->syncWithoutDetaching([$branchNasr->id]);

                // 3. Receptionist Branch B: استقبال فرع التجمع
                $receptionTagamoa = User::updateOrCreate(
                    ['email' => 'reception.tagamoa@tenant2.com'],
                    [
                        'name' => 'استقبال فرع التجمع',
                        'password' => $universalPassword,
                    ]
                );
                $receptionTagamoa->syncRoles([$receptionRole]);
                $receptionTagamoa->branches()->syncWithoutDetaching([$branchTagamoa->id]);
            });
        });
    }
}

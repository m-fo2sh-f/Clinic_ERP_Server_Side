<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Branch;
use App\Models\ClinicSetting;
use App\Models\Service;
use App\Models\BranchService;
use App\Enums\ClinicMode;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = function_exists('tenant') ? tenant() : null;
        $tenantId = $tenant ? (string) $tenant->getTenantKey() : null;

        if ($tenantId && function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($tenantId);
        }

        $passwordHash = Hash::make('12345678');

        // 1. Standard Permissions
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

        // 2. Standard Roles
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
            'invoices.create',
            'invoices.view',
            'payments.create',
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

        // Branch & Staff Scaffolding according to Clinic Environment
        if ($tenantId === 'tenant-1') {
            $this->seedClinicA($ownerRole, $doctorRole, $receptionistRole, $passwordHash);
        } elseif ($tenantId === 'tenant-2') {
            $this->seedClinicB($ownerRole, $doctorRole, $receptionistRole, $passwordHash);
        } elseif ($tenantId === 'tenant-3') {
            $this->seedClinicC($ownerRole, $doctorRole, $passwordHash);
        } elseif (app()->runningUnitTests() && (str_starts_with((string)$tenantId, 'super') || str_starts_with((string)$tenantId, 'alpha') || str_starts_with((string)$tenantId, 'beta') || str_starts_with((string)$tenantId, 'test-'))) {
            // Test suites handle their own custom entity seeding in setUp()
            return;
        } else {
            // Default Dynamic Tenant Setup
            $this->seedDefaultClinic($tenant, $ownerRole, $doctorRole, $receptionistRole, $passwordHash);
        }
    }

    /**
     * 🏥 Clinic A: Polyclinic Multi-Branch
     * 2 Branches, 1 Doctor assigned to both, 2 Dedicated Receptionists.
     */
    protected function seedClinicA(Role $ownerRole, Role $doctorRole, Role $receptionistRole, string $passwordHash): void
    {
        $branch1 = Branch::firstOrCreate(
            ['name' => 'Branch 1 - فرع المعادي'],
            ['address' => 'المعادي - شارع اللاسلكي', 'phone' => '01011111111', 'is_active' => true]
        );

        $branch2 = Branch::firstOrCreate(
            ['name' => 'Branch 2 - فرع مدينة نصر'],
            ['address' => 'مدينة نصر - مكرم عبيد', 'phone' => '01022222222', 'is_active' => true]
        );

        ClinicSetting::updateOrCreate(
            ['branch_id' => $branch1->id],
            ['clinic_mode' => ClinicMode::POLYCLINIC->value, 'queue_strategy' => 'hybrid', 'avg_appointment_duration' => 15]
        );
        ClinicSetting::updateOrCreate(
            ['branch_id' => $branch2->id],
            ['clinic_mode' => ClinicMode::POLYCLINIC->value, 'queue_strategy' => 'hybrid', 'avg_appointment_duration' => 15]
        );

        $consultService = Service::firstOrCreate(
            ['code' => 'GEN-01'],
            ['name' => 'كشف باطنة عام', 'default_price' => 250.00, 'is_active' => true]
        );
        $ultrasoundService = Service::firstOrCreate(
            ['code' => 'US-01'],
            ['name' => 'سونار بطن وحوض', 'default_price' => 350.00, 'is_active' => true]
        );

        foreach ([$branch1, $branch2] as $branch) {
            BranchService::firstOrCreate(
                ['branch_id' => $branch->id, 'service_id' => $consultService->id],
                ['price' => 250.00, 'is_available' => true]
            );
            BranchService::firstOrCreate(
                ['branch_id' => $branch->id, 'service_id' => $ultrasoundService->id],
                ['price' => 350.00, 'is_available' => true]
            );
        }

        // Doctor assigned to both branches
        $doctor = User::updateOrCreate(
            ['email' => 'dr.ahmed@clinica.test'],
            ['name' => 'د. أحمد علي (Dr. Ahmed Ali)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $doctor->syncRoles([$ownerRole, $doctorRole]);
        $doctor->branches()->sync([$branch1->id, $branch2->id]);

        // Alias for backwards compatibility with tests
        $doctorAlias = User::updateOrCreate(
            ['email' => 'dr.ahmed@alnoor.com'],
            ['name' => 'د. أحمد علي (Dr. Ahmed Ali)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $doctorAlias->syncRoles([$ownerRole, $doctorRole]);
        $doctorAlias->branches()->sync([$branch1->id, $branch2->id]);

        // Receptionist 1 -> Branch 1
        $rec1 = User::updateOrCreate(
            ['email' => 'reception.branch1@clinica.test'],
            ['name' => 'سارة - استقبال المعادي (Receptionist Branch 1)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $rec1->syncRoles([$receptionistRole]);
        $rec1->branches()->sync([$branch1->id]);

        // Receptionist 2 -> Branch 2
        $rec2 = User::updateOrCreate(
            ['email' => 'reception.branch2@clinica.test'],
            ['name' => 'منى - استقبال مدينة نصر (Receptionist Branch 2)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $rec2->syncRoles([$receptionistRole]);
        $rec2->branches()->sync([$branch2->id]);

        $recAlias = User::updateOrCreate(
            ['email' => 'reception.mona@alnoor.com'],
            ['name' => 'منى - استقبال مدينة نصر (Receptionist Branch 2)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $recAlias->syncRoles([$receptionistRole]);
        $recAlias->branches()->sync([$branch2->id]);
    }

    /**
     * 🏥 Clinic B: Dual Doctor Shared Reception
     * 2 Wings/Branches, 2 Doctors strictly separated, 1 Shared Receptionist.
     */
    protected function seedClinicB(Role $ownerRole, Role $doctorRole, Role $receptionistRole, string $passwordHash): void
    {
        $eastWing = Branch::firstOrCreate(
            ['name' => 'East Wing - الجناح الشرقي'],
            ['address' => 'مصر الجديدة - الميرغني (الجناح الشرقي)', 'phone' => '01033333333', 'is_active' => true]
        );

        $westWing = Branch::firstOrCreate(
            ['name' => 'West Wing - الجناح الغربي'],
            ['address' => 'مصر الجديدة - الميرغني (الجناح الغربي)', 'phone' => '01044444444', 'is_active' => true]
        );

        ClinicSetting::updateOrCreate(
            ['branch_id' => $eastWing->id],
            ['clinic_mode' => ClinicMode::POLYCLINIC->value, 'queue_strategy' => 'hybrid', 'avg_appointment_duration' => 20]
        );
        ClinicSetting::updateOrCreate(
            ['branch_id' => $westWing->id],
            ['clinic_mode' => ClinicMode::POLYCLINIC->value, 'queue_strategy' => 'hybrid', 'avg_appointment_duration' => 20]
        );

        $specializedService = Service::firstOrCreate(
            ['code' => 'SPEC-01'],
            ['name' => 'كشف تخصصي دقيق', 'default_price' => 300.00, 'is_active' => true]
        );
        $ecgService = Service::firstOrCreate(
            ['code' => 'ECG-01'],
            ['name' => 'رسم قلب ECG', 'default_price' => 150.00, 'is_active' => true]
        );

        foreach ([$eastWing, $westWing] as $branch) {
            BranchService::firstOrCreate(
                ['branch_id' => $branch->id, 'service_id' => $specializedService->id],
                ['price' => 300.00, 'is_available' => true]
            );
            BranchService::firstOrCreate(
                ['branch_id' => $branch->id, 'service_id' => $ecgService->id],
                ['price' => 150.00, 'is_available' => true]
            );
        }

        // Doctor A strictly in East Wing
        $drA = User::updateOrCreate(
            ['email' => 'dr.tarek@clinicb.test'],
            ['name' => 'د. طارق خليل (Dr. Tarek - East Wing)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $drA->syncRoles([$ownerRole, $doctorRole]);
        $drA->branches()->sync([$eastWing->id]);

        $drAAlias = User::updateOrCreate(
            ['email' => 'dr.tarek@tenant2.com'],
            ['name' => 'د. طارق خليل (Dr. Tarek - East Wing)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $drAAlias->syncRoles([$ownerRole, $doctorRole]);
        $drAAlias->branches()->sync([$eastWing->id]);

        // Doctor B strictly in West Wing
        $drB = User::updateOrCreate(
            ['email' => 'dr.khaled@clinicb.test'],
            ['name' => 'د. خالد عبد الرحمن (Dr. Khaled - West Wing)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $drB->syncRoles([$doctorRole]);
        $drB->branches()->sync([$westWing->id]);

        $drBAlias = User::updateOrCreate(
            ['email' => 'dr.khaled@tenant2.com'],
            ['name' => 'د. خالد عبد الرحمن (Dr. Khaled - West Wing)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $drBAlias->syncRoles([$doctorRole]);
        $drBAlias->branches()->sync([$westWing->id]);

        // Shared Receptionist across East & West Wings
        $sharedRec = User::updateOrCreate(
            ['email' => 'reception.shared@clinicb.test'],
            ['name' => 'هدى - استقبال مشترك (Shared Receptionist Hoda)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $sharedRec->syncRoles([$receptionistRole]);
        $sharedRec->branches()->sync([$eastWing->id, $westWing->id]);

        $sharedRecAlias = User::updateOrCreate(
            ['email' => 'reception.hoda@alamal.com'],
            ['name' => 'هدى - استقبال مشترك (Shared Receptionist Hoda)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $sharedRecAlias->syncRoles([$receptionistRole]);
        $sharedRecAlias->branches()->sync([$eastWing->id, $westWing->id]);
    }

    /**
     * 🏥 Clinic C: Solo Doctor Walk-In
     * 1 Branch, 1 Doctor only (no receptionist, cashier, or assistants), instant checkout & dynamic vitals.
     */
    protected function seedClinicC(Role $ownerRole, Role $doctorRole, string $passwordHash): void
    {
        $mainBranch = Branch::firstOrCreate(
            ['name' => 'Main Branch - الفرع الرئيسي'],
            ['address' => 'الشيخ زايد - بيفرلي هيلز', 'phone' => '01055555555', 'is_active' => true]
        );

        $vitalsConfig = [
            ['key' => 'bp_systolic', 'label' => 'Blood Pressure (Systolic)', 'unit' => 'mmHg', 'type' => 'number', 'is_active' => true],
            ['key' => 'bp_diastolic', 'label' => 'Blood Pressure (Diastolic)', 'unit' => 'mmHg', 'type' => 'number', 'is_active' => true],
            ['key' => 'heart_rate', 'label' => 'Heart Rate', 'unit' => 'bpm', 'type' => 'number', 'is_active' => true],
            ['key' => 'temperature', 'label' => 'Body Temperature', 'unit' => '°C', 'type' => 'number', 'is_active' => true],
            ['key' => 'respiratory_rate', 'label' => 'Respiratory Rate', 'unit' => 'bpm', 'type' => 'number', 'is_active' => true],
            ['key' => 'spo2', 'label' => 'Oxygen Saturation (SpO2)', 'unit' => '%', 'type' => 'number', 'is_active' => true],
            ['key' => 'weight', 'label' => 'Weight', 'unit' => 'kg', 'type' => 'number', 'is_active' => true],
            ['key' => 'height', 'label' => 'Height', 'unit' => 'cm', 'type' => 'number', 'is_active' => true],
            ['key' => 'bmi', 'label' => 'Body Mass Index (BMI)', 'unit' => 'kg/m²', 'type' => 'number', 'is_active' => true],
            ['key' => 'blood_sugar', 'label' => 'Random Blood Sugar', 'unit' => 'mg/dL', 'type' => 'number', 'is_active' => true],
        ];

        ClinicSetting::updateOrCreate(
            ['branch_id' => $mainBranch->id],
            [
                'clinic_mode'              => ClinicMode::SOLO->value,
                'queue_strategy'           => 'hybrid',
                'avg_appointment_duration' => 15,
                'vitals_config'            => $vitalsConfig,
            ]
        );

        $consultSolo = Service::firstOrCreate(
            ['code' => 'CONSULT-SOLO'],
            ['name' => 'كشف كونسلتو شامل', 'default_price' => 200.00, 'is_active' => true]
        );
        $ultrasoundSolo = Service::firstOrCreate(
            ['code' => 'US-SOLO'],
            ['name' => 'سونار استكشافي', 'default_price' => 300.00, 'is_active' => true]
        );

        BranchService::firstOrCreate(
            ['branch_id' => $mainBranch->id, 'service_id' => $consultSolo->id],
            ['price' => 200.00, 'is_available' => true]
        );
        BranchService::firstOrCreate(
            ['branch_id' => $mainBranch->id, 'service_id' => $ultrasoundSolo->id],
            ['price' => 300.00, 'is_available' => true]
        );

        // Doctor only - no assistants, cashiers, or receptionists
        $doctor = User::updateOrCreate(
            ['email' => 'dr.sherif@solo.test'],
            ['name' => 'د. شريف عبد المنعم (Dr. Sherif)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $doctor->syncRoles([$ownerRole, $doctorRole]);
        $doctor->branches()->sync([$mainBranch->id]);

        $doctorAlias = User::updateOrCreate(
            ['email' => 'dr.sherif@clinicc.test'],
            ['name' => 'د. شريف عبد المنعم (Dr. Sherif)', 'password' => $passwordHash, 'is_super_admin' => false]
        );
        $doctorAlias->syncRoles([$ownerRole, $doctorRole]);
        $doctorAlias->branches()->sync([$mainBranch->id]);
    }

    /**
     * Default Clinic Fallback for unspecified tenant IDs
     */
    protected function seedDefaultClinic($tenant, Role $ownerRole, Role $doctorRole, Role $receptionistRole, string $passwordHash): void
    {
        $clinicMode = $tenant?->clinic_mode ?? ($tenant?->data['clinic_mode'] ?? 'solo');
        $branchName = $tenant?->branch_name ?? ($tenant?->data['branch_name'] ?? 'الفرع الرئيسي');
        $plainPassword = $tenant?->admin_password ?? ($tenant?->data['admin_password'] ?? null);
        $passHash = $plainPassword ? Hash::make($plainPassword) : $passwordHash;

        $mainBranch = Branch::firstOrCreate(
            ['name' => $branchName],
            ['address' => 'المركز الرئيسي', 'is_active' => true]
        );

        if ($clinicMode === 'solo' || $clinicMode === ClinicMode::SOLO->value) {
            $vitalsConfig = [
                ['key' => 'bp_systolic', 'label' => 'Blood Pressure (Systolic)', 'unit' => 'mmHg', 'type' => 'number', 'is_active' => true],
                ['key' => 'bp_diastolic', 'label' => 'Blood Pressure (Diastolic)', 'unit' => 'mmHg', 'type' => 'number', 'is_active' => true],
                ['key' => 'heart_rate', 'label' => 'Heart Rate', 'unit' => 'bpm', 'type' => 'number', 'is_active' => true],
                ['key' => 'temperature', 'label' => 'Body Temperature', 'unit' => '°C', 'type' => 'number', 'is_active' => true],
                ['key' => 'respiratory_rate', 'label' => 'Respiratory Rate', 'unit' => 'bpm', 'type' => 'number', 'is_active' => true],
                ['key' => 'spo2', 'label' => 'Oxygen Saturation (SpO2)', 'unit' => '%', 'type' => 'number', 'is_active' => true],
                ['key' => 'weight', 'label' => 'Weight', 'unit' => 'kg', 'type' => 'number', 'is_active' => true],
                ['key' => 'height', 'label' => 'Height', 'unit' => 'cm', 'type' => 'number', 'is_active' => true],
                ['key' => 'bmi', 'label' => 'Body Mass Index (BMI)', 'unit' => 'kg/m²', 'type' => 'number', 'is_active' => true],
                ['key' => 'blood_sugar', 'label' => 'Random Blood Sugar', 'unit' => 'mg/dL', 'type' => 'number', 'is_active' => true],
            ];

            ClinicSetting::updateOrCreate(
                ['branch_id' => $mainBranch->id],
                [
                    'clinic_mode'              => ClinicMode::SOLO->value,
                    'queue_strategy'           => 'hybrid',
                    'avg_appointment_duration' => 15,
                    'vitals_config'            => $vitalsConfig,
                ]
            );

            $consultService = Service::firstOrCreate(
                ['code' => 'CONSULT-SOLO'],
                ['name' => 'كشف كونسلتو شامل', 'default_price' => 200.00, 'is_active' => true]
            );
            BranchService::firstOrCreate(
                ['branch_id' => $mainBranch->id, 'service_id' => $consultService->id],
                ['price' => 200.00, 'is_available' => true]
            );
        } else {
            ClinicSetting::updateOrCreate(
                ['branch_id' => $mainBranch->id],
                [
                    'clinic_mode'              => ClinicMode::POLYCLINIC->value,
                    'queue_strategy'           => 'scheduled',
                    'avg_appointment_duration' => 20,
                    'vitals_config'            => [],
                ]
            );

            $consultService = Service::firstOrCreate(
                ['code' => 'GEN-01'],
                ['name' => 'كشف عام', 'default_price' => 150.00, 'is_active' => true]
            );
            BranchService::firstOrCreate(
                ['branch_id' => $mainBranch->id, 'service_id' => $consultService->id],
                ['price' => 150.00, 'is_available' => true]
            );
        }

        $ownerEmail = $tenant?->owner_email ?? ($tenant?->data['admin_email'] ?? 'admin@clinic.test');
        $ownerName = $tenant?->admin_name ?? ($tenant?->data['admin_name'] ?? 'مدير العيادة');

        $owner = User::firstOrCreate(
            ['email' => $ownerEmail],
            ['name' => $ownerName, 'password' => $passHash, 'is_super_admin' => false]
        );

        if ($clinicMode === 'solo' || $clinicMode === ClinicMode::SOLO->value) {
            $owner->syncRoles([$ownerRole, $doctorRole]);
        } else {
            $owner->syncRoles([$ownerRole]);
        }

        $owner->branches()->syncWithoutDetaching([$mainBranch->id]);
    }
}

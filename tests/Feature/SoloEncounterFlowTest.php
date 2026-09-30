<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\ClinicSetting;
use App\Models\Service;
use App\Models\BranchService;
use App\Models\Encounter;
use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Enums\ClinicMode;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class SoloEncounterFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $doctor;
    protected Service $consultationService;
    protected Service $ecgService;
    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'solo-' . Str::random(6);
        $this->domain = $tenantId . '.test';

        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);

        $this->branch = Branch::create([
            'name'      => 'عيادة التجمع',
            'address'   => 'التجمع الخامس',
            'phone'     => '01000000000',
            'is_active' => true,
        ]);

        ClinicSetting::create([
            'branch_id'                => $this->branch->id,
            'clinic_mode'              => ClinicMode::SOLO->value,
            'queue_strategy'           => 'hybrid',
            'avg_appointment_duration' => 15,
            'vitals_config'            => [
                ['key' => 'bp_systolic', 'label' => 'Blood Pressure (Systolic)', 'unit' => 'mmHg'],
                ['key' => 'heart_rate', 'label' => 'Heart Rate', 'unit' => 'bpm'],
            ],
        ]);

        $this->doctor = User::create([
            'name'     => 'د. أحمد شاكر',
            'email'    => 'dr.ahmed@solo.test',
            'password' => bcrypt('Password123!'),
        ]);

        $this->doctor->assignRole('doctor');
        $this->doctor->branches()->attach($this->branch->id);

        $this->consultationService = Service::create([
            'name'          => 'كشف استشاري',
            'code'          => 'CONSULTATION',
            'default_price' => 200.00,
            'is_active'     => true,
        ]);

        $this->ecgService = Service::create([
            'name'          => 'رسم قلب ECG',
            'code'          => 'ECG',
            'default_price' => 100.00,
            'is_active'     => true,
        ]);

        BranchService::create([
            'branch_id'    => $this->branch->id,
            'service_id'   => $this->consultationService->id,
            'price'        => 250.00,
            'is_available' => true,
        ]);

        BranchService::create([
            'branch_id'    => $this->branch->id,
            'service_id'   => $this->ecgService->id,
            'price'        => 120.00,
            'is_available' => true,
        ]);
    }

    public function test_quick_start_walk_in_creates_patient_and_in_progress_encounter(): void
    {
        $response = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'       => $this->branch->id,
                'name'            => 'محمود فاروق',
                'phone'           => '01122334455',
                'age'             => 42,
                'gender'          => 'male',
                'chief_complaint' => 'صداع مستمر وارتفاع ضغط',
                'vitals'          => ['bp_systolic' => 140, 'heart_rate' => 85],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.type', 'walk_in')
            ->assertJsonPath('data.chief_complaint', 'صداع مستمر وارتفاع ضغط');

        $this->assertDatabaseHas('patients', [
            'name'  => 'محمود فاروق',
            'phone' => '01122334455',
        ]);

        $this->assertDatabaseHas('encounters', [
            'branch_id' => $this->branch->id,
            'doctor_id' => $this->doctor->id,
            'status'    => EncounterStatus::IN_PROGRESS->value,
        ]);
    }

    public function test_draft_protection_prevents_duplicate_active_encounters(): void
    {
        $patient = Patient::create([
            'medical_number' => 'MRN-TEST-01',
            'name'           => 'سارة حسن',
            'phone'          => '01234567890',
            'gender'         => 'female',
        ]);

        // First encounter started
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'  => $this->branch->id,
                'patient_id' => $patient->id,
            ])
            ->assertStatus(201);

        // Attempt second encounter without resume_existing -> must fail with 409
        $conflictResponse = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'  => $this->branch->id,
                'patient_id' => $patient->id,
            ]);

        $conflictResponse->assertStatus(409)
            ->assertJsonPath('status', 'error');

        // Attempt with resume_existing -> resumes successfully
        $resumeResponse = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'       => $this->branch->id,
                'patient_id'      => $patient->id,
                'resume_existing' => true,
            ]);

        $resumeResponse->assertStatus(201)
            ->assertJsonPath('status', 'success');
    }

    public function test_auto_save_draft_updates_clinical_data_non_blocking(): void
    {
        $patient = Patient::create([
            'medical_number' => 'MRN-TEST-02',
            'name'           => 'كريم فتحي',
            'phone'          => '01099887766',
            'gender'         => 'male',
        ]);

        $encounter = Encounter::create([
            'branch_id'  => $this->branch->id,
            'patient_id' => $patient->id,
            'doctor_id'  => $this->doctor->id,
            'type'       => EncounterType::WALK_IN->value,
            'status'     => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("http://{$this->domain}/api/v1/encounters/{$encounter->id}/draft", [
                'chief_complaint'      => 'ألم حاد في الصدر',
                'clinical_examination' => 'أصوات قلب منتظمة وضغط طبيعي',
                'diagnosis'            => ['Angina Pectoris'],
                'vitals'               => ['bp_systolic' => 130, 'heart_rate' => 90],
                'private_notes'        => 'المريض يعاني من إجهاد وتوتر بالعمل',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.chief_complaint', 'ألم حاد في الصدر');

        $encounter->refresh();
        $this->assertEquals('ألم حاد في الصدر', $encounter->chief_complaint);
        $this->assertEquals([['code' => null, 'description' => 'Angina Pectoris']], $encounter->diagnosis);
    }

    public function test_complete_encounter_creates_prescription_and_processes_payment(): void
    {
        $patient = Patient::create([
            'medical_number' => 'MRN-TEST-03',
            'name'           => 'منى إبراهيم',
            'phone'          => '01511223344',
            'gender'         => 'female',
        ]);

        $encounter = Encounter::create([
            'branch_id'  => $this->branch->id,
            'patient_id' => $patient->id,
            'doctor_id'  => $this->doctor->id,
            'type'       => EncounterType::WALK_IN->value,
            'status'     => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $payload = [
            'chief_complaint'      => 'التهاب بالحلق وحرارة',
            'clinical_examination' => 'احتقان اللوزتين',
            'diagnosis'            => ['Acute Tonsillitis'],
            'vitals'               => ['temperature' => 38.5],
            'general_advice'       => 'شرب سوائل دافئة والراحة التامة لمدة 3 أيام',
            'follow_up_date'       => now()->addDays(7)->toDateString(),
            'medications'          => [
                [
                    'drug_name'   => 'Augmentin 1g',
                    'dose'        => '1 tablet',
                    'frequency'   => 'Every 12 hours',
                    'duration'    => '7 days',
                    'instruction' => 'بعد الأكل',
                ],
            ],
            // Services: Branch override consultation is 250 EGP, ECG is 120 EGP => Total 370 EGP
            'services'             => [
                ['service_id' => $this->consultationService->id, 'quantity' => 1],
                ['service_id' => $this->ecgService->id, 'quantity' => 1],
            ],
            'discount'             => 20.00, // Total = 350 EGP
            'payments'             => [
                ['method' => 'cash', 'amount' => 200.00],
                ['method' => 'visa', 'amount' => 150.00, 'transaction_reference' => 'TXN-998877'],
            ],
        ];

        $response = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/{$encounter->id}/complete", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.encounter.status', 'completed');

        $this->assertDatabaseHas('encounters', [
            'id'     => $encounter->id,
            'status' => EncounterStatus::COMPLETED->value,
        ]);

        $this->assertDatabaseHas('prescriptions', [
            'encounter_id' => $encounter->id,
            'patient_id'   => $patient->id,
        ]);

        $this->assertDatabaseHas('prescription_items', [
            'drug_name' => 'Augmentin 1g',
        ]);

        $this->assertDatabaseHas('invoices', [
            'encounter_id'   => $encounter->id,
            'subtotal'       => 370.00,
            'discount'       => 20.00,
            'total'          => 350.00,
            'payment_status' => PaymentStatus::PAID->value,
        ]);

        $this->assertDatabaseHas('payments', [
            'payment_method' => 'cash',
            'amount'         => 200.00,
        ]);

        $this->assertDatabaseHas('payments', [
            'payment_method' => 'visa',
            'amount'         => 150.00,
        ]);
    }

    public function test_today_summary_endpoint(): void
    {
        $patient = Patient::create([
            'medical_number' => 'MRN-TEST-04',
            'name'           => 'طارق العوضي',
            'phone'          => '01001122334',
            'gender'         => 'male',
        ]);

        Encounter::create([
            'branch_id'  => $this->branch->id,
            'patient_id' => $patient->id,
            'doctor_id'  => $this->doctor->id,
            'type'       => EncounterType::WALK_IN->value,
            'status'     => EncounterStatus::COMPLETED->value,
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->doctor, 'sanctum')
            ->getJson("http://{$this->domain}/api/v1/encounters/today-summary?branch_id={$this->branch->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonStructure([
                'status',
                'data' => [
                    'total_encounters',
                    'completed_count',
                    'in_progress_count',
                    'total_revenue',
                    'encounters',
                ],
            ]);
    }
}

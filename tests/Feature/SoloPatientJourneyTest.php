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
use App\Models\Prescription;
use App\Models\Invoice;
use App\Models\Payment;
use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Enums\ClinicMode;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class SoloPatientJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $doctor;
    protected Service $consultationService;
    protected Service $ultrasoundService;
    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'solo-' . Str::random(6);
        $this->domain = $tenantId . '.test';

        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($this->tenant->id);
        }

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);

        $this->branch = Branch::create([
            'name'      => 'عيادة د. شريف - الفرع الرئيسي',
            'address'   => 'الشيخ زايد - الحي الدبلوماسي',
            'phone'     => '01055555555',
            'is_active' => true,
        ]);

        ClinicSetting::create([
            'branch_id'                => $this->branch->id,
            'clinic_mode'              => ClinicMode::SOLO->value,
            'queue_strategy'           => 'hybrid',
            'avg_appointment_duration' => 15,
            'vitals_config'            => [
                ['key' => 'bp_systolic', 'label' => 'Blood Pressure (Systolic)', 'unit' => 'mmHg', 'type' => 'number', 'is_active' => true],
                ['key' => 'bp_diastolic', 'label' => 'Blood Pressure (Diastolic)', 'unit' => 'mmHg', 'type' => 'number', 'is_active' => true],
                ['key' => 'heart_rate', 'label' => 'Heart Rate', 'unit' => 'bpm', 'type' => 'number', 'is_active' => true],
                ['key' => 'spo2', 'label' => 'Oxygen Saturation (SpO2)', 'unit' => '%', 'type' => 'number', 'is_active' => true],
                ['key' => 'temperature', 'label' => 'Body Temperature', 'unit' => '°C', 'type' => 'number', 'is_active' => true],
            ],
        ]);

        $this->doctor = User::create([
            'name'     => 'د. شريف عبد المنعم',
            'email'    => 'dr.sherif@solo.test',
            'password' => bcrypt('12345678'),
        ]);

        $this->doctor->assignRole(['clinic_owner', 'doctor']);
        $this->doctor->branches()->attach($this->branch->id);

        $this->consultationService = Service::create([
            'name'          => 'كشف كونسلتو شامل',
            'code'          => 'CONSULT-SOLO',
            'default_price' => 200.00,
            'is_active'     => true,
        ]);

        $this->ultrasoundService = Service::create([
            'name'          => 'سونار استكشافي',
            'code'          => 'US-SOLO',
            'default_price' => 300.00,
            'is_active'     => true,
        ]);

        BranchService::create([
            'branch_id'    => $this->branch->id,
            'service_id'   => $this->consultationService->id,
            'price'        => 200.00,
            'is_available' => true,
        ]);

        BranchService::create([
            'branch_id'    => $this->branch->id,
            'service_id'   => $this->ultrasoundService->id,
            'price'        => 300.00,
            'is_available' => true,
        ]);
    }

    /**
     * Test full end-to-end Solo Doctor Walk-In patient journey from search to checkout and lock.
     */
    public function test_complete_solo_doctor_walk_in_patient_journey(): void
    {
        $testPhone = '01099998877';

        // ── Step 1: Doctor searches patient by phone -> Not found ────────
        $searchRes = $this->actingAs($this->doctor, 'sanctum')
            ->getJson("http://{$this->domain}/api/v1/patients/search?query={$testPhone}");

        $searchRes->assertStatus(200)
            ->assertJsonPath('data', []);

        // ── Step 2: Doctor executes inline create + quick-start ───────────
        $quickStartRes = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'        => $this->branch->id,
                'name'             => 'محمود شاكر',
                'phone'            => $testPhone,
                'age'              => 38,
                'gender'           => 'male',
                'blood_group'      => 'O+',
                'chronic_diseases' => 'Hypertension',
                'allergies'        => 'Penicillin',
                'chief_complaint'  => 'Chest tightness and shortness of breath',
            ]);

        $quickStartRes->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', EncounterStatus::IN_PROGRESS->value)
            ->assertJsonPath('data.type', EncounterType::WALK_IN->value)
            ->assertJsonPath('data.appointment_id', null);

        $encounterId = $quickStartRes->json('data.id');
        $patientId = $quickStartRes->json('data.patient_id');

        $this->assertNotNull($encounterId);
        $this->assertNotNull($patientId);

        // Validate patient created with proper formatting
        $patient = Patient::findOrFail($patientId);
        $this->assertEquals('محمود شاكر', $patient->name);
        $this->assertEquals($testPhone, $patient->phone);
        $this->assertStringStartsWith('MRN-', $patient->medical_number);

        // Validate encounter initialized in progress
        $this->assertDatabaseHas('encounters', [
            'id'             => $encounterId,
            'branch_id'      => $this->branch->id,
            'patient_id'     => $patientId,
            'doctor_id'      => $this->doctor->id,
            'status'         => EncounterStatus::IN_PROGRESS->value,
            'appointment_id' => null,
        ]);

        // ── Step 3: Doctor updates vitals and clinical notes (draft) ─────
        $draftRes = $this->actingAs($this->doctor, 'sanctum')
            ->putJson("http://{$this->domain}/api/v1/encounters/{$encounterId}/draft", [
                'chief_complaint'      => 'Severe chest discomfort after exertion',
                'clinical_examination' => 'S1 S2 heard, lungs clear, no peripheral edema',
                'diagnosis'            => ['Angina Pectoris', 'Essential Hypertension'],
                'vitals'               => [
                    'bp_systolic'  => 150,
                    'bp_diastolic' => 95,
                    'heart_rate'   => 92,
                    'spo2'         => 98,
                    'temperature'  => 36.8,
                ],
                'private_notes'        => 'Patient is under work stress. Suggest stress ECG if symptoms persist.',
            ]);

        $draftRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', EncounterStatus::IN_PROGRESS->value)
            ->assertJsonPath('data.chief_complaint', 'Severe chest discomfort after exertion')
            ->assertJsonPath('data.vitals.bp_systolic', 150)
            ->assertJsonPath('data.vitals.heart_rate', 92);

        // ── Step 4: Doctor finishes exam with diagnosis, meds, ultrasound, cash payment
        $completeRes = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/{$encounterId}/complete", [
                'diagnosis'            => ['Stable Angina Pectoris', 'Essential Hypertension'],
                'medications'          => [
                    [
                        'drug_name'   => 'Aspirin Protect 100mg',
                        'dose'        => '1 tablet',
                        'frequency'   => 'Once daily',
                        'duration'    => '30 days',
                        'instruction' => 'After lunch',
                    ],
                    [
                        'drug_name'   => 'Concor Cor 2.5mg',
                        'dose'        => '1 tablet',
                        'frequency'   => 'Once daily',
                        'duration'    => '30 days',
                        'instruction' => 'Before breakfast',
                    ],
                ],
                'services'             => [
                    [
                        'service_id' => $this->ultrasoundService->id,
                        'quantity'   => 1,
                    ],
                ],
                'payments'             => [
                    [
                        'method' => 'cash',
                        'amount' => 300.00,
                    ],
                ],
                'general_advice'       => 'Avoid strenuous physical activity and monitor blood pressure daily.',
                'follow_up_date'       => now()->addDays(14)->toDateString(),
            ]);

        $completeRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.encounter.status', EncounterStatus::COMPLETED->value)
            ->assertJsonPath('data.invoice.payment_status', PaymentStatus::PAID->value);

        // ── Step 5: Assertions on full lifecycle completion ──────────────
        $encounter = Encounter::findOrFail($encounterId);
        $this->assertEquals(EncounterStatus::COMPLETED->value, $encounter->status->value);
        $this->assertNotNull($encounter->completed_at);

        // Prescription verification
        $prescription = Prescription::where('encounter_id', $encounterId)->first();
        $this->assertNotNull($prescription);
        $this->assertCount(2, $prescription->items);
        $this->assertEquals('Aspirin Protect 100mg', $prescription->items[0]->drug_name);
        $this->assertEquals('Concor Cor 2.5mg', $prescription->items[1]->drug_name);

        // Invoice snapshot price verification
        $invoice = Invoice::where('encounter_id', $encounterId)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(300.00, (float)$invoice->total);
        $this->assertEquals(PaymentStatus::PAID->value, $invoice->payment_status->value ?? $invoice->payment_status);
        $this->assertCount(1, $invoice->items);
        $this->assertEquals('سونار استكشافي', $invoice->items[0]->item_name);
        $this->assertEquals(300.00, (float)$invoice->items[0]->unit_price);

        // Payment verification
        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(300.00, (float)$payment->amount);
        $this->assertEquals('cash', $payment->payment_method->value ?? $payment->payment_method);
        $this->assertEquals($this->doctor->id, $payment->cashier_id);

        // Cannot update or save draft on this encounter anymore (returns 409)
        $lockedDraftRes = $this->actingAs($this->doctor, 'sanctum')
            ->putJson("http://{$this->domain}/api/v1/encounters/{$encounterId}/draft", [
                'chief_complaint' => 'Attempting to overwrite completed medical record',
            ]);

        $this->assertContains($lockedDraftRes->status(), [409, 422]);
        $this->assertEquals(409, $lockedDraftRes->status());

        // Verify medical data was NOT overwritten
        $encounter->refresh();
        $this->assertEquals('Severe chest discomfort after exertion', $encounter->chief_complaint);
    }
}

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
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Enums\ClinicMode;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class EncounterEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch1;
    protected Branch $branch2;
    protected User $doctorBranch1;
    protected User $doctorBranch2;
    protected Service $consultationService;
    protected Service $procedureService;
    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'edge-' . Str::random(6);
        $this->domain = $tenantId . '.test';

        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($this->tenant->id);
        }

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);

        $this->branch1 = Branch::create([
            'name'      => 'East Wing - الجناح الشرقي',
            'address'   => 'مصر الجديدة',
            'phone'     => '01011111111',
            'is_active' => true,
        ]);

        $this->branch2 = Branch::create([
            'name'      => 'West Wing - الجناح الغربي',
            'address'   => 'مصر الجديدة',
            'phone'     => '01022222222',
            'is_active' => true,
        ]);

        ClinicSetting::create([
            'branch_id'                => $this->branch1->id,
            'clinic_mode'              => ClinicMode::POLYCLINIC->value,
            'queue_strategy'           => 'hybrid',
            'avg_appointment_duration' => 15,
            'vitals_config'            => [
                ['key' => 'bp_systolic', 'label' => 'Blood Pressure (Systolic)', 'is_active' => true],
                ['key' => 'heart_rate', 'label' => 'Heart Rate', 'is_active' => true],
                ['key' => 'blood_sugar', 'label' => 'Blood Sugar', 'is_active' => false],
            ],
        ]);

        ClinicSetting::create([
            'branch_id'                => $this->branch2->id,
            'clinic_mode'              => ClinicMode::POLYCLINIC->value,
            'queue_strategy'           => 'hybrid',
            'avg_appointment_duration' => 15,
        ]);

        // Doctor 1 strictly in Branch 1
        $this->doctorBranch1 = User::create([
            'name'     => 'د. طارق خليل',
            'email'    => 'dr.tarek@clinicb.test',
            'password' => bcrypt('12345678'),
        ]);
        $this->doctorBranch1->assignRole('doctor');
        $this->doctorBranch1->branches()->attach($this->branch1->id);

        // Doctor 2 strictly in Branch 2
        $this->doctorBranch2 = User::create([
            'name'     => 'د. خالد عبد الرحمن',
            'email'    => 'dr.khaled@clinicb.test',
            'password' => bcrypt('12345678'),
        ]);
        $this->doctorBranch2->assignRole('doctor');
        $this->doctorBranch2->branches()->attach($this->branch2->id);

        $this->consultationService = Service::create([
            'name'          => 'كشف استشاري',
            'code'          => 'GEN-01',
            'default_price' => 200.00,
            'is_active'     => true,
        ]);

        $this->procedureService = Service::create([
            'name'          => 'فحص سونار دقيق',
            'code'          => 'PROC-01',
            'default_price' => 500.00,
            'is_active'     => true,
        ]);

        BranchService::create([
            'branch_id'    => $this->branch1->id,
            'service_id'   => $this->consultationService->id,
            'price'        => 200.00,
            'is_available' => true,
        ]);

        BranchService::create([
            'branch_id'    => $this->branch1->id,
            'service_id'   => $this->procedureService->id,
            'price'        => 500.00,
            'is_available' => true,
        ]);
    }

    /**
     * 1. Auto-Save Race Condition on Finish:
     * An auto-save draft request reaches the server after the encounter has already been marked completed.
     * Assert draft endpoint rejects with HTTP 409 Conflict and does NOT overwrite completed medical data.
     */
    public function test_auto_save_race_condition_rejected_after_completion(): void
    {
        $patient = Patient::create([
            'name'  => 'سامي جرجس',
            'phone' => '01000000001',
        ]);

        $encounter = Encounter::create([
            'branch_id'            => $this->branch1->id,
            'doctor_id'            => $this->doctorBranch1->id,
            'patient_id'           => $patient->id,
            'type'                 => EncounterType::WALK_IN->value,
            'status'               => EncounterStatus::IN_PROGRESS->value,
            'chief_complaint'      => 'Original Valid Complaint',
            'clinical_examination' => 'Original Valid Examination',
            'diagnosis'            => [['code' => null, 'description' => 'Original Confirmed Diagnosis']],
            'started_at'           => now(),
        ]);

        // Complete encounter
        $completeRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/{$encounter->id}/complete", [
                'chief_complaint' => 'Final Doctor Verified Complaint',
                'diagnosis'       => ['Final Doctor Verified Diagnosis'],
            ]);

        $completeRes->assertStatus(200);
        $encounter->refresh();
        $this->assertEquals(EncounterStatus::COMPLETED->value, $encounter->status->value);

        // In-flight auto-save arrives late
        $lateDraftRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->putJson("http://{$this->domain}/api/v1/encounters/{$encounter->id}/draft", [
                'chief_complaint' => 'MALICIOUS_OVERWRITE_RACE_CONDITION',
                'diagnosis'       => ['OVERWRITTEN_DIAGNOSIS'],
            ]);

        $lateDraftRes->assertStatus(409)
            ->assertJsonPath('status', 'error');

        // Verify database remains pristine with completed doctor data
        $encounter->refresh();
        $this->assertEquals('Final Doctor Verified Complaint', $encounter->chief_complaint);
        $this->assertEquals('Final Doctor Verified Diagnosis', $encounter->diagnosis[0]['description']);
    }

    /**
     * 2. Double Active Encounters Prevention:
     * Attempt to start two concurrent in_progress encounters for the exact same patient on the same day with the same doctor.
     * Assert system detects existing draft and prevents duplicate session creation (returns 409).
     */
    public function test_double_active_encounters_prevention_on_same_day(): void
    {
        $patient = Patient::create([
            'name'  => 'مروة عبد العزيز',
            'phone' => '01000000002',
        ]);

        // First encounter
        $firstRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'  => $this->branch1->id,
                'patient_id' => $patient->id,
            ]);

        $firstRes->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $firstEncounterId = $firstRes->json('data.id');

        // Duplicate attempt on the same day without resume flag
        $duplicateRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'  => $this->branch1->id,
                'patient_id' => $patient->id,
            ]);

        $duplicateRes->assertStatus(409)
            ->assertJsonPath('status', 'error');

        // When resume_existing is true, safely returns the active draft instead of creating a second one
        $resumeRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'       => $this->branch1->id,
                'patient_id'      => $patient->id,
                'resume_existing' => true,
            ]);

        $resumeRes->assertStatus(201)
            ->assertJsonPath('data.id', $firstEncounterId);

        // Verify only 1 encounter exists in DB
        $this->assertEquals(1, Encounter::where('patient_id', $patient->id)->count());
    }

    /**
     * 3. Cross-Tenant & Cross-Branch IDOR Attack:
     * A doctor authenticated in Clinic B (Branch 1) attempts to start an encounter or view an encounter belonging to Branch 2 or another tenant.
     * Assert HTTP 403 Forbidden or 404 Not Found.
     */
    public function test_cross_tenant_and_cross_branch_idor_attack_rejected(): void
    {
        $patientBranch2 = Patient::create([
            'name'  => 'ياسر القاضي',
            'phone' => '01000000003',
        ]);

        // Attack 1: Doctor 1 (Branch 1 only) tries to start encounter in Branch 2
        $crossBranchStart = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'  => $this->branch2->id,
                'patient_id' => $patientBranch2->id,
            ]);

        $crossBranchStart->assertStatus(403);

        // Doctor 2 legally starts an encounter in Branch 2
        $branch2Encounter = Encounter::create([
            'branch_id'  => $this->branch2->id,
            'doctor_id'  => $this->doctorBranch2->id,
            'patient_id' => $patientBranch2->id,
            'type'       => EncounterType::WALK_IN->value,
            'status'     => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        // Attack 2: Doctor 1 tries to view Branch 2's encounter
        $crossBranchShow = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->getJson("http://{$this->domain}/api/v1/encounters/{$branch2Encounter->id}");

        $crossBranchShow->assertStatus(403);

        // Attack 3: Doctor 1 tries to save draft on Branch 2's encounter
        $crossBranchDraft = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->putJson("http://{$this->domain}/api/v1/encounters/{$branch2Encounter->id}/draft", [
                'chief_complaint' => 'Unauthorized IDOR modification attempt',
            ]);

        $crossBranchDraft->assertStatus(403);

        // Attack 4: Cross-Tenant access with a foreign tenant encounter ID
        $foreignEncounterId = (string) Str::uuid();
        $crossTenantAccess = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->getJson("http://{$this->domain}/api/v1/encounters/{$foreignEncounterId}");

        $crossTenantAccess->assertStatus(404);
    }

    /**
     * 4. Price Fluctuation Immunity (Snapshot Verification):
     * Complete an encounter with a service priced at 200 EGP.
     * Afterwards, update the clinic service price in catalog to 500 EGP.
     * Reload the previous invoice and assert invoice_items.unit_price remains 200 EGP.
     */
    public function test_price_fluctuation_immunity_preserves_historical_snapshots(): void
    {
        $patient = Patient::create([
            'name'  => 'عمر فاروق',
            'phone' => '01000000004',
        ]);

        $encounter = Encounter::create([
            'branch_id'  => $this->branch1->id,
            'doctor_id'  => $this->doctorBranch1->id,
            'patient_id' => $patient->id,
            'type'       => EncounterType::WALK_IN->value,
            'status'     => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        // Complete encounter with consultation service (currently 200 EGP)
        $completeRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/{$encounter->id}/complete", [
                'diagnosis' => ['General Checkup'],
                'services'  => [
                    ['service_id' => $this->consultationService->id, 'quantity' => 1],
                ],
                'payments'  => [
                    ['method' => 'cash', 'amount' => 200.00],
                ],
            ]);

        $completeRes->assertStatus(200);

        $invoice = Invoice::where('encounter_id', $encounter->id)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(200.00, (float)$invoice->total);
        $this->assertEquals(200.00, (float)$invoice->items->first()->unit_price);

        // Drastic price increase in catalog from 200 EGP to 500 EGP
        $this->consultationService->update(['default_price' => 500.00]);
        BranchService::where('branch_id', $this->branch1->id)
            ->where('service_id', $this->consultationService->id)
            ->update(['price' => 500.00]);

        // Reload previous invoice from DB and API
        $invoice->refresh();
        $this->assertEquals(200.00, (float)$invoice->total);
        $this->assertEquals(200.00, (float)$invoice->items->first()->unit_price);

        $showInvoiceRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->getJson("http://{$this->domain}/api/v1/invoices/{$invoice->id}");

        $showInvoiceRes->assertStatus(200);
        $this->assertEquals(200.00, (float)$showInvoiceRes->json('data.total'));
        $this->assertEquals(200.00, (float)$showInvoiceRes->json('data.items.0.unit_price'));
    }

    /**
     * 5. Partial / Split Payment Integrity:
     * Total is 500 EGP: Attempt checkout with 300 Cash and 100 Card (total 400). Assert validation fails with mismatch.
     * Repeat with 300 Cash and 200 Card. Assert invoice created with two payments records totaling 500 EGP and marked paid.
     */
    public function test_partial_and_split_payment_integrity(): void
    {
        $patient = Patient::create([
            'name'  => 'كريم فهمي',
            'phone' => '01000000005',
        ]);

        $encounter = Encounter::create([
            'branch_id'  => $this->branch1->id,
            'doctor_id'  => $this->doctorBranch1->id,
            'patient_id' => $patient->id,
            'type'       => EncounterType::WALK_IN->value,
            'status'     => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        // Attempt 1: Service is 500 EGP, but payments total is only 400 (300 cash + 100 card)
        $mismatchRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/{$encounter->id}/complete", [
                'services' => [
                    ['service_id' => $this->procedureService->id, 'quantity' => 1], // 500 EGP
                ],
                'payments' => [
                    ['method' => 'cash', 'amount' => 300.00],
                    ['method' => 'visa', 'amount' => 100.00],
                ],
            ]);

        $mismatchRes->assertStatus(422)
            ->assertJsonPath('status', 'error');

        // Encounter remains in_progress
        $encounter->refresh();
        $this->assertEquals(EncounterStatus::IN_PROGRESS->value, $encounter->status->value);

        // Attempt 2: Exact Split Payment (300 Cash + 200 Card = 500 EGP)
        $exactSplitRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/{$encounter->id}/complete", [
                'services' => [
                    ['service_id' => $this->procedureService->id, 'quantity' => 1], // 500 EGP
                ],
                'payments' => [
                    ['method' => 'cash', 'amount' => 300.00],
                    ['method' => 'visa', 'amount' => 200.00, 'transaction_reference' => 'VISA-REF-9988'],
                ],
            ]);

        $exactSplitRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $invoice = Invoice::where('encounter_id', $encounter->id)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(500.00, (float)$invoice->total);
        $this->assertEquals(PaymentStatus::PAID->value, $invoice->payment_status->value ?? $invoice->payment_status);

        $payments = Payment::where('invoice_id', $invoice->id)->get();
        $this->assertCount(2, $payments);
        $this->assertEquals(300.00, (float)$payments->where('payment_method', 'cash')->first()->amount);
        $this->assertEquals(200.00, (float)$payments->where('payment_method', 'visa')->first()->amount);
        $this->assertEquals('VISA-REF-9988', $payments->where('payment_method', 'visa')->first()->transaction_reference);
    }

    /**
     * 6. Missing / Deactivated Vitals Fields:
     * Send vitals payload containing fields disabled in vitals_config or arbitrary unexpected keys.
     * Assert system safely handles or strips unrecognized keys without database exceptions.
     */
    public function test_missing_and_deactivated_vitals_handled_safely_without_db_exceptions(): void
    {
        $patient = Patient::create([
            'name'  => 'نوران عادل',
            'phone' => '01000000006',
        ]);

        // Branch 1 vitals_config has:
        // bp_systolic (is_active: true)
        // heart_rate (is_active: true)
        // blood_sugar (is_active: false)
        $vitalsPayload = [
            'bp_systolic'               => 120,
            'heart_rate'                => 78,
            'blood_sugar'               => 115, // Deactivated!
            'malicious_sql_injection'   => "'; DROP TABLE encounters; --",
            'unexpected_nested_payload' => ['deep_attack' => true],
        ];

        $startRes = $this->actingAs($this->doctorBranch1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'  => $this->branch1->id,
                'patient_id' => $patient->id,
                'vitals'     => $vitalsPayload,
            ]);

        $startRes->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $encounter = Encounter::findOrFail($startRes->json('data.id'));
        $storedVitals = $encounter->vitals;

        // Assert valid active fields are preserved
        $this->assertEquals(120, $storedVitals['bp_systolic']);
        $this->assertEquals(78, $storedVitals['heart_rate']);

        // Assert deactivated and unrecognized keys were safely stripped
        $this->assertArrayNotHasKey('blood_sugar', $storedVitals);
        $this->assertArrayNotHasKey('malicious_sql_injection', $storedVitals);
        $this->assertArrayNotHasKey('unexpected_nested_payload', $storedVitals);
    }
}

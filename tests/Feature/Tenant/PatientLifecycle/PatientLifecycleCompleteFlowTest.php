<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant\PatientLifecycle;

use App\Enums\AppointmentStatus;
use App\Enums\ClinicMode;
use App\Enums\EncounterStatus;
use App\Enums\LiveQueueStatus;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\ClinicSetting;
use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\LiveQueue;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PatientLifecycleCompleteFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;

    protected Tenant $tenantB;

    protected Branch $branchA;

    protected Branch $branchA2;

    protected Branch $branchB;

    protected User $doctorA;

    protected User $receptionistA;

    protected User $doctorB;

    protected Service $consultationServiceA;

    protected string $domainA;

    protected string $domainB;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantAId = 'lifecycle-a-'.Str::random(6);
        $tenantBId = 'lifecycle-b-'.Str::random(6);

        $this->domainA = $tenantAId.'.test';
        $this->domainB = $tenantBId.'.test';

        // 1. Create Tenant A
        $this->tenantA = Tenant::create(['id' => $tenantAId]);
        $this->tenantA->domains()->create(['domain' => $this->domainA]);

        // 2. Setup Context in Tenant A
        tenancy()->initialize($this->tenantA);
        setPermissionsTeamId($this->tenantA->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'sanctum']);

        $this->branchA = Branch::create([
            'name' => 'Main Branch A',
            'phone' => '01011111111',
            'is_active' => true,
        ]);

        $this->branchA2 = Branch::create([
            'name' => 'Secondary Branch A2',
            'phone' => '01033333333',
            'is_active' => true,
        ]);

        ClinicSetting::create([
            'branch_id' => $this->branchA->id,
            'clinic_mode' => ClinicMode::POLYCLINIC->value,
            'queue_strategy' => 'hybrid',
            'estimated_time_per_visit' => 15,
        ]);

        $this->doctorA = User::create([
            'name' => 'Dr. Ahmed A',
            'email' => 'doctorA@clinic.test',
            'password' => bcrypt('password'),
        ]);
        $this->doctorA->assignRole('doctor');
        $this->doctorA->branches()->attach($this->branchA->id);

        $this->receptionistA = User::create([
            'name' => 'Receptionist Sara',
            'email' => 'receptionA@clinic.test',
            'password' => bcrypt('password'),
        ]);
        $this->receptionistA->assignRole('receptionist');
        $this->receptionistA->branches()->attach($this->branchA->id);

        $this->consultationServiceA = Service::create([
            'code' => 'CONSULTATION',
            'name' => 'كشف استشاري',
            'default_price' => 300.00,
            'is_active' => true,
        ]);

        tenancy()->end();

        // 3. Create Tenant B for multi-tenancy isolation tests
        $this->tenantB = Tenant::create(['id' => $tenantBId]);
        $this->tenantB->domains()->create(['domain' => $this->domainB]);

        tenancy()->initialize($this->tenantB);
        setPermissionsTeamId($this->tenantB->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'sanctum']);

        $this->branchB = Branch::create([
            'name' => 'Branch B',
            'phone' => '01022222222',
            'is_active' => true,
        ]);

        $this->doctorB = User::create([
            'name' => 'Dr. Khaled B',
            'email' => 'doctorB@clinic.test',
            'password' => bcrypt('password'),
        ]);
        $this->doctorB->assignRole('doctor');
        $this->doctorB->branches()->attach($this->branchB->id);

        tenancy()->end();
    }

    /**
     * Helper to authenticate as user within tenant A.
     */
    protected function actingAsTenantUser(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }

    /**
     * 1. Walk-In Registration:
     * - Queue item created with appointment_id = NULL
     * - ZERO records in appointments table
     * - ZERO records in invoices table
     * - Concurrency: Sequential non-duplicate queue numbers
     */
    public function test_walk_in_registration_creates_queue_without_appointment_or_invoice_and_sequential_queue_numbers(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient = Patient::create([
            'medical_number' => 'MRN-10001',
            'name' => 'Mohamed Walkin',
            'phone' => '01012345678',
        ]);

        $this->actingAsTenantUser($this->receptionistA);

        // 1. First walk-in check-in
        $response1 = $this->postJson("http://{$this->domainA}/api/v1/live-queues/check-in-walkin", [
            'patient_id' => $patient->id,
            'branch_id' => $this->branchA->id,
            'doctor_id' => $this->doctorA->id,
        ]);

        $response1->assertStatus(201);
        $queueItem1 = $response1->json('data');

        $this->assertNull($queueItem1['appointment_id']);
        $this->assertEquals(1, $queueItem1['queue_no']);
        $this->assertEquals(LiveQueueStatus::CHECKED_IN->value, $queueItem1['status']);

        // Assert zero appointments added
        $this->assertEquals(0, Appointment::count(), 'Zero appointments must be created for walk-in check-in');
        // Assert zero invoices added
        $this->assertEquals(0, Invoice::count(), 'Zero invoices must be created at walk-in check-in');

        // 2. Second walk-in check-in for another patient -> asserts sequential non-duplicate queue_no
        $patient2 = Patient::create([
            'medical_number' => 'MRN-10002',
            'name' => 'Hassan Walkin',
            'phone' => '01087654321',
        ]);

        $response2 = $this->postJson("http://{$this->domainA}/api/v1/live-queues/check-in-walkin", [
            'patient_id' => $patient2->id,
            'branch_id' => $this->branchA->id,
            'doctor_id' => $this->doctorA->id,
        ]);

        $response2->assertStatus(201);
        $queueItem2 = $response2->json('data');

        $this->assertNull($queueItem2['appointment_id']);
        $this->assertEquals(2, $queueItem2['queue_no'], 'Queue number must increment sequentially');
        $this->assertEquals(0, Appointment::count());
        $this->assertEquals(0, Invoice::count());
    }

    /**
     * 2. Scheduled Check-In:
     * - Appointment status transitions to checked_in
     * - Queue item created with correct appointment_id
     * - ZERO invoices created at this stage
     */
    public function test_scheduled_appointment_check_in_transitions_status_and_links_queue_with_zero_invoices(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient = Patient::create([
            'medical_number' => 'MRN-10003',
            'name' => 'Sara Scheduled',
            'phone' => '01122334455',
        ]);

        $appointment = Appointment::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'appointment_time' => now()->addHour(),
            'status' => AppointmentStatus::BOOKING->value,
            'type' => 'consultation',
        ]);

        $this->actingAsTenantUser($this->receptionistA);

        $response = $this->postJson("http://{$this->domainA}/api/v1/appointments/{$appointment->id}/check-in");

        $response->assertStatus(200);

        // Assert appointment status transitions to checked_in
        $this->assertEquals(AppointmentStatus::CHECKED_IN->value, $appointment->fresh()->status->value);

        // Assert queue item created and linked
        $queueItem = LiveQueue::where('appointment_id', $appointment->id)->first();
        $this->assertNotNull($queueItem);
        $this->assertEquals(LiveQueueStatus::CHECKED_IN->value, $queueItem->status->value);
        $this->assertEquals($patient->id, $queueItem->patient_id);

        // Assert zero invoices created
        $this->assertEquals(0, Invoice::count(), 'Zero invoices must be created on appointment check-in');
    }

    /**
     * 3. Call Next Patient:
     * - Moves patient to under_examination
     * - Encounter record initialized with status: in_progress and linked to queue_item.encounter_id
     * - Active Encounter Guard: Doctor cannot call a new patient if already has unfinished in_progress encounter
     */
    public function test_call_next_patient_creates_in_progress_encounter_and_enforces_active_encounter_guard(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient1 = Patient::create([
            'medical_number' => 'MRN-10004',
            'name' => 'Patient One',
            'phone' => '01000000001',
        ]);
        $patient2 = Patient::create([
            'medical_number' => 'MRN-10005',
            'name' => 'Patient Two',
            'phone' => '01000000002',
        ]);

        // Create 2 queue items
        $queue1 = LiveQueue::create([
            'patient_id' => $patient1->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'queue_no' => 1,
            'status' => LiveQueueStatus::CHECKED_IN->value,
            'shift_date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);

        $queue2 = LiveQueue::create([
            'patient_id' => $patient2->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'queue_no' => 2,
            'status' => LiveQueueStatus::CHECKED_IN->value,
            'shift_date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);

        $this->actingAsTenantUser($this->doctorA);

        // 1. Doctor calls next patient
        $response1 = $this->postJson("http://{$this->domainA}/api/v1/live-queues/next", [
            'branch_id' => $this->branchA->id,
            'doctor_id' => $this->doctorA->id,
        ]);

        $response1->assertStatus(200);

        // Assert queue item 1 is under_examination
        $queue1->refresh();
        $this->assertEquals(LiveQueueStatus::UNDER_EXAMINATION->value, $queue1->status->value);
        $this->assertNotNull($queue1->encounter_id);

        // Assert encounter created with in_progress status
        $encounter = Encounter::find($queue1->encounter_id);
        $this->assertNotNull($encounter);
        $this->assertEquals(EncounterStatus::IN_PROGRESS->value, $encounter->status->value);
        $this->assertEquals($patient1->id, $encounter->patient_id);
        $this->assertEquals($this->doctorA->id, $encounter->doctor_id);

        // 2. Active Encounter Guard: Doctor attempts to call patient 2 while patient 1 encounter is in_progress
        $response2 = $this->postJson("http://{$this->domainA}/api/v1/live-queues/next", [
            'branch_id' => $this->branchA->id,
            'doctor_id' => $this->doctorA->id,
        ]);

        // Expect 409 Conflict with active encounter message
        $response2->assertStatus(409);
        $this->assertStringContainsString('كشف طبي جاري', $response2->json('message'));

        // Patient 2 queue must still be checked_in
        $this->assertEquals(LiveQueueStatus::CHECKED_IN->value, $queue2->fresh()->status->value);
    }

    /**
     * 4. Session Recovery:
     * - Doctor reconnecting or refreshing browser retrieves active in_progress encounter with full patient context
     */
    public function test_session_recovery_retrieves_active_in_progress_encounter(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient = Patient::create([
            'medical_number' => 'MRN-10006',
            'name' => 'Active Patient',
            'phone' => '01000000003',
        ]);

        $encounter = Encounter::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'type' => 'walk_in',
            'status' => EncounterStatus::IN_PROGRESS->value,
            'chief_complaint' => 'ألم حاد في الأذن',
            'clinical_examination' => 'احتقان طبلة الأذن اليمنى',
            'started_at' => now(),
        ]);

        $this->actingAsTenantUser($this->doctorA);

        $response = $this->getJson("http://{$this->domainA}/api/v1/encounters/active?branch_id={$this->branchA->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotNull($data);
        $this->assertEquals($encounter->id, $data['id']);
        $this->assertEquals(EncounterStatus::IN_PROGRESS->value, $data['status']);
        $this->assertEquals($patient->id, $data['patient']['id']);
        $this->assertEquals('ألم حاد في الأذن', $data['chief_complaint']);
    }

    /**
     * 5. Walk-Away & Abandon Edge Cases:
     * - Reception Cancel (PATCH /api/v1/live-queues/{id}/cancel): cancels checked_in patient, no encounter, no invoice
     * - Doctor Abandon (POST /api/v1/encounters/{id}/abandon): marks encounter cancelled, queue cancelled/no_show, zero invoices
     */
    public function test_reception_cancel_and_doctor_abandon_edge_cases(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient = Patient::create([
            'medical_number' => 'MRN-10007',
            'name' => 'Walkaway Patient',
            'phone' => '01000000004',
        ]);

        // A. Reception Cancel for a checked_in patient
        $queueItem1 = LiveQueue::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'queue_no' => 1,
            'status' => LiveQueueStatus::CHECKED_IN->value,
            'shift_date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);

        $this->actingAsTenantUser($this->receptionistA);

        $responseCancel = $this->patchJson("http://{$this->domainA}/api/v1/live-queues/{$queueItem1->id}/cancel", [
            'reason' => 'Patient had an emergency and left',
        ]);

        $responseCancel->assertStatus(200);
        $this->assertEquals(LiveQueueStatus::CANCELLED->value, $queueItem1->fresh()->status->value);
        $this->assertEquals(0, Encounter::count());
        $this->assertEquals(0, Invoice::count());

        // B. Doctor Abandon when patient is called but absent
        $encounter = Encounter::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'type' => 'walk_in',
            'status' => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $queueItem2 = LiveQueue::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'encounter_id' => $encounter->id,
            'queue_no' => 2,
            'status' => LiveQueueStatus::UNDER_EXAMINATION->value,
            'shift_date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);

        $this->actingAsTenantUser($this->doctorA);

        $responseAbandon = $this->postJson("http://{$this->domainA}/api/v1/encounters/{$encounter->id}/abandon", [
            'reason' => 'Patient called 3 times but absent',
        ]);

        $responseAbandon->assertStatus(200);

        // Assert encounter is cancelled
        $this->assertEquals(EncounterStatus::CANCELLED->value, $encounter->fresh()->status->value);
        // Assert queue item is cancelled
        $this->assertEquals(LiveQueueStatus::CANCELLED->value, $queueItem2->fresh()->status->value);
        // Assert zero invoices created
        $this->assertEquals(0, Invoice::count(), 'Zero invoices must be created on encounter abandon');
    }

    /**
     * 6. Encounter Completion:
     * - Clinical data written to encounters table (NOT appointments)
     * - Prescription created linked to encounter_id
     * - Paid / Zero-Cost Case: Total = 0 -> Invoice auto-marked paid, queue moves to completed
     * - Unpaid / Billed Case: Total > 0 -> Invoice created with status unpaid, queue moves to pending_payment
     */
    public function test_encounter_completion_zero_cost_and_billed_flows(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient = Patient::create([
            'medical_number' => 'MRN-10008',
            'name' => 'Completion Patient',
            'phone' => '01000000005',
        ]);

        // ── Case A: Zero-Cost / Follow-Up Encounter ───────────────────
        $encounterZero = Encounter::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'type' => 'walk_in',
            'status' => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $queueZero = LiveQueue::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'encounter_id' => $encounterZero->id,
            'queue_no' => 1,
            'status' => LiveQueueStatus::UNDER_EXAMINATION->value,
            'shift_date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);

        $this->actingAsTenantUser($this->doctorA);

        $payloadZero = [
            'chief_complaint' => 'متابعة بعد أسبوع',
            'clinical_examination' => 'تحسن كامل في الأعراض',
            'diagnosis' => ['التهاب سابق تم الشفاء منه'],
            'vitals' => ['blood_pressure' => '120/80'],
            'medications' => [
                [
                    'drug_name' => 'مكمل غذائي',
                    'dose' => 'قرص يومياً',
                    'frequency' => 'صباحاً',
                    'duration' => '14 يوم',
                ],
            ],
            // 100% discount or 0 price service -> total = 0
            'discount' => 300.00,
        ];

        $resZero = $this->postJson("http://{$this->domainA}/api/v1/encounters/{$encounterZero->id}/complete", $payloadZero);
        $resZero->assertStatus(200);

        // Assert encounter status completed and clinical notes stored in encounters
        $encounterZero->refresh();
        $this->assertEquals(EncounterStatus::COMPLETED->value, $encounterZero->status->value);
        $this->assertEquals('متابعة بعد أسبوع', $encounterZero->chief_complaint);
        $this->assertEquals('تحسن كامل في الأعراض', $encounterZero->clinical_examination);

        // Assert prescription linked to encounter_id
        $this->assertDatabaseHas('prescriptions', [
            'encounter_id' => $encounterZero->id,
            'patient_id' => $patient->id,
        ]);

        // Assert invoice is auto-marked PAID and queue is COMPLETED
        $queueZero->refresh();
        $this->assertEquals(LiveQueueStatus::COMPLETED->value, $queueZero->status->value);

        $invoiceZero = Invoice::where('encounter_id', $encounterZero->id)->first();
        $this->assertNotNull($invoiceZero);
        $this->assertEquals(PaymentStatus::PAID->value, $invoiceZero->payment_status->value);

        // ── Case B: Billed Encounter (Total > 0) ──────────────────────
        $encounterBilled = Encounter::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'type' => 'walk_in',
            'status' => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $queueBilled = LiveQueue::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'encounter_id' => $encounterBilled->id,
            'queue_no' => 2,
            'status' => LiveQueueStatus::UNDER_EXAMINATION->value,
            'shift_date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);

        $payloadBilled = [
            'chief_complaint' => 'ارتفاع في درجة الحرارة',
            'clinical_examination' => 'التهاب بالحلق',
            'diagnosis' => ['Acute Pharyngitis'],
            'vitals' => ['temperature' => '38.5'],
            'medications' => [],
            'discount' => 0.00, // Total = 300.00
        ];

        $resBilled = $this->postJson("http://{$this->domainA}/api/v1/encounters/{$encounterBilled->id}/complete", $payloadBilled);
        $resBilled->assertStatus(200);

        // Assert queue moves to PENDING_PAYMENT
        $queueBilled->refresh();
        $this->assertEquals(LiveQueueStatus::PENDING_PAYMENT->value, $queueBilled->status->value);

        // Assert invoice created with status UNPAID
        $invoiceBilled = Invoice::where('encounter_id', $encounterBilled->id)->first();
        $this->assertNotNull($invoiceBilled);
        $this->assertEquals(PaymentStatus::UNPAID->value, $invoiceBilled->payment_status->value);
        $this->assertEquals(300.00, (float) $invoiceBilled->total);
    }

    /**
     * 7. Settlement & Checkout:
     * - Payment recorded via POST /api/v1/invoices/{id}/pay
     * - Invoice transitions to paid
     * - Associated queue item moves to completed
     */
    public function test_settlement_and_checkout_transitions_invoice_and_queue_to_completed(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient = Patient::create([
            'medical_number' => 'MRN-10009',
            'name' => 'Checkout Patient',
            'phone' => '01000000006',
        ]);

        $encounter = Encounter::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'type' => 'walk_in',
            'status' => EncounterStatus::COMPLETED->value,
            'completed_at' => now(),
        ]);

        $queue = LiveQueue::create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'encounter_id' => $encounter->id,
            'queue_no' => 1,
            'status' => LiveQueueStatus::PENDING_PAYMENT->value,
            'shift_date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-TEST-001',
            'branch_id' => $this->branchA->id,
            'patient_id' => $patient->id,
            'encounter_id' => $encounter->id,
            'subtotal' => 300.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'total' => 300.00,
            'paid_amount' => 0.00,
            'remaining_balance' => 300.00,
            'payment_status' => PaymentStatus::UNPAID->value,
            'issued_at' => now(),
        ]);

        $this->actingAsTenantUser($this->receptionistA);

        $payResponse = $this->postJson("http://{$this->domainA}/api/v1/invoices/{$invoice->id}/pay", [
            'payments' => [
                ['method' => 'cash', 'amount' => 300.00],
            ],
        ]);

        $payResponse->assertStatus(200);

        // Assert invoice transitions to PAID
        $invoice->refresh();
        $this->assertEquals(PaymentStatus::PAID->value, $invoice->payment_status->value);
        $this->assertEquals(300.00, (float) $invoice->payments()->sum('amount'));

        // Assert queue item transitions to COMPLETED
        $queue->refresh();
        $this->assertEquals(LiveQueueStatus::COMPLETED->value, $queue->status->value);
    }

    /**
     * 8. Security, IDOR & Multi-Tenancy Tests:
     * - Cross-Tenant Isolation: Tenant A cannot access or mutate Tenant B records
     * - RBAC: Receptionist gets 403 attempting to complete encounters
     * - Doctor gets 403 attempting to process billing payments
     */
    public function test_cross_tenant_isolation_and_rbac_prohibitions(): void
    {
        // 🔒 A. RBAC: Doctor gets 403 attempting to process invoice payment
        tenancy()->initialize($this->tenantA);

        $patientA = Patient::create([
            'medical_number' => 'MRN-10010',
            'name' => 'RBAC Patient',
            'phone' => '01000000007',
        ]);

        $invoiceA = Invoice::create([
            'invoice_number' => 'INV-RBAC-001',
            'branch_id' => $this->branchA->id,
            'patient_id' => $patientA->id,
            'subtotal' => 100.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'total' => 100.00,
            'paid_amount' => 0.00,
            'remaining_balance' => 100.00,
            'payment_status' => PaymentStatus::UNPAID->value,
            'issued_at' => now(),
        ]);

        $this->actingAsTenantUser($this->doctorA);

        $doctorPayRes = $this->postJson("http://{$this->domainA}/api/v1/invoices/{$invoiceA->id}/pay", [
            'payments' => [
                ['method' => 'cash', 'amount' => 100.00],
            ],
        ]);

        // Must be 403 Forbidden for doctor with standardized error envelope
        $doctorPayRes->assertStatus(403);
        $doctorPayRes->assertJsonFragment(['success' => false, 'error_code' => 'FORBIDDEN']);

        // 🔒 B. RBAC: Doctor gets 403 attempting to register a walk-in check-in (Reception task)
        $doctorCheckinRes = $this->postJson("http://{$this->domainA}/api/v1/live-queues/check-in-walkin", [
            'name' => 'WalkIn Test',
            'phone' => '01099998888',
            'branch_id' => $this->branchA->id,
        ]);
        $doctorCheckinRes->assertStatus(403);
        $doctorCheckinRes->assertJsonFragment(['success' => false, 'error_code' => 'FORBIDDEN']);

        // 🔒 C. RBAC: Receptionist gets 403 attempting to complete an encounter
        $encounterA = Encounter::create([
            'patient_id' => $patientA->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branchA->id,
            'type' => 'walk_in',
            'status' => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $this->actingAsTenantUser($this->receptionistA);

        $receptionistCompleteRes = $this->postJson("http://{$this->domainA}/api/v1/encounters/{$encounterA->id}/complete", [
            'chief_complaint' => 'محاولة غير مصرح بها',
        ]);

        // Must be 403 Forbidden for receptionist
        $receptionistCompleteRes->assertStatus(403);
        $receptionistCompleteRes->assertJsonFragment(['success' => false, 'error_code' => 'FORBIDDEN']);

        // 🔒 D. RBAC: Receptionist gets 403 attempting to call next patient
        $receptionistNextRes = $this->postJson("http://{$this->domainA}/api/v1/live-queues/next", [
            'branch_id' => $this->branchA->id,
        ]);
        $receptionistNextRes->assertStatus(403);
        $receptionistNextRes->assertJsonFragment(['success' => false, 'error_code' => 'FORBIDDEN']);

        // 🔒 E. Branch IDOR Protection: User assigned to Branch A attempting action on Branch A2 gets 403
        $idorRes = $this->withHeader('X-Branch-ID', $this->branchA2->id)
            ->postJson("http://{$this->domainA}/api/v1/live-queues/check-in-walkin", [
                'name' => 'IDOR Patient',
                'phone' => '01077776666',
                'branch_id' => $this->branchA2->id,
            ]);

        $idorRes->assertStatus(403);
        $idorRes->assertJsonFragment(['success' => false, 'error_code' => 'BRANCH_ACCESS_DENIED']);

        tenancy()->end();

        // 🔒 F. Cross-Tenant Isolation: Tenant B user cannot access Tenant A records
        $this->flushHeaders();
        tenancy()->initialize($this->tenantB);

        $this->actingAsTenantUser($this->doctorB);

        // Doctor B hitting Tenant B domain with Tenant A encounter ID -> 404 (does not exist in Tenant B DB)
        $crossRes = $this->getJson("http://{$this->domainB}/api/v1/encounters/{$encounterA->id}");
        $crossRes->assertStatus(404);

        tenancy()->end();
    }
}

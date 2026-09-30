<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\ClinicSetting;
use App\Models\Service;
use App\Models\BranchService;
use App\Models\LiveQueue;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\Invoice;
use App\Models\Payment;
use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Enums\ClinicMode;
use App\Enums\LiveQueueStatus;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PolyclinicPatientJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch1;
    protected Branch $branch2;
    protected User $doctor;
    protected User $receptionist1;
    protected Service $consultService;
    protected Patient $patient;
    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'poly-' . Str::random(6);
        $this->domain = $tenantId . '.test';

        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($this->tenant->id);
        }

        $ownerRole = Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
        $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        $receptionRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        $receptionRole->syncPermissions([
            Permission::firstOrCreate(['name' => 'appointments.create', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'appointments.view', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'appointments.edit', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'patients.create', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'patients.view', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'live_queue.view', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'live_queue.manage', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'invoices.create', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'payments.create', 'guard_name' => 'web']),
        ]);

        $doctorRole->syncPermissions([
            Permission::firstOrCreate(['name' => 'appointments.view', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'patients.view', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'prescriptions.create', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'prescriptions.view', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'live_queue.view', 'guard_name' => 'web']),
            Permission::firstOrCreate(['name' => 'live_queue.manage', 'guard_name' => 'web']),
        ]);

        $this->branch1 = Branch::create([
            'name'      => 'Branch 1 - فرع المعادي',
            'address'   => 'المعادي - شارع النصر',
            'phone'     => '01011111111',
            'is_active' => true,
        ]);

        $this->branch2 = Branch::create([
            'name'      => 'Branch 2 - فرع مدينة نصر',
            'address'   => 'مدينة نصر - مكرم عبيد',
            'phone'     => '01022222222',
            'is_active' => true,
        ]);

        ClinicSetting::create([
            'branch_id'                => $this->branch1->id,
            'clinic_mode'              => ClinicMode::POLYCLINIC->value,
            'queue_strategy'           => 'hybrid',
            'avg_appointment_duration' => 15,
        ]);

        ClinicSetting::create([
            'branch_id'                => $this->branch2->id,
            'clinic_mode'              => ClinicMode::POLYCLINIC->value,
            'queue_strategy'           => 'hybrid',
            'avg_appointment_duration' => 15,
        ]);

        $this->consultService = Service::firstOrCreate(
            ['code' => 'GEN-01'],
            ['name' => 'كشف باطنة عام', 'default_price' => 250.00, 'is_active' => true]
        );
        $this->consultService->update([
            'default_price' => 250.00,
        ]);

        BranchService::updateOrCreate(
            ['branch_id' => $this->branch1->id, 'service_id' => $this->consultService->id],
            ['price' => 250.00, 'is_available' => true]
        );

        // Cross-branch doctor
        $this->doctor = User::create([
            'name'     => 'د. أحمد علي',
            'email'    => 'dr.ahmed@clinica.test',
            'password' => bcrypt('12345678'),
        ]);
        $this->doctor->assignRole('doctor');
        $this->doctor->branches()->attach([$this->branch1->id, $this->branch2->id]);

        // Dedicated Receptionist for Branch 1
        $this->receptionist1 = User::create([
            'name'     => 'سارة - استقبال المعادي',
            'email'    => 'reception.branch1@clinica.test',
            'password' => bcrypt('12345678'),
        ]);
        $this->receptionist1->assignRole('receptionist');
        $this->receptionist1->branches()->attach($this->branch1->id);

        $this->patient = Patient::create([
            'medical_number' => 'MRN-POLY-001',
            'name'           => 'خالد منصور',
            'phone'          => '01012344321',
            'age'            => 29,
            'gender'         => 'male',
        ]);
    }

    /**
     * Test full end-to-end Polyclinic Scheduled Visit lifecycle.
     */
    public function test_complete_polyclinic_scheduled_visit_patient_journey(): void
    {
        // ── Step 1: Receptionist books appointment ───────────────────────
        $apptTime = now()->addHour()->format('Y-m-d H:i:s');
        $bookingRes = $this->actingAs($this->receptionist1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/appointments", [
                'branch_id'        => $this->branch1->id,
                'doctor_id'        => $this->doctor->id,
                'patient_id'       => $this->patient->id,
                'appointment_time' => $apptTime,
                'type'             => AppointmentType::CHECK_UP->value,
                'status'           => AppointmentStatus::BOOKING->value,
            ]);

        $bookingRes->assertStatus(201)
            ->assertJsonPath('status', 'success');

        $appointmentId = $bookingRes->json('data.id');
        $this->assertNotNull($appointmentId);

        $this->assertDatabaseHas('appointments', [
            'id'        => $appointmentId,
            'branch_id' => $this->branch1->id,
            'doctor_id' => $this->doctor->id,
            'status'    => AppointmentStatus::BOOKING->value,
        ]);

        // ── Step 2: Patient arrives -> Receptionist checks in patient into live_queues
        $checkInRes = $this->actingAs($this->receptionist1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/appointments/{$appointmentId}/check-in");

        $checkInRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $this->assertDatabaseHas('appointments', [
            'id'     => $appointmentId,
            'status' => AppointmentStatus::CHECKED_IN->value,
        ]);

        $queueItem = LiveQueue::where('appointment_id', $appointmentId)->first();
        $this->assertNotNull($queueItem);
        $this->assertEquals($this->branch1->id, $queueItem->branch_id);
        $this->assertEquals($this->patient->id, $queueItem->patient_id);
        $this->assertContains($queueItem->status->value, [
            LiveQueueStatus::CHECKED_IN->value,
            LiveQueueStatus::WAITING->value,
        ]);

        // Assert invoice auto-created with consultation fee snapshot (250 EGP)
        $invoice = Invoice::where('appointment_id', $appointmentId)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(250.00, (float)$invoice->total);
        $this->assertEquals(PaymentStatus::UNPAID->value, $invoice->payment_status->value ?? $invoice->payment_status);

        // ── Step 3: Doctor calls next patient -> starts encounter linked to appointment
        $callRes = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/live-queues/next", [
                'branch_id' => $this->branch1->id,
                'room_name' => 'Examination Room 1',
            ]);

        $callRes->assertStatus(200)
            ->assertJsonPath('status', 'success');

        $queueItem->refresh();
        $this->assertEquals(LiveQueueStatus::UNDER_EXAMINATION->value, $queueItem->status->value);

        // Doctor initiates encounter linked to appointment_id
        $quickStartRes = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/quick-start", [
                'branch_id'       => $this->branch1->id,
                'patient_id'      => $this->patient->id,
                'appointment_id'  => $appointmentId,
                'type'            => EncounterType::CHECK_UP->value,
                'chief_complaint' => 'Sore throat, fever, and difficulty swallowing',
            ]);

        $quickStartRes->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.status', EncounterStatus::IN_PROGRESS->value)
            ->assertJsonPath('data.appointment_id', $appointmentId);

        $encounterId = $quickStartRes->json('data.id');

        // ── Step 4: Doctor completes clinical exam and issues prescription
        // (Separated billing desk: doctor does NOT pass payments in polyclinic mode)
        $completeRes = $this->actingAs($this->doctor, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/encounters/{$encounterId}/complete", [
                'diagnosis'      => ['Acute Bacterial Pharyngitis'],
                'medications'    => [
                    [
                        'drug_name'   => 'Augmentin 1g',
                        'dose'        => '1 tablet',
                        'frequency'   => 'Every 12 hours',
                        'duration'    => '7 days',
                        'instruction' => 'After food',
                    ],
                ],
                'general_advice' => 'Rest, drink warm fluids, and isolate for 2 days.',
                'follow_up_date' => now()->addDays(7)->toDateString(),
            ]);

        $completeRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.encounter.status', EncounterStatus::COMPLETED->value);

        $encounter = Encounter::findOrFail($encounterId);
        $this->assertEquals(EncounterStatus::COMPLETED->value, $encounter->status->value);

        $prescription = Prescription::where('encounter_id', $encounterId)->first();
        $this->assertNotNull($prescription);
        $this->assertCount(1, $prescription->items);
        $this->assertEquals('Augmentin 1g', $prescription->items[0]->drug_name);

        // ── Step 5: Cashier/Receptionist bills the patient and marks invoice paid
        $invoice = Invoice::where('appointment_id', $appointmentId)->first();
        $this->assertNotNull($invoice);

        $payRes = $this->actingAs($this->receptionist1, 'sanctum')
            ->postJson("http://{$this->domain}/api/v1/invoices/{$invoice->id}/pay", [
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => (float)$invoice->total,
                    ],
                ],
            ]);

        $payRes->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID->value);

        // ── Step 6: Verify queue item, appointment, and payment status ──
        $invoice->refresh();
        $this->assertEquals(PaymentStatus::PAID->value, $invoice->payment_status->value ?? $invoice->payment_status);

        $payment = Payment::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($payment);
        $this->assertEquals(250.00, (float)$payment->amount);
        $this->assertEquals($this->receptionist1->id, $payment->cashier_id);

        $appointment = Appointment::findOrFail($appointmentId);
        $this->assertEquals(AppointmentStatus::COMPLETED->value, $appointment->status->value);

        $queueItem->refresh();
        $this->assertEquals(LiveQueueStatus::COMPLETED->value, $queueItem->status->value);
    }
}

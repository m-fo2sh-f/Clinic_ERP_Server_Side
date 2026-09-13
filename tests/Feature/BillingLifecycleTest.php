<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\LiveQueue;
use App\Models\Service;
use App\Models\BranchService;
use App\Models\Invoice;
use App\Enums\LiveQueueStatus;
use App\Enums\AppointmentStatus;
use App\Enums\PaymentStatus;
use App\Events\InvoiceReadyForPayment;
use App\Events\InvoicePaid;
use App\Services\Clinic\AppointmentService;
use App\Services\Clinic\BillingService;
use App\Services\Clinic\ConsultationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class BillingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $doctor;
    protected User $receptionist;
    protected Patient $patient;
    protected Service $consultationService;
    protected Service $ecgService;
    protected BillingService $billingService;
    protected AppointmentService $appointmentService;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'clinic-lifecycle-' . Str::random(6);

        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $tenantId . '.test']);
        tenancy()->initialize($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->doctor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email'     => 'dr@lifecycle.test',
        ]);
        $this->doctor->assignRole('doctor');
        $this->doctor->branches()->attach($this->branch->id);

        $this->receptionist = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email'     => 'rx@lifecycle.test',
        ]);
        $this->receptionist->assignRole('receptionist');
        $this->receptionist->branches()->attach($this->branch->id);

        $this->patient = Patient::create([
            'tenant_id'      => $this->tenant->id,
            'name'           => 'مريض تجربة الدورة',
            'phone'          => '01011112222',
            'medical_number' => 'MRN-LC-001',
            'age'            => 30,
            'gender'         => 'male',
        ]);

        $this->consultationService = Service::create([
            'tenant_id'     => $this->tenant->id,
            'name'          => 'كشف استشاري',
            'code'          => 'CONSULTATION',
            'default_price' => 150.00,
            'is_active'     => true,
        ]);

        BranchService::create([
            'tenant_id'    => $this->tenant->id,
            'branch_id'    => $this->branch->id,
            'service_id'   => $this->consultationService->id,
            'price'        => 150.00,
            'is_available' => true,
        ]);

        $this->ecgService = Service::create([
            'tenant_id'     => $this->tenant->id,
            'name'          => 'رسم قلب (ECG)',
            'code'          => 'ECG',
            'default_price' => 100.00,
            'is_active'     => true,
        ]);

        BranchService::create([
            'tenant_id'    => $this->tenant->id,
            'branch_id'    => $this->branch->id,
            'service_id'   => $this->ecgService->id,
            'price'        => 100.00,
            'is_available' => true,
        ]);

        $this->billingService     = app(BillingService::class);
        $this->appointmentService = app(AppointmentService::class);
    }

    // =========================================================================
    // HELPER: Create a checked-in appointment with invoice
    // =========================================================================

    private function createCheckedInAppointment(): array
    {
        $appointment = Appointment::create([
            'tenant_id'        => $this->tenant->id,
            'branch_id'        => $this->branch->id,
            'patient_id'       => $this->patient->id,
            'doctor_id'        => $this->doctor->id,
            'appointment_time' => now(),
            'type'             => 'check_up',
            'status'           => AppointmentStatus::CHECKED_IN->value,
        ]);

        $invoice = $this->billingService->createInvoiceForAppointment($appointment);

        $queueItem = LiveQueue::create([
            'tenant_id'      => $this->tenant->id,
            'branch_id'      => $this->branch->id,
            'appointment_id' => $appointment->id,
            'patient_id'     => $this->patient->id,
            'doctor_id'      => $this->doctor->id,
            'status'         => LiveQueueStatus::WAITING->value,
            'queue_no'       => 1,
            'shift_date'     => now()->toDateString(),
            'checked_in_at'  => now(),
        ]);

        return [$appointment, $invoice, $queueItem];
    }

    // =========================================================================
    // TEST 1: Check-in creates invoice but it does NOT appear in pending list
    // =========================================================================

    /** @test */
    public function test_checked_in_patient_invoice_is_not_in_pending_list(): void
    {
        [$appointment, $invoice, $queueItem] = $this->createCheckedInAppointment();

        // Assert invoice was created
        $this->assertNotNull($invoice->id);
        $this->assertEquals(PaymentStatus::UNPAID->value, $invoice->payment_status->value ?? $invoice->payment_status);

        // Assert appointment is still checked_in
        $this->assertEquals(AppointmentStatus::CHECKED_IN->value, $appointment->fresh()->status->value ?? $appointment->fresh()->status);

        // Assert invoice does NOT appear in pending invoices
        $pending = $this->billingService->getPendingInvoices($this->branch->id);
        $this->assertCount(0, $pending, 'Invoice should NOT appear in pending list while patient is checked_in');
    }

    // =========================================================================
    // TEST 2: Adding extra service does NOT push invoice to pending list
    // =========================================================================

    /** @test */
    public function test_adding_extra_service_does_not_push_to_pending_list(): void
    {
        Event::fake([InvoiceReadyForPayment::class]);

        [$appointment, $invoice, $queueItem] = $this->createCheckedInAppointment();

        // Add an extra ECG service
        $updatedInvoice = $this->billingService->addExtraService(
            $invoice->id,
            $this->ecgService->id,
            1
        );

        // Assert total was updated (150 consultation + 100 ECG = 250)
        $this->assertEquals(250.00, (float) $updatedInvoice->total);
        $this->assertCount(2, $updatedInvoice->items);

        // Assert invoice still does NOT appear in pending invoices
        $pending = $this->billingService->getPendingInvoices($this->branch->id);
        $this->assertCount(0, $pending, 'Invoice should NOT appear in pending list after adding service while patient is in queue');

        // Assert event was NOT dispatched (because appointment is still checked_in)
        Event::assertNotDispatched(InvoiceReadyForPayment::class);
    }

    // =========================================================================
    // TEST 3: Completing consultation moves to pending_payment and broadcasts
    // =========================================================================

    /** @test */
    public function test_completing_consultation_moves_to_pending_payment_and_appears_in_pending_list(): void
    {
        Event::fake([InvoiceReadyForPayment::class]);

        [$appointment, $invoice, $queueItem] = $this->createCheckedInAppointment();

        // Move appointment to under_examination (as would happen when doctor calls next)
        $appointment->update(['status' => AppointmentStatus::UNDER_EXAMINATION->value]);
        $queueItem->update(['status' => LiveQueueStatus::UNDER_EXAMINATION->value]);

        // Still should NOT be in pending list
        $pending = $this->billingService->getPendingInvoices($this->branch->id);
        $this->assertCount(0, $pending, 'Invoice should NOT appear in pending list during examination');

        // Complete consultation (simulates doctor completing the examination)
        $consultationService = app(ConsultationService::class);

        $this->actingAs($this->doctor);

        $consultationService->completeConsultation([
            'live_queue_id'        => $queueItem->id,
            'appointment_id'       => $appointment->id,
            'patient_id'           => $this->patient->id,
            'chief_complaint'      => 'ألم في الصدر',
            'diagnoses'            => [['code' => 'I20', 'name' => 'Angina Pectoris']],
            'examination_findings' => 'Normal heart sounds, no murmurs',
            'vitals'               => ['bp' => '120/80', 'pulse' => '72', 'temp' => '37.0'],
            'medications'          => [
                [
                    'name'      => 'Aspirin',
                    'dosage'    => '100mg',
                    'frequency' => 'Once daily',
                    'duration'  => '30 days',
                ],
            ],
        ]);

        // Assert appointment is now pending_payment
        $freshAppointment = $appointment->fresh();
        $appointmentStatus = $freshAppointment->status instanceof AppointmentStatus
            ? $freshAppointment->status->value
            : $freshAppointment->status;
        $this->assertEquals(
            AppointmentStatus::PENDING_PAYMENT->value,
            $appointmentStatus,
            'Appointment should be in pending_payment after consultation completion'
        );

        // Assert invoice NOW appears in pending list
        $pending = $this->billingService->getPendingInvoices($this->branch->id);
        $this->assertCount(1, $pending, 'Invoice should NOW appear in pending list after consultation completion');
        $this->assertEquals($invoice->id, $pending->first()->id);

        // Assert InvoiceReadyForPayment event was dispatched
        Event::assertDispatched(InvoiceReadyForPayment::class);
    }

    // =========================================================================
    // TEST 4: Paying invoice moves appointment to completed and removes from pending
    // =========================================================================

    /** @test */
    public function test_paying_invoice_completes_appointment_and_removes_from_pending(): void
    {
        Event::fake([InvoiceReadyForPayment::class, InvoicePaid::class]);

        [$appointment, $invoice, $queueItem] = $this->createCheckedInAppointment();

        // Fast-forward to pending_payment state
        $appointment->update(['status' => AppointmentStatus::PENDING_PAYMENT->value]);

        // Verify it appears in pending
        $pending = $this->billingService->getPendingInvoices($this->branch->id);
        $this->assertCount(1, $pending, 'Invoice should appear in pending list when appointment is pending_payment');

        // Process payment
        $this->actingAs($this->receptionist);

        $paidInvoice = $this->billingService->processPayment(
            $invoice->id,
            [['method' => 'cash', 'amount' => (float) $invoice->total]],
            $this->receptionist->id
        );

        // Assert invoice is now paid
        $paidStatus = $paidInvoice->payment_status instanceof PaymentStatus
            ? $paidInvoice->payment_status->value
            : $paidInvoice->payment_status;
        $this->assertEquals(PaymentStatus::PAID->value, $paidStatus);

        // Assert appointment moved to completed
        $freshAppointment = $appointment->fresh();
        $completedStatus = $freshAppointment->status instanceof AppointmentStatus
            ? $freshAppointment->status->value
            : $freshAppointment->status;
        $this->assertEquals(
            AppointmentStatus::COMPLETED->value,
            $completedStatus,
            'Appointment should be completed after payment'
        );

        // Assert invoice is removed from pending list
        $pending = $this->billingService->getPendingInvoices($this->branch->id);
        $this->assertCount(0, $pending, 'Invoice should be removed from pending list after payment');

        // Assert InvoicePaid event was dispatched
        Event::assertDispatched(InvoicePaid::class);
    }

    // =========================================================================
    // TEST 5: Under-examination invoices do NOT appear in pending list
    // =========================================================================

    /** @test */
    public function test_under_examination_invoice_not_in_pending_list(): void
    {
        [$appointment, $invoice, $queueItem] = $this->createCheckedInAppointment();

        // Move to under_examination
        $appointment->update(['status' => AppointmentStatus::UNDER_EXAMINATION->value]);

        $pending = $this->billingService->getPendingInvoices($this->branch->id);
        $this->assertCount(0, $pending, 'Invoice should NOT appear in pending list during examination');
    }

    // =========================================================================
    // TEST 6: Adding extra service AFTER pending_payment DOES broadcast
    // =========================================================================

    /** @test */
    public function test_adding_extra_service_after_pending_payment_broadcasts_update(): void
    {
        Event::fake([InvoiceReadyForPayment::class]);

        [$appointment, $invoice, $queueItem] = $this->createCheckedInAppointment();

        // Move to pending_payment
        $appointment->update(['status' => AppointmentStatus::PENDING_PAYMENT->value]);

        // Add ECG service while in pending_payment
        $updatedInvoice = $this->billingService->addExtraService(
            $invoice->id,
            $this->ecgService->id,
            1
        );

        // Assert total was updated
        $this->assertEquals(250.00, (float) $updatedInvoice->total);

        // Assert InvoiceReadyForPayment WAS dispatched (because appointment is pending_payment)
        Event::assertDispatched(InvoiceReadyForPayment::class);

        // Assert invoice appears in pending list
        $pending = $this->billingService->getPendingInvoices($this->branch->id);
        $this->assertCount(1, $pending);
    }
}

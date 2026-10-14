<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Enums\LiveQueueStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Events\InvoicePaid;
use App\Events\InvoiceReadyForPayment;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Encounter;
use App\Models\LiveQueue;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clinic\AppointmentService;
use App\Services\Clinic\BillingService;
use App\Services\Clinic\EncounterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FinancialModuleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Branch $branch;

    protected User $doctor;

    protected User $receptionist;

    protected Patient $patient;

    protected Service $consultationService;

    protected Service $ecgService;

    protected string $doctorToken;

    protected string $receptionToken;

    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'clinic-fin-'.Str::random(6);

        // 1. Setup Tenant and Domain
        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->domain = $tenantId.'.test';
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'sanctum']);

        $this->branch = Branch::factory()->create();

        $this->doctor = User::factory()->create([
            'email' => 'doctor@'.$this->domain,
        ]);
        $this->doctor->assignRole(['doctor', 'clinic_owner']);
        $this->doctor->branches()->attach($this->branch->id);

        $this->receptionist = User::factory()->create([
            'email' => 'reception@'.$this->domain,
        ]);
        $this->receptionist->assignRole('receptionist');
        $this->receptionist->branches()->attach($this->branch->id);

        $this->patient = Patient::create([
            'name' => 'أحمد محمود',
            'phone' => '01099887766',
            'medical_number' => 'MRN-778899',
            'age' => 35,
            'gender' => 'male',
        ]);

        // Create standard services
        $this->consultationService = Service::create([
            'name' => 'كشف استشاري',
            'code' => 'CONSULTATION',
            'default_price' => 150.00,
            'is_active' => true,
        ]);

        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_id' => $this->consultationService->id,
            'price' => 150.00,
            'is_available' => true,
        ]);

        $this->ecgService = Service::create([
            'name' => 'رسم قلب (ECG)',
            'code' => 'ECG',
            'default_price' => 100.00,
            'is_active' => true,
        ]);

        BranchService::create([
            'branch_id' => $this->branch->id,
            'service_id' => $this->ecgService->id,
            'price' => 100.00,
            'is_available' => true,
        ]);

        $this->doctorToken = $this->doctor->createToken('DoctorTestToken')->plainTextToken;
        $this->receptionToken = $this->receptionist->createToken('ReceptionTestToken')->plainTextToken;
    }

    public function test_checkin_automatically_creates_unpaid_invoice_with_consultation_fee_snapshot(): void
    {
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::BOOKING->value,
        ]);

        $appointmentService = app(AppointmentService::class);
        $appointmentService->checkInAppointment($appointment->id);

        $billingService = app(BillingService::class);
        $invoice = $billingService->createInvoiceForAppointment($appointment);

        $this->assertNotNull($invoice);
        $this->assertEquals(PaymentStatus::UNPAID, $invoice->payment_status);
        $this->assertEquals('150.00', $invoice->total);
        $this->assertEquals($this->patient->id, $invoice->patient_id);
        $this->assertEquals($this->branch->id, $invoice->branch_id);

        $items = $invoice->items;
        $this->assertCount(1, $items);
        $this->assertEquals('كشف استشاري', $items[0]->item_name);
        $this->assertEquals('150.00', $items[0]->unit_price);
        $this->assertEquals(1, $items[0]->quantity);
        $this->assertEquals('150.00', $items[0]->total);
    }

    public function test_doctor_can_add_extra_services_to_invoice_with_price_snapshotting(): void
    {
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::CHECKED_IN->value,
        ]);

        $billingService = app(BillingService::class);
        $invoice = $billingService->createInvoiceForAppointment($appointment);

        // Add ECG via API
        $response = $this->withHeader('Authorization', 'Bearer '.$this->doctorToken)
            ->postJson("http://{$this->domain}/api/v1/invoices/{$invoice->id}/items", [
                'service_id' => $this->ecgService->id,
                'quantity' => 1,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $invoice->refresh();
        $this->assertEquals('250.00', $invoice->total); // 150 consultation + 100 ECG
        $this->assertCount(2, $invoice->items);

        $ecgItem = $invoice->items->firstWhere('service_id', $this->ecgService->id);
        $this->assertNotNull($ecgItem);
        $this->assertEquals('رسم قلب (ECG)', $ecgItem->item_name);
        $this->assertEquals('100.00', $ecgItem->unit_price);
    }

    public function test_catalog_price_changes_do_not_alter_existing_invoice_item_snapshots(): void
    {
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::CHECKED_IN->value,
        ]);

        $billingService = app(BillingService::class);
        $invoice = $billingService->createInvoiceForAppointment($appointment);
        $billingService->addExtraService($invoice->id, $this->ecgService->id, 1);

        // Change catalog price of ECG to 500
        $this->ecgService->update(['default_price' => 500.00]);
        BranchService::where('service_id', $this->ecgService->id)->update(['price' => 500.00]);

        $invoice->refresh();
        $ecgItem = $invoice->items->firstWhere('service_id', $this->ecgService->id);

        // Snapshot remains 100.00
        $this->assertEquals('100.00', $ecgItem->unit_price);
        $this->assertEquals('250.00', $invoice->total);
    }

    public function test_completing_consultation_transitions_appointment_to_pending_payment_and_broadcasts_event(): void
    {
        Event::fake([InvoiceReadyForPayment::class]);

        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::UNDER_EXAMINATION->value,
        ]);

        $queue = LiveQueue::create([
            'branch_id' => $this->branch->id,
            'appointment_id' => $appointment->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'queue_no' => 1,
            'status' => LiveQueueStatus::UNDER_EXAMINATION,
            'shift_date' => now()->toDateString(),
            'checked_in_at' => now(),
        ]);

        $encounter = Encounter::create([
            'patient_id' => $this->patient->id,
            'branch_id' => $this->branch->id,
            'doctor_id' => $this->doctor->id,
            'appointment_id' => $appointment->id,
            'type' => EncounterType::SCHEDULED,
            'status' => EncounterStatus::IN_PROGRESS,
            'started_at' => now(),
        ]);
        $queue->update(['encounter_id' => $encounter->id]);

        $encounterService = app(EncounterService::class);
        $encounterService->completeEncounter($encounter->id, [
            'chief_complaint' => 'حمى وصداع شديد',
            'diagnosis' => ['التهاب حاد'],
            'clinical_examination' => 'احتقان بالحلق',
            'vitals' => ['temperature' => '38.5'],
            'medications' => [],
        ], $this->doctor->id);

        $appointment->refresh();
        $this->assertEquals(AppointmentStatus::PENDING_PAYMENT, $appointment->status);

        Event::assertDispatched(InvoiceReadyForPayment::class, function ($event) use ($appointment) {
            return $event->invoiceData['appointment_id'] === (string) $appointment->id;
        });
    }

    public function test_reception_can_process_split_payment_cash_plus_visa_atomically(): void
    {
        Event::fake([InvoicePaid::class]);

        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::PENDING_PAYMENT->value,
        ]);

        $billingService = app(BillingService::class);
        $invoice = $billingService->createInvoiceForAppointment($appointment);
        $billingService->addExtraService($invoice->id, $this->ecgService->id, 1);
        // Total is 250.00

        // Process Split Payment: 100 Cash + 150 Visa
        $response = $this->withHeader('Authorization', 'Bearer '.$this->receptionToken)
            ->postJson("http://{$this->domain}/api/v1/invoices/{$invoice->id}/pay", [
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => 100.00,
                    ],
                    [
                        'method' => 'visa',
                        'amount' => 150.00,
                        'transaction_reference' => 'TXN-VISA-998811',
                    ],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');

        $invoice->refresh();
        $this->assertEquals(PaymentStatus::PAID, $invoice->payment_status);
        $this->assertNotNull($invoice->paid_at);

        $appointment->refresh();
        $this->assertEquals(AppointmentStatus::COMPLETED, $appointment->status);

        $payments = Payment::where('invoice_id', $invoice->id)->get();
        $this->assertCount(2, $payments);
        $this->assertEquals('100.00', $payments->firstWhere('payment_method', PaymentMethod::CASH)->amount);
        $this->assertEquals('150.00', $payments->firstWhere('payment_method', PaymentMethod::VISA)->amount);

        Event::assertDispatched(InvoicePaid::class, function ($event) use ($invoice) {
            return $event->paymentData['invoice_id'] === (string) $invoice->id;
        });
    }

    public function test_cannot_pay_mismatched_amount(): void
    {
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::PENDING_PAYMENT->value,
        ]);

        $billingService = app(BillingService::class);
        $invoice = $billingService->createInvoiceForAppointment($appointment);
        // Total is 150.00

        // Pay only 100
        $response = $this->withHeader('Authorization', 'Bearer '.$this->receptionToken)
            ->postJson("http://{$this->domain}/api/v1/invoices/{$invoice->id}/pay", [
                'payments' => [
                    [
                        'method' => 'cash',
                        'amount' => 100.00,
                    ],
                ],
            ]);

        // Expect 422 with descriptive error message
        $response->assertStatus(422);
        $invoice->refresh();
        $this->assertEquals(PaymentStatus::UNPAID, $invoice->payment_status);
    }

    public function test_double_payment_prevention_guards_against_double_spending(): void
    {
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::PENDING_PAYMENT->value,
        ]);

        $billingService = app(BillingService::class);
        $invoice = $billingService->createInvoiceForAppointment($appointment);

        // First payment succeeds
        $billingService->processPayment($invoice->id, [
            ['method' => 'cash', 'amount' => 150.00],
        ], $this->receptionist->id);

        $invoice->refresh();
        $this->assertEquals(PaymentStatus::PAID, $invoice->payment_status);

        // Attempting second payment must throw exception
        $this->expectException(\InvalidArgumentException::class);
        $billingService->processPayment($invoice->id, [
            ['method' => 'cash', 'amount' => 150.00],
        ], $this->receptionist->id);
    }

    public function test_cannot_add_services_to_already_paid_invoice(): void
    {
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::PENDING_PAYMENT->value,
        ]);

        $billingService = app(BillingService::class);
        $invoice = $billingService->createInvoiceForAppointment($appointment);

        $billingService->processPayment($invoice->id, [
            ['method' => 'cash', 'amount' => 150.00],
        ], $this->receptionist->id);

        $this->expectException(\InvalidArgumentException::class);
        $billingService->addExtraService($invoice->id, $this->ecgService->id, 1);
    }

    public function test_reception_can_fetch_pending_invoices_for_drawer(): void
    {
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::PENDING_PAYMENT->value,
        ]);

        $billingService = app(BillingService::class);
        $invoice = $billingService->createInvoiceForAppointment($appointment);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->receptionToken)
            ->getJson("http://{$this->domain}/api/v1/invoices/pending?branch_id={$this->branch->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertNotEmpty($response->json('data'));
        $this->assertEquals($invoice->id, $response->json('data.0.id'));
    }

    public function test_doctor_or_owner_can_create_and_update_service_pricing_from_settings(): void
    {
        // 1. Create new service
        $response = $this->withHeader('Authorization', 'Bearer '.$this->doctorToken)
            ->postJson("http://{$this->domain}/api/v1/billing/services", [
                'name' => 'جلسة علاج طبيعي',
                'code' => 'PHYSIO',
                'price' => 200.00,
                'branch_id' => $this->branch->id,
            ]);

        $response->assertStatus(201);
        $serviceId = $response->json('data.id');
        $this->assertNotNull($serviceId);

        // 2. Update service name and price
        $updateResponse = $this->withHeader('Authorization', 'Bearer '.$this->doctorToken)
            ->putJson("http://{$this->domain}/api/v1/billing/services/{$serviceId}", [
                'name' => 'جلسة علاج طبيعي متقدمة',
                'price' => 250.00,
                'branch_id' => $this->branch->id,
            ]);

        $updateResponse->assertStatus(200);
        $updateResponse->assertJsonPath('data.name', 'جلسة علاج طبيعي متقدمة');

        // Verify BranchService override was updated to 250.00
        $branchService = BranchService::where('branch_id', $this->branch->id)
            ->where('service_id', $serviceId)
            ->first();
        $this->assertNotNull($branchService);
        $this->assertEquals('250.00', $branchService->price);
    }

    public function test_can_get_or_create_invoice_for_appointment(): void
    {
        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => AppointmentStatus::BOOKING->value,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->receptionToken)
            ->getJson("http://{$this->domain}/api/v1/invoices/appointment/{$appointment->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('status', 'success');
        $this->assertEquals((string) $appointment->id, $response->json('data.appointment_id'));
        $this->assertEquals('150.00', $response->json('data.total'));
    }
}

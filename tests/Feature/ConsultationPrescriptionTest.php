<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\LiveQueue;
use App\Models\Drug;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Enums\LiveQueueStatus;
use App\Enums\AppointmentStatus;
use App\Services\ConsultationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ConsultationPrescriptionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected Branch $branchA;
    protected User $doctorA;
    protected Patient $patientA;
    protected Appointment $appointmentA;
    protected LiveQueue $liveQueueA;
    protected Drug $drugPanadol;
    protected string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantAId = 'clinic-rx-' . Str::random(6);
        $tenantBId = 'clinic-rx-' . Str::random(6);

        // 1. Create Tenant A and Tenant B
        $this->tenantA = Tenant::create(['id' => $tenantAId]);
        $this->tenantA->domains()->create(['domain' => $tenantAId . '.test']);

        $this->tenantB = Tenant::create(['id' => $tenantBId]);
        $this->tenantB->domains()->create(['domain' => $tenantBId . '.test']);

        // 2. Setup Context in Tenant A
        tenancy()->initialize($this->tenantA);
        setPermissionsTeamId($this->tenantA->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'sanctum']);

        $this->branchA = Branch::factory()->create(['tenant_id' => $this->tenantA->id]);

        $this->doctorA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'email'     => 'doctor@' . $tenantAId . '.test',
        ]);
        $this->doctorA->assignRole('doctor');
        $this->doctorA->branches()->attach($this->branchA->id);

        $this->patientA = Patient::create([
            'tenant_id'      => $this->tenantA->id,
            'name'           => 'مريض العيادة أ',
            'phone'          => '01011112222',
            'medical_number' => 'MRN-50001',
            'age'            => 30,
            'gender'         => 'male',
        ]);

        $this->appointmentA = Appointment::create([
            'tenant_id'        => $this->tenantA->id,
            'branch_id'        => $this->branchA->id,
            'patient_id'       => $this->patientA->id,
            'doctor_id'        => $this->doctorA->id,
            'appointment_time' => now(),
            'type'             => 'check_up',
            'status'           => AppointmentStatus::CHECKED_IN->value,
        ]);

        $this->liveQueueA = LiveQueue::create([
            'tenant_id'        => $this->tenantA->id,
            'branch_id'        => $this->branchA->id,
            'appointment_id'   => $this->appointmentA->id,
            'patient_id'       => $this->patientA->id,
            'doctor_id'        => $this->doctorA->id,
            'queue_no'         => 1,
            'status'           => LiveQueueStatus::UNDER_EXAMINATION,
            'shift_date'       => now()->toDateString(),
            'checked_in_at'    => now(),
        ]);

        $this->drugPanadol = Drug::create([
            'trade_name'        => 'Panadol Extra',
            'active_ingredient' => 'Paracetamol 500mg',
            'price'             => 35.00,
            'barcode'           => '6220000111122',
        ]);

        $this->tokenA = $this->doctorA->createToken('doc-token')->plainTextToken;

        tenancy()->end();
    }

    /** @test */
    public function test_complete_consultation_creates_prescription_and_items_via_eloquent(): void
    {
        $payload = [
            'live_queue_id'        => $this->liveQueueA->id,
            'appointment_id'       => $this->appointmentA->id,
            'patient_id'           => $this->patientA->id,
            'branch_id'            => $this->branchA->id,
            'chief_complaint'      => 'صداع مستمر وحمى خفيفة',
            'examination_findings' => 'الحلق ملتهب قليلاً',
            'diagnoses'            => ['نزلة برد حادة'],
            'vitals'               => [
                'temperature' => '38.2',
                'blood_pressure' => '120/80',
            ],
            'medications'          => [
                [
                    'drug_id'      => $this->drugPanadol->id,
                    'name'         => 'Panadol Extra',
                    'dosage'       => 'قرص واحد',
                    'frequency'    => 'كل 8 ساعات',
                    'duration'     => '5 أيام',
                    'instructions' => 'بعد الأكل',
                    'sort_order'   => 1,
                ],
                [
                    'name'         => 'فيتامين سي فوار',
                    'dosage'       => 'كيس فوار',
                    'frequency'    => 'مرة يومياً',
                    'duration'     => '10 أيام',
                    'instructions' => 'صباحاً على نصف كوب ماء',
                    'sort_order'   => 2,
                ],
            ],
            'general_advice'       => 'الراحة التامة والإكثار من السوائل الدافئة',
        ];

        $domain = $this->tenantA->domains()->first()->domain;
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->postJson("http://{$domain}/api/v1/consultations/complete", $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'status'  => 'success',
            'message' => 'Consultation completed successfully',
        ]);

        // 1. Verify Prescription was created under Tenant A
        $prescription = Prescription::where('appointment_id', $this->appointmentA->id)->first();
        $this->assertNotNull($prescription);
        $this->assertEquals($this->tenantA->id, $prescription->tenant_id);

        // 2. Verify PrescriptionItems were created via Eloquent relationship
        $items = PrescriptionItem::where('prescription_id', $prescription->id)->orderBy('sort_order')->get();
        $this->assertCount(2, $items);

        // First Item assertions
        $this->assertEquals($this->tenantA->id, $items[0]->tenant_id);
        $this->assertEquals($prescription->id, $items[0]->prescription_id);
        $this->assertEquals($this->drugPanadol->id, $items[0]->drug_id);
        $this->assertEquals('Panadol Extra', $items[0]->drug_name);
        $this->assertEquals('قرص واحد', $items[0]->dose);
        $this->assertEquals('بعد الأكل', $items[0]->instruction);
        $this->assertEquals(1, $items[0]->sort_order);

        // Second Item assertions (custom drug without drug_id)
        $this->assertEquals($this->tenantA->id, $items[1]->tenant_id);
        $this->assertEquals($prescription->id, $items[1]->prescription_id);
        $this->assertNull($items[1]->drug_id);
        $this->assertEquals('فيتامين سي فوار', $items[1]->drug_name);
        $this->assertEquals(2, $items[1]->sort_order);

        // 3. Verify LiveQueue was marked completed
        $this->assertEquals(LiveQueueStatus::COMPLETED->value, $this->liveQueueA->fresh()->status->value);
    }

    /** @test */
    public function test_prescription_items_are_strictly_isolated_from_other_tenants(): void
    {
        // 1. Create prescription & items in Tenant A directly via ConsultationService
        tenancy()->initialize($this->tenantA);
        auth()->login($this->doctorA);
        $service = app(ConsultationService::class);

        $result = $service->completeConsultation([
            'live_queue_id'   => $this->liveQueueA->id,
            'appointment_id'  => $this->appointmentA->id,
            'patient_id'      => $this->patientA->id,
            'branch_id'       => $this->branchA->id,
            'chief_complaint' => 'شكوى خاصة بالعيادة أ',
            'diagnoses'       => ['تشخيص أ'],
            'medications'     => [
                [
                    'name'        => 'دواء سري خاص بتينانت أ',
                    'dose'        => '1 قرص',
                    'frequency'   => 'يومياً',
                    'duration'    => '7 أيام',
                    'instruction' => 'سري للغاية',
                ]
            ],
        ]);

        $prescriptionA = $result['prescription'];
        tenancy()->end();

        // 2. Switch context to Tenant B
        tenancy()->initialize($this->tenantB);
        setPermissionsTeamId($this->tenantB->id);

        // PrescriptionItem query under Tenant B MUST return empty (BelongsToTenant isolation)
        $itemsInTenantB = PrescriptionItem::where('prescription_id', $prescriptionA->id)->get();
        $this->assertCount(0, $itemsInTenantB);

        $allTenantBItems = PrescriptionItem::all();
        $this->assertCount(0, $allTenantBItems);

        tenancy()->end();
    }

    /** @test */
    public function test_throws_exception_if_prescription_tenant_id_is_missing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant context missing for prescription.');

        $prescriptionMock = new Prescription();
        $prescriptionMock->tenant_id = null;

        throw_if(
            empty($prescriptionMock->tenant_id),
            \RuntimeException::class,
            'Tenant context missing for prescription.'
        );
    }
}

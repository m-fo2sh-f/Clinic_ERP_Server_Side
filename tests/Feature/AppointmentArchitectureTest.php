<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\LiveQueue;
use App\Models\Tenant;
use App\Enums\AppointmentStatus;
use App\Services\AppointmentService;
use App\Services\PatientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

class AppointmentArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected AppointmentService $appointmentService;
    protected PatientService $patientService;
    protected Branch $branch;
    protected ?Tenant $tenant = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists(Tenant::class)) {
            $this->tenant = Tenant::create(['id' => 'tenant-' . Str::random(8)]);
            tenancy()->initialize($this->tenant);
        }

        $this->appointmentService = app(AppointmentService::class);
        $this->patientService     = app(PatientService::class);
        $this->branch             = Branch::factory()->create();
    }

    /** @test */
    public function test_normalizes_arabic_and_english_name_variations_during_resolution(): void
    {
        $arabicPatient = Patient::factory()->create([
            'name'  => 'أحمد فؤاد',
            'phone' => '01012345678',
        ]);

        $resolvedArabicId = $this->patientService->resolvePatient([
            'patient' => [
                'name'  => 'احمد فؤاد ',
                'phone' => '010-1234-5678',
            ]
        ]);

        $this->assertEquals($arabicPatient->id, $resolvedArabicId);

        $englishPatient = Patient::factory()->create([
            'name'  => 'MOHAMMED FOAUD',
            'phone' => '01099998888',
        ]);

        $resolvedEnglishId = $this->patientService->resolvePatient([
            'patient' => [
                'name'  => 'mohammed foaud',
                'phone' => '01099998888',
            ]
        ]);

        $this->assertEquals($englishPatient->id, $resolvedEnglishId);
    }

    /** @test */
    public function test_manual_booking_with_existing_name_and_phone_does_not_duplicate_patient(): void
    {
        $patient = Patient::factory()->create([
            'name'  => 'محمد فؤاد',
            'phone' => '01012345678',
        ]);

        $initialPatientCount = Patient::count();

        $appointment = $this->appointmentService->createAppointment([
            'branch_id'        => $this->branch->id,
            'appointment_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'type'             => 'check_up',
            'patient'          => [
                'name'  => 'محمد فؤاد ',
                'phone' => '010-1234-5678',
            ]
        ]);

        $this->assertEquals($initialPatientCount, Patient::count());
        $this->assertEquals($patient->id, $appointment->patient_id);
    }

    /** @test */
    public function test_reuses_existing_patient_when_exact_name_and_phone_are_typed_manually(): void
    {
        $existingPatient = Patient::factory()->create([
            'name'           => 'Same Patient Name',
            'phone'          => '01000000000',
            'medical_number' => 'MRN-10001',
        ]);

        $initialPatientCount = Patient::count();

        // Resolve patient by typing exact same name & phone
        $resolvedId = $this->patientService->resolvePatient([
            'patient' => [
                'name'  => 'Same Patient Name',
                'phone' => '01000000000',
            ]
        ]);

        // Must return existing patient ID without creating a duplicate record or new MRN
        $this->assertEquals($existingPatient->id, $resolvedId);
        $this->assertEquals($initialPatientCount, Patient::count());
    }

    /** @test */
    public function test_creates_new_patient_when_same_phone_has_different_name(): void
    {
        $id1 = $this->patientService->resolvePatient([
            'patient' => [
                'name'  => 'Child Sibling One',
                'phone' => '01000000000',
            ]
        ]);

        $id2 = $this->patientService->resolvePatient([
            'patient' => [
                'name'  => 'Child Sibling Two',
                'phone' => '01000000000',
            ]
        ]);

        $this->assertNotEquals($id1, $id2);
        $this->assertDatabaseHas('patients', ['id' => $id1, 'name' => 'Child Sibling One', 'phone' => '01000000000']);
        $this->assertDatabaseHas('patients', ['id' => $id2, 'name' => 'Child Sibling Two', 'phone' => '01000000000']);
    }

    /** @test */
    public function test_updates_current_patient_demographics_under_update_current_strategy(): void
    {
        $patient = Patient::factory()->create(['name' => 'Original Name', 'phone' => '01011111111']);
        $apt = Appointment::factory()->create(['patient_id' => $patient->id, 'branch_id' => $this->branch->id]);

        $this->appointmentService->updateAppointment($apt->id, [
            'strategy' => 'UPDATE_CURRENT',
            'patient'  => [
                'name'  => 'Corrected Name',
                'phone' => '01099999999',
                'age'   => 35,
            ]
        ]);

        $this->assertDatabaseHas('patients', [
            'id'    => $patient->id,
            'name'  => 'Corrected Name',
            'phone' => '01099999999',
            'age'   => 35,
        ]);
    }

    /** @test */
    public function test_reassigns_appointment_to_another_patient_under_reassign_strategy(): void
    {
        $patientA = Patient::factory()->create(['name' => 'Patient A']);
        $patientB = Patient::factory()->create(['name' => 'Patient B']);

        $apt = Appointment::factory()->create(['patient_id' => $patientA->id, 'branch_id' => $this->branch->id]);

        $this->appointmentService->updateAppointment($apt->id, [
            'strategy'   => 'REASSIGN_EXISTING',
            'patient_id' => $patientB->id,
        ]);

        $this->assertDatabaseHas('appointments', [
            'id'         => $apt->id,
            'patient_id' => $patientB->id,
        ]);
    }

    /** @test */
    public function test_auto_generates_medical_record_number_mrn_per_tenant(): void
    {
        $patient1 = $this->patientService->createPatient([
            'name'  => 'First Patient',
            'phone' => '01011111111',
        ]);

        $patient2 = $this->patientService->createPatient([
            'name'  => 'Second Patient',
            'phone' => '01022222222',
        ]);

        $this->assertNotNull($patient1->medical_number);
        $this->assertNotNull($patient2->medical_number);
        $this->assertEquals('MRN-10001', $patient1->medical_number);
        $this->assertEquals('MRN-10002', $patient2->medical_number);
    }

    /** @test */
    public function test_searches_patients_by_name_phone_or_medical_record_number(): void
    {
        $patient = Patient::factory()->create([
            'name'           => 'Unique Search Target',
            'phone'          => '01077778888',
            'medical_number' => 'MRN-99999',
        ]);

        $results = $this->patientService->search(new \Illuminate\Http\Request(['q' => 'MRN-99999']));
        $this->assertTrue($results->contains('id', $patient->id));

        $resultsByName = $this->patientService->search(new \Illuminate\Http\Request(['q' => 'Unique Search Target']));
        $this->assertTrue($resultsByName->contains('id', $patient->id));
    }

    /** @test */
    public function test_blocks_deleting_completed_appointments(): void
    {
        $appointment = Appointment::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => AppointmentStatus::COMPLETED->value,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->appointmentService->destroyAppointment($appointment->id);
    }

    /** @test */
    public function test_cleans_up_linked_live_queue_item_when_appointment_is_destroyed(): void
    {
        $appointment = Appointment::factory()->create([
            'branch_id' => $this->branch->id,
            'status'    => AppointmentStatus::CHECKED_IN->value,
        ]);

        $queueItem = LiveQueue::factory()->create([
            'appointment_id' => $appointment->id,
            'branch_id'      => $this->branch->id,
            'patient_id'     => $appointment->patient_id,
        ]);

        $this->appointmentService->destroyAppointment($appointment->id);

        $this->assertDatabaseMissing('appointments', ['id' => $appointment->id]);
        $this->assertDatabaseMissing('live_queues', ['id' => $queueItem->id]);
    }

    /** @test */
    public function test_creates_appointment_with_doctor_id_and_filters_queue_per_doctor(): void
    {
        $doctor1 = \App\Models\User::factory()->create(['name' => 'Dr. Ahmed Ali']);
        $doctor2 = \App\Models\User::factory()->create(['name' => 'Dr. Sara Mahmoud']);

        // Doctor 1 Appointment & Check-In
        $appt1 = $this->appointmentService->createAppointment([
            'branch_id'        => $this->branch->id,
            'doctor_id'        => $doctor1->id,
            'appointment_time' => now()->addHour()->format('Y-m-d H:i:s'),
            'type'             => 'check_up',
            'patient'          => [
                'name'  => 'Patient For Dr Ahmed',
                'phone' => '01011112222',
            ]
        ]);
        $queue1 = $this->appointmentService->checkInAppointment($appt1->id);

        // Doctor 2 Appointment & Check-In
        $appt2 = $this->appointmentService->createAppointment([
            'branch_id'        => $this->branch->id,
            'doctor_id'        => $doctor2->id,
            'appointment_time' => now()->addHours(2)->format('Y-m-d H:i:s'),
            'type'             => 'check_up',
            'patient'          => [
                'name'  => 'Patient For Dr Sara',
                'phone' => '01033334444',
            ]
        ]);
        $queue2 = $this->appointmentService->checkInAppointment($appt2->id);

        $liveQueueService = app(\App\Services\LiveQueueService::class);

        // Queue for Dr. Ahmed
        $doc1Queue = $liveQueueService->getQueueForBranch($this->branch->id, $doctor1->id);
        $this->assertCount(1, $doc1Queue);
        $this->assertEquals($queue1->id, $doc1Queue->first()->id);

        // Queue for Dr. Sara
        $doc2Queue = $liveQueueService->getQueueForBranch($this->branch->id, $doctor2->id);
        $this->assertCount(1, $doc2Queue);
        $this->assertEquals($queue2->id, $doc2Queue->first()->id);

        // All Queue (Unfiltered for receptionist)
        $allQueue = $liveQueueService->getQueueForBranch($this->branch->id, null);
        $this->assertCount(2, $allQueue);
    }

    /** @test */
    public function test_creates_appointment_and_persists_patient_age_and_gender(): void
    {
        $appointment = $this->appointmentService->createAppointment([
            'branch_id'        => $this->branch->id,
            'appointment_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'type'             => 'check_up',
            'patient'          => [
                'name'   => 'Patient With Demographics',
                'phone'  => '01055556666',
                'age'    => 28,
                'gender' => 'male',
            ]
        ]);

        $this->assertDatabaseHas('patients', [
            'id'     => $appointment->patient_id,
            'name'   => 'Patient With Demographics',
            'phone'  => '01055556666',
            'age'    => 28,
            'gender' => 'male',
        ]);
    }

    /** @test */
    public function test_api_store_appointment_validates_and_persists_patient_age_and_gender(): void
    {
        $this->tenant->domains()->create(['domain' => 'tenant.test']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        $user = \App\Models\User::factory()->create();
        $user->assignRole('receptionist');
        $user->branches()->attach($this->branch->id);

        $response = $this->actingAs($user)->postJson('http://tenant.test/api/v1/appointments', [
            'branch_id'        => $this->branch->id,
            'appointment_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'type'             => 'check_up',
            'status'           => 'booking',
            'patient'          => [
                'name'   => 'API Patient Age Gender',
                'phone'  => '01077779999',
                'age'    => 45,
                'gender' => 'female',
            ]
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.patient.age', 45);
        $response->assertJsonPath('data.patient.gender', 'female');

        $this->assertDatabaseHas('patients', [
            'name'   => 'API Patient Age Gender',
            'phone'  => '01077779999',
            'age'    => 45,
            'gender' => 'female',
        ]);
    }
}


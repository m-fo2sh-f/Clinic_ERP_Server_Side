<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Prescription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class PatientPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected Branch $branch;
    protected User $doctor;
    protected User $receptionist;
    protected Patient $patient;
    protected string $doctorToken;
    protected string $receptionistToken;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Tenant and Domain
        $this->tenant = Tenant::create(['id' => 'tenant-privacy']);
        $this->tenant->domains()->create(['domain' => 'tenant-privacy.test']);

        tenancy()->initialize($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        // 2. Roles under Spatie
        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        // 3. Dedicated Branch
        $this->branch = Branch::factory()->create(['tenant_id' => $this->tenant->id]);

        // 4. Clinical Staff (Doctor)
        $this->doctor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email'     => 'doctor@tenant-privacy.test',
        ]);
        $this->doctor->assignRole('doctor');
        $this->doctor->branches()->attach($this->branch->id);

        // 5. Non-Clinical Staff (Receptionist)
        $this->receptionist = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'email'     => 'reception@tenant-privacy.test',
        ]);
        $this->receptionist->assignRole('receptionist');
        $this->receptionist->branches()->attach($this->branch->id);

        // 6. Patient with sensitive clinical history
        $this->patient = Patient::create([
            'tenant_id'        => $this->tenant->id,
            'name'             => 'سارة مريض تجريبي',
            'phone'            => '01012345678',
            'medical_number'   => 'MRN-1001',
            'age'              => 30,
            'gender'           => 'female',
            'chronic_diseases' => 'الربو المزمن',
            'medical_history'  => 'تاريخ مرضي سريري سري للغاية',
        ]);

        $appointment = Appointment::create([
            'tenant_id'            => $this->tenant->id,
            'branch_id'            => $this->branch->id,
            'patient_id'           => $this->patient->id,
            'doctor_id'            => $this->doctor->id,
            'appointment_time'     => now(),
            'type'                 => 'check_up',
            'status'               => 'completed',
            'chief_complaint'      => 'ألم حاد في الصدر',
            'diagnosis'            => ['التهاب رئوي حاد'],
            'clinical_examination' => 'فحص سريري كامل',
        ]);

        Prescription::create([
            'tenant_id'         => $this->tenant->id,
            'appointment_id'    => $appointment->id,
            'patient_id'        => $this->patient->id,
            'doctor_id'         => $this->doctor->id,
            'prescription_code' => 'RX-999',
            'prescription_date' => now()->toDateString(),
            'general_advice'    => 'ملاحظات الروشتة السرية',
        ]);

        $this->doctorToken = $this->doctor->createToken('doc-token')->plainTextToken;
        $this->receptionistToken = $this->receptionist->createToken('rec-token')->plainTextToken;

        tenancy()->end();
    }

    /** @test */
    public function test_receptionist_calling_show_receives_demographics_without_prescriptions_or_diagnoses(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->receptionistToken)
            ->getJson('http://tenant-privacy.test/api/v1/patients/' . $this->patient->id);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data'   => [
                'id'    => $this->patient->id,
                'name'  => 'سارة مريض تجريبي',
                'phone' => '01012345678',
            ],
        ]);

        $responseData = $response->json('data');

        // التأكد من حجب كافة البيانات والمسارات السريرية الحساسة عن موظف الاستقبال
        $this->assertArrayNotHasKey('consultations', $responseData);
        $this->assertArrayNotHasKey('prescriptions', $responseData);
        $this->assertArrayNotHasKey('diagnosis', $responseData);
        $this->assertArrayNotHasKey('appointments', $responseData);
        $this->assertArrayNotHasKey('medical_history', $responseData);
        $this->assertArrayNotHasKey('chronic_diseases', $responseData);
    }

    /** @test */
    public function test_doctor_calling_show_receives_full_clinical_history(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->doctorToken)
            ->getJson('http://tenant-privacy.test/api/v1/patients/' . $this->patient->id);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data'   => [
                'id'    => $this->patient->id,
                'name'  => 'سارة مريض تجريبي',
                'phone' => '01012345678',
            ],
        ]);

        $responseData = $response->json('data');

        // التأكد من أن الطبيب يمتلك صلاحية الوصول الكاملة للبيانات السريرية والاستشارات
        $this->assertTrue(isset($responseData['appointments']) || isset($responseData['consultations']));
        $this->assertArrayHasKey('medical_history', $responseData);
        $this->assertEquals('تاريخ مرضي سريري سري للغاية', $responseData['medical_history']);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

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

    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Tenant and Domain
        $tenantId = 'tenant-priv-'.Str::random(6);
        $this->domain = $tenantId.'.test';
        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        // 2. Roles under Spatie
        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        // 3. Dedicated Branch
        $this->branch = Branch::factory()->create();

        // 4. Clinical Staff (Doctor)
        $this->doctor = User::factory()->create([
            'email' => 'doctor@'.$this->domain,
        ]);
        $this->doctor->assignRole('doctor');
        $this->doctor->branches()->attach($this->branch->id);

        // 5. Non-Clinical Staff (Receptionist)
        $this->receptionist = User::factory()->create([
            'email' => 'reception@'.$this->domain,
        ]);
        $this->receptionist->assignRole('receptionist');
        $this->receptionist->branches()->attach($this->branch->id);

        // 6. Patient with sensitive clinical history
        $this->patient = Patient::create([
            'name' => 'سارة مريض تجريبي',
            'phone' => '01012345678',
            'medical_number' => 'MRN-1001',
            'age' => 30,
            'gender' => 'female',
            'chronic_diseases' => 'الربو المزمن',
            'medical_history' => 'تاريخ مرضي سريري سري للغاية',
        ]);

        $appointment = Appointment::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'appointment_time' => now(),
            'type' => 'check_up',
            'status' => 'completed',
            'chief_complaint' => 'ألم حاد في الصدر',
            'diagnosis' => ['التهاب رئوي حاد'],
            'clinical_examination' => 'فحص سريري كامل',
        ]);

        Prescription::create([
            'appointment_id' => $appointment->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'prescription_code' => 'RX-999',
            'prescription_date' => now()->toDateString(),
            'general_advice' => 'ملاحظات الروشتة السرية',
        ]);

        $this->doctorToken = $this->doctor->createToken('doc-token')->plainTextToken;
        $this->receptionistToken = $this->receptionist->createToken('rec-token')->plainTextToken;

        tenancy()->end();
    }

    /** @test */
    public function test_receptionist_calling_show_receives_demographics_without_prescriptions_or_diagnoses(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->receptionistToken)
            ->getJson('http://'.$this->domain.'/api/v1/patients/'.$this->patient->id);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $this->patient->id,
                'name' => 'سارة مريض تجريبي',
                'phone' => '01012345678',
            ],
        ]);

        $responseData = $response->json('data');

        // التأكد من حجب كافة البيانات والمسارات السريرية الحساسة عن موظف الاستقبال
        $this->assertArrayNotHasKey('consultations', $responseData);
        $this->assertArrayNotHasKey('prescriptions', $responseData);
        $this->assertArrayNotHasKey('medical_history', $responseData);
        $this->assertArrayNotHasKey('chronic_diseases', $responseData);

        // التأكد من أن الكشوفات المعروضة للريسبشن لا تحتوي على أي بيانات سريرية حساسة
        if (isset($responseData['appointments'])) {
            foreach ($responseData['appointments'] as $appt) {
                $this->assertArrayNotHasKey('diagnosis', $appt);
                $this->assertArrayNotHasKey('prescription', $appt);
                $this->assertArrayNotHasKey('vitals', $appt);
                $this->assertArrayNotHasKey('clinical_examination', $appt);
                $this->assertArrayHasKey('appointment_time', $appt);
                $this->assertArrayHasKey('status', $appt);
                $this->assertArrayHasKey('type', $appt);
            }
        }
    }

    /** @test */
    public function test_doctor_calling_show_receives_full_clinical_history(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->doctorToken)
            ->getJson('http://'.$this->domain.'/api/v1/patients/'.$this->patient->id);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data' => [
                'id' => $this->patient->id,
                'name' => 'سارة مريض تجريبي',
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

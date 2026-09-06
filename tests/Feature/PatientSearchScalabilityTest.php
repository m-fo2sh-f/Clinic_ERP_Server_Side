<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Branch;
use App\Models\Patient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class PatientSearchScalabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $doctorA;
    protected string $tokenA;

    protected Patient $patientAhmed;
    protected Patient $patientSara;
    protected Patient $patientJohn;
    protected Patient $patientTenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Tenant A and Tenant B
        $this->tenantA = Tenant::create(['id' => 'clinic-a']);
        $this->tenantA->domains()->create(['domain' => 'clinic-a.test']);

        $this->tenantB = Tenant::create(['id' => 'clinic-b']);
        $this->tenantB->domains()->create(['domain' => 'clinic-b.test']);

        // 2. Setup Doctor in Tenant A
        tenancy()->initialize($this->tenantA);
        setPermissionsTeamId($this->tenantA->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);

        $this->doctorA = User::factory()->create([
            'tenant_id' => 'clinic-a',
            'email'     => 'doctor@clinic-a.test',
        ]);
        $this->doctorA->assignRole('doctor');
        $branchA = Branch::factory()->create(['tenant_id' => 'clinic-a']);
        $this->doctorA->branches()->attach($branchA->id);

        // 3. Seed Patients for Tenant A
        $this->patientAhmed = Patient::create([
            'tenant_id'      => 'clinic-a',
            'name'           => 'أحمد علي حسن',
            'phone'          => '01012345678',
            'medical_number' => 'MRN-10001',
            'age'            => 35,
            'gender'         => 'male',
        ]);

        $this->patientSara = Patient::create([
            'tenant_id'      => 'clinic-a',
            'name'           => 'سارة إبراهيم محمد',
            'phone'          => '01198765432',
            'medical_number' => 'PT-2026-002',
            'age'            => 28,
            'gender'         => 'female',
        ]);

        $this->patientJohn = Patient::create([
            'tenant_id'      => 'clinic-a',
            'name'           => 'John Doe Smith',
            'phone'          => '01234567890',
            'medical_number' => 'MRN-10003',
            'age'            => 42,
            'gender'         => 'male',
        ]);

        $this->tokenA = $this->doctorA->createToken('test-token')->plainTextToken;
        tenancy()->end();

        // 4. Seed Patient for Tenant B (to assert tenant isolation)
        tenancy()->initialize($this->tenantB);
        $this->patientTenantB = Patient::create([
            'tenant_id'      => 'clinic-b',
            'name'           => 'أحمد علي دخيل',
            'phone'          => '01019999999',
            'medical_number' => 'MRN-99999',
            'age'            => 50,
            'gender'         => 'male',
        ]);
        tenancy()->end();

        // Commit transaction to flush InnoDB Full-Text cache to inverted index
        \Illuminate\Support\Facades\DB::commit();
    }

    protected function tearDown(): void
    {
        if (isset($this->tenantA)) {
            Patient::whereIn('tenant_id', ['clinic-a', 'clinic-b'])->forceDelete();
            User::whereIn('tenant_id', ['clinic-a', 'clinic-b'])->forceDelete();
            Branch::whereIn('tenant_id', ['clinic-a', 'clinic-b'])->forceDelete();
            $this->tenantA->delete();
            $this->tenantB->delete();
        }
        parent::tearDown();
    }

    /** @test */
    public function test_phone_prefix_search_matches_correct_patient_via_btree_index(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://clinic-a.test/api/v1/patients/search?q=0101');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->patientAhmed->id);
        $response->assertJsonPath('data.0.phone', '01012345678');
    }

    /** @test */
    public function test_medical_number_prefix_search_matches_via_btree_index(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://clinic-a.test/api/v1/patients/search?q=PT-2026');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->patientSara->id);
        $response->assertJsonPath('data.0.medical_number', 'PT-2026-002');
    }

    /** @test */
    public function test_mysql_fulltext_search_matches_arabic_and_english_names(): void
    {
        // 1. English name full-text search
        $responseEn = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://clinic-a.test/api/v1/patients/search?q=John');

        $responseEn->assertStatus(200);
        $responseEn->assertJsonCount(1, 'data');
        $responseEn->assertJsonPath('data.0.id', $this->patientJohn->id);

        // 2. Arabic name search
        $responseAr = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://clinic-a.test/api/v1/patients/search?q=سارة');

        $responseAr->assertStatus(200);
        $responseAr->assertJsonCount(1, 'data');
        $responseAr->assertJsonPath('data.0.id', $this->patientSara->id);
    }

    /** @test */
    public function test_short_string_prefix_search_matches_under_three_characters(): void
    {
        // Short prefix (< 3 chars): 'Jo' -> John
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://clinic-a.test/api/v1/patients/search?q=Jo');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->patientJohn->id);
    }

    /** @test */
    public function test_search_strictly_respects_tenant_isolation(): void
    {
        // Search '0101' which exists in both Tenant A (01012345678) and Tenant B (01019999999)
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://clinic-a.test/api/v1/patients/search?q=0101');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Only patient from Tenant A is returned; Tenant B patient must never leak
        $this->assertCount(1, $data);
        $this->assertEquals($this->patientAhmed->id, $data[0]['id']);
        $this->assertNotEquals($this->patientTenantB->id, $data[0]['id']);
    }

    /** @test */
    public function test_directory_listing_with_search_filter_returns_correct_results(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://clinic-a.test/api/v1/patients?search=0119');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->patientSara->id);
        $response->assertJsonPath('data.0.phone', '01198765432');
    }
}

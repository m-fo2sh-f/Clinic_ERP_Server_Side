<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Patient;
use App\Models\Branch;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class PatientSearchScalabilityTest extends TestCase
{
    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $doctorA;
    protected Patient $patientAhmed;
    protected Patient $patientSara;
    protected Patient $patientJohn;
    protected Patient $patientTenantB;
    protected string $tokenA;
    protected string $domainA;
    protected string $domainB;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Tenant A and Tenant B with dynamic unique IDs
        $idA = 'clinic-a-' . Str::random(6);
        $idB = 'clinic-b-' . Str::random(6);
        $this->domainA = $idA . '.test';
        $this->domainB = $idB . '.test';

        $this->tenantA = Tenant::create(['id' => $idA]);
        $this->tenantA->domains()->create(['domain' => $this->domainA]);

        $this->tenantB = Tenant::create(['id' => $idB]);
        $this->tenantB->domains()->create(['domain' => $this->domainB]);

        // 2. Setup Doctor in Tenant A
        tenancy()->initialize($this->tenantA);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($this->tenantA->id);
        }

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);

        $this->doctorA = User::factory()->create([
            'email' => 'doctor@clinic-a.test',
        ]);
        $this->doctorA->assignRole('doctor');
        $branchA = Branch::factory()->create();
        $this->doctorA->branches()->attach($branchA->id);

        // 3. Seed Patients for Tenant A
        $this->patientAhmed = Patient::create([
            'name'           => 'أحمد علي حسن',
            'phone'          => '01012345678',
            'medical_number' => 'MRN-10001',
            'age'            => 35,
            'gender'         => 'male',
        ]);

        $this->patientSara = Patient::create([
            'name'           => 'سارة إبراهيم محمد',
            'phone'          => '01198765432',
            'medical_number' => 'PT-2026-002',
            'age'            => 28,
            'gender'         => 'female',
        ]);

        $this->patientJohn = Patient::create([
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
            $this->tenantA->delete();
        }
        if (isset($this->tenantB)) {
            $this->tenantB->delete();
        }
        parent::tearDown();
    }

    /** @test */
    public function test_phone_prefix_search_matches_correct_patient_via_btree_index(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson("http://{$this->domainA}/api/v1/patients/search?q=0101");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->patientAhmed->id);
        $response->assertJsonPath('data.0.phone', '01012345678');
    }

    /** @test */
    public function test_medical_number_prefix_search_matches_via_btree_index(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson("http://{$this->domainA}/api/v1/patients/search?q=PT-2026");

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
            ->getJson("http://{$this->domainA}/api/v1/patients/search?q=John");

        $responseEn->assertStatus(200);
        $responseEn->assertJsonCount(1, 'data');
        $responseEn->assertJsonPath('data.0.id', $this->patientJohn->id);

        // 2. Arabic name search
        $responseAr = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson("http://{$this->domainA}/api/v1/patients/search?q=سارة");

        $responseAr->assertStatus(200);
        $responseAr->assertJsonCount(1, 'data');
        $responseAr->assertJsonPath('data.0.id', $this->patientSara->id);
    }

    /** @test */
    public function test_short_string_prefix_search_matches_under_three_characters(): void
    {
        // Short prefix (< 3 chars): 'Jo' -> John
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson("http://{$this->domainA}/api/v1/patients/search?q=Jo");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->patientJohn->id);
    }

    /** @test */
    public function test_search_strictly_respects_tenant_isolation(): void
    {
        // Search '0101' which exists in both Tenant A (01012345678) and Tenant B (01019999999)
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson("http://{$this->domainA}/api/v1/patients/search?q=0101");

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
            ->getJson("http://{$this->domainA}/api/v1/patients?search=0119");

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $this->patientSara->id);
        $response->assertJsonPath('data.0.phone', '01198765432');
    }
}

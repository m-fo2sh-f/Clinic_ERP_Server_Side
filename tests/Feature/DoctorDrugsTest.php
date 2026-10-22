<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\CentralDrug;
use App\Models\DoctorDrug;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\PrescriptionItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clinic\EncounterService;
use App\Services\Clinic\Medications\PrescriptionAutoLearnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DoctorDrugsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Branch $branch;

    protected User $doctorA;

    protected User $doctorB;

    protected User $receptionist;

    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $tenantId = 'tenant-dr-'.Str::random(6);
        $this->domain = $tenantId.'.test';
        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        setPermissionsTeamId($this->tenant->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        $this->branch = Branch::factory()->create();

        $this->doctorA = User::factory()->create([
            'email' => 'dr.a@'.$this->domain,
        ]);
        $this->doctorA->assignRole('doctor');
        $this->doctorA->branches()->attach($this->branch->id);

        $this->doctorB = User::factory()->create([
            'email' => 'dr.b@'.$this->domain,
        ]);
        $this->doctorB->assignRole('doctor');
        $this->doctorB->branches()->attach($this->branch->id);

        $this->receptionist = User::factory()->create([
            'email' => 'rec@'.$this->domain,
        ]);
        $this->receptionist->assignRole('receptionist');
        $this->receptionist->branches()->attach($this->branch->id);

        // Seed central drug strictly with purified columns
        CentralDrug::firstOrCreate(
            ['trade_name' => 'Augmentin 1g Tabs'],
            [
                'active_ingredient' => 'Amoxicillin + Clavulanic Acid',
                'form' => 'Tablet',
                'strength' => '1g',
            ]
        );
    }

    protected function tearDown(): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }

        parent::tearDown();
    }

    /**
     * 1. Hybrid Search Test: Prioritizes doctor favorites ordered by usage_count DESC,
     * followed by central_drugs with deduplication.
     */
    public function test_hybrid_search_prioritizes_doctor_frequent_drugs_and_deduplicates(): void
    {
        // Doctor A has favorite and frequent drugs
        DoctorDrug::create([
            'doctor_id' => $this->doctorA->id,
            'trade_name' => 'Augmentin 1g Special Mix',
            'form' => 'Tablet',
            'strength' => '1g',
            'default_dosage' => '1 tab bid x 7d',
            'usage_count' => 25,
            'is_favorite' => true,
        ]);

        DoctorDrug::create([
            'doctor_id' => $this->doctorA->id,
            'trade_name' => 'Augmentin 625mg Tabs',
            'form' => 'Tablet',
            'strength' => '625mg',
            'default_dosage' => '1 tab tid x 5d',
            'usage_count' => 10,
            'is_favorite' => false,
        ]);

        $response = $this->actingAs($this->doctorA, 'web')
            ->withHeader('X-Branch-Id', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/doctor-drugs/search?query=Augmentin&limit=10");

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        // First item should be the highest usage doctor favorite
        $this->assertEquals('Augmentin 1g Special Mix', $data[0]['trade_name']);
        $this->assertTrue($data[0]['is_favorite']);
        $this->assertEquals(25, $data[0]['usage_count']);
        $this->assertEquals('doctor_catalog', $data[0]['source']);

        // Check central drug item exists in the same response
        $this->assertTrue(collect($data)->contains(fn ($item) => $item['trade_name'] === 'Augmentin 1g Tabs' && $item['source'] === 'central_catalog'));
    }

    /**
     * 2. Unregistered Custom Drug Test: Completing a prescription with an unregistered drug
     * saves to prescription_items with unified dosage, auto-creates in doctor_drugs (usage_count=1, is_custom=true),
     * and keeps central_drugs untouched.
     */
    public function test_unregistered_custom_drug_saves_to_prescription_and_creates_doctor_drug_without_altering_central(): void
    {
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'branch_id' => $this->branch->id,
            'status' => AppointmentStatus::UNDER_EXAMINATION,
        ]);

        $encounter = Encounter::create([
            'branch_id' => $this->branch->id,
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctorA->id,
            'appointment_id' => $appointment->id,
            'type' => EncounterType::SCHEDULED->value,
            'status' => EncounterStatus::IN_PROGRESS->value,
            'started_at' => now(),
        ]);

        $initialCentralCount = CentralDrug::count();
        $unregisteredName = 'Custom Cough Elixir DrA 200ml';
        $unregisteredDosage = '10ml tid after meals';

        $encounterService = app(EncounterService::class);
        $encounterService->completeEncounter($encounter->id, [
            'medications' => [
                [
                    'drug_name' => $unregisteredName,
                    'dosage' => $unregisteredDosage,
                ],
            ],
        ], $this->doctorA->id);

        // Verify prescription_items table contains unified dosage
        $item = PrescriptionItem::where('drug_name', $unregisteredName)->first();
        $this->assertNotNull($item);
        $this->assertEquals($unregisteredDosage, $item->dosage);

        // Verify doctor_drugs has auto-learned this custom drug
        $doctorDrug = DoctorDrug::where('doctor_id', $this->doctorA->id)
            ->where('trade_name', $unregisteredName)
            ->first();

        $this->assertNotNull($doctorDrug);
        $this->assertEquals(1, $doctorDrug->usage_count);
        $this->assertEquals($unregisteredDosage, $doctorDrug->default_dosage);
        $this->assertTrue($doctorDrug->is_custom);

        // Verify central_drugs table was NOT modified (Strict Read-Only)
        $this->assertEquals($initialCentralCount, CentralDrug::count());
        $this->assertFalse(CentralDrug::where('trade_name', $unregisteredName)->exists());
    }

    /**
     * 3. Auto-Learning Increment Test: Prescribing the same drug again increments usage_count to 2
     * and updates default_dosage without creating duplicate rows.
     */
    public function test_auto_learning_increments_usage_and_updates_dosage_without_duplicates(): void
    {
        $autoLearnService = app(PrescriptionAutoLearnService::class);
        $drugName = 'Panadol Advance 500mg';

        // 1st prescription
        $autoLearnService->recordPrescriptionUsage((string) $this->doctorA->id, [
            [
                'drug_name' => $drugName,
                'dosage' => '1 tab bid',
            ],
        ]);

        $drug = DoctorDrug::where('doctor_id', $this->doctorA->id)
            ->where('trade_name', $drugName)
            ->first();

        $this->assertNotNull($drug);
        $this->assertEquals(1, $drug->usage_count);
        $this->assertEquals('1 tab bid', $drug->default_dosage);

        // 2nd prescription with updated dosage
        $autoLearnService->recordPrescriptionUsage((string) $this->doctorA->id, [
            [
                'drug_name' => $drugName,
                'dosage' => '2 tabs tid prn pain',
            ],
        ]);

        $count = DoctorDrug::where('doctor_id', $this->doctorA->id)
            ->where('trade_name', $drugName)
            ->count();

        $this->assertEquals(1, $count, 'Must not duplicate doctor_drugs rows');

        $drug->refresh();
        $this->assertEquals(2, $drug->usage_count);
        $this->assertEquals('2 tabs tid prn pain', $drug->default_dosage);
    }

    /**
     * 4. Cross-Doctor Security & IDOR Test: Doctor B cannot see Doctor A's custom drugs in search,
     * and cannot update or delete them (403 Forbidden).
     */
    public function test_cross_doctor_isolation_prevents_idor_and_search_leakage(): void
    {
        // Doctor A creates a private custom drug
        $drugA = DoctorDrug::create([
            'doctor_id' => $this->doctorA->id,
            'trade_name' => 'Exclusive Cardiac Compound DrA',
            'form' => 'Capsule',
            'strength' => '25mg',
            'default_dosage' => '1 cap daily',
            'usage_count' => 12,
            'is_custom' => true,
            'is_favorite' => true,
        ]);

        // Doctor B searches: must NOT see Doctor A's private drug
        $searchResponse = $this->actingAs($this->doctorB, 'web')
            ->withHeader('X-Branch-Id', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/doctor-drugs/search?query=Exclusive");

        $searchResponse->assertStatus(200);
        $searchResults = $searchResponse->json('data');
        $this->assertFalse(collect($searchResults)->contains(fn ($d) => $d['trade_name'] === 'Exclusive Cardiac Compound DrA'));

        // Doctor B tries to update Doctor A's drug -> 403 Forbidden
        $updateResponse = $this->actingAs($this->doctorB, 'web')
            ->withHeader('X-Branch-Id', $this->branch->id)
            ->putJson("http://{$this->domain}/api/v1/doctor-drugs/{$drugA->id}", [
                'trade_name' => 'Malicious Rename Attempt',
            ]);

        $updateResponse->assertStatus(403);

        // Doctor B tries to delete Doctor A's drug -> 403 Forbidden
        $deleteResponse = $this->actingAs($this->doctorB, 'web')
            ->withHeader('X-Branch-Id', $this->branch->id)
            ->deleteJson("http://{$this->domain}/api/v1/doctor-drugs/{$drugA->id}");

        $deleteResponse->assertStatus(403);

        // Ensure drug still intact in database
        $this->assertDatabaseHas('doctor_drugs', [
            'id' => $drugA->id,
            'trade_name' => 'Exclusive Cardiac Compound DrA',
        ]);
    }

    /**
     * 5. Receptionist RBAC Test: Receptionist is strictly forbidden (403) from accessing doctor-drugs routes.
     */
    public function test_receptionist_is_strictly_forbidden_from_doctor_drugs_endpoints(): void
    {
        // Search endpoint
        $this->actingAs($this->receptionist, 'web')
            ->withHeader('X-Branch-Id', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/doctor-drugs/search?query=Augmentin")
            ->assertStatus(403);

        // Index catalog endpoint
        $this->actingAs($this->receptionist, 'web')
            ->withHeader('X-Branch-Id', $this->branch->id)
            ->getJson("http://{$this->domain}/api/v1/doctor-drugs")
            ->assertStatus(403);

        // Store custom drug endpoint
        $this->actingAs($this->receptionist, 'web')
            ->withHeader('X-Branch-Id', $this->branch->id)
            ->postJson("http://{$this->domain}/api/v1/doctor-drugs", [
                'trade_name' => 'Receptionist Drug',
                'form' => 'Tablet',
                'strength' => '500mg',
                'default_dosage' => '1 tab daily',
            ])
            ->assertStatus(403);
    }
}

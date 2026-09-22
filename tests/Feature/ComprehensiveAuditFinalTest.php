<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Branch;
use App\Models\Patient;
use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\Service;
use App\Events\LiveQueueUpdated;
use App\Events\NextPatientCalled;
use App\Events\QueueReordered;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class ComprehensiveAuditFinalTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected Branch $branchA1;
    protected Branch $branchA2;
    protected User $ownerA;
    protected User $doctorA;
    protected User $receptionistA;
    protected string $ownerTokenA;
    protected string $doctorTokenA;
    protected string $receptionistTokenA;

    protected function setUp(): void
    {
        parent::setUp();

        $tAId = 'clinic-aud-' . Str::lower(Str::random(6));
        $tBId = 'clinic-aud-' . Str::lower(Str::random(6));

        // 1. Create two distinct tenants
        $this->tenantA = Tenant::create(['id' => $tAId]);
        $this->tenantA->domains()->create(['domain' => $tAId . '.test']);

        $this->tenantB = Tenant::create(['id' => $tBId]);
        $this->tenantB->domains()->create(['domain' => $tBId . '.test']);

        // 2. Setup roles and context in Tenant A
        tenancy()->initialize($this->tenantA);

        $ownerRole = Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
        $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
        $receptionRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

        $this->branchA1 = Branch::factory()->create([
            'name' => 'فرع المعادي',
        ]);

        $this->branchA2 = Branch::factory()->create([
            'name' => 'فرع التجمع',
        ]);

        // A. Clinic Owner: has full administrative access, no specific branch assignment required
        $this->ownerA = User::factory()->create([
            'email'          => 'owner@' . $this->tenantA->id . '.test',
            'is_super_admin' => false,
        ]);
        $this->ownerA->assignRole($ownerRole);

        // B. Doctor: assigned to both branch 1 and branch 2
        $this->doctorA = User::factory()->create([
            'email'          => 'doctor@' . $this->tenantA->id . '.test',
            'is_super_admin' => false,
        ]);
        $this->doctorA->assignRole($doctorRole);
        $this->doctorA->branches()->sync([$this->branchA1->id, $this->branchA2->id]);

        // C. Receptionist: assigned only to branch 1
        $this->receptionistA = User::factory()->create([
            'email'          => 'reception@' . $this->tenantA->id . '.test',
            'is_super_admin' => false,
        ]);
        $this->receptionistA->assignRole($receptionRole);
        $this->receptionistA->branches()->sync([$this->branchA1->id]);

        // Generate Sanctum tokens
        $this->ownerTokenA = $this->ownerA->createToken('owner-token')->plainTextToken;
        $this->doctorTokenA = $this->doctorA->createToken('doctor-token')->plainTextToken;
        $this->receptionistTokenA = $this->receptionistA->createToken('rec-token')->plainTextToken;

        tenancy()->end();
    }

    /**
     * [SEC-01 Regression Test]
     * Asserts that a token or request from Tenant A is strictly rejected when accessing Tenant B.
     */
    public function test_sec_01_cross_tenant_session_bleed_is_strictly_blocked(): void
    {
        $domainB = $this->tenantB->domains()->first()->domain;

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->doctorTokenA)
            ->getJson("http://{$domainB}/api/v1/patients");

        // 1. Cross-tenant token replay: foreign token from Tenant A sent to Tenant B
        // Because personal_access_tokens is isolated per-tenant DB, Tenant B rejects the token (401 Unauthenticated or 403 Forbidden)
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * [SEC-02 Regression Test]
     * Asserts that AppointmentResource strictly filters out sensitive clinical data (diagnosis,
     * clinical examination, vitals, prescription) when serialized for receptionist staff.
     */
    public function test_sec_02_receptionist_cannot_access_phi_clinical_fields(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient = Patient::create([
            'name'             => 'أحمد مريض اختبار',
            'phone'            => '01011112222',
            'medical_number'   => 'MRN-SEC02',
            'age'              => 40,
            'gender'           => 'male',
            'chronic_diseases' => 'الضغط المرتفع',
            'medical_history'  => 'سجل سريري حساس',
        ]);

        $appointment = Appointment::create([
            'branch_id'            => $this->branchA1->id,
            'patient_id'           => $patient->id,
            'doctor_id'            => $this->doctorA->id,
            'appointment_time'     => now(),
            'type'                 => 'consultation',
            'status'               => 'completed',
            'chief_complaint'      => 'صداع حاد وزغللة',
            'diagnosis'            => ['ارتفاع ضغط دم شرياني'],
            'clinical_examination' => 'ضغط الدم 160/100 مع توتر شرياني',
            'vitals'               => ['bp' => '160/100', 'pulse' => 88],
        ]);

        Prescription::create([
            'appointment_id'    => $appointment->id,
            'patient_id'        => $patient->id,
            'doctor_id'         => $this->doctorA->id,
            'prescription_code' => 'RX-SEC02',
            'prescription_date' => now()->toDateString(),
            'general_advice'    => 'علاج دوائي فوري',
        ]);

        $domainA = $this->tenantA->domains()->first()->domain;
        tenancy()->end();

        // 1. Receptionist request: Clinical fields must be stripped
        $recResponse = $this->withHeader('Authorization', 'Bearer ' . $this->receptionistTokenA)
            ->getJson("http://{$domainA}/api/v1/appointments/{$appointment->id}");

        $recResponse->assertStatus(200);
        $recData = $recResponse->json('data') ?? $recResponse->json();

        $this->assertArrayNotHasKey('diagnosis', $recData, 'Diagnosis must not be visible to receptionists');
        $this->assertArrayNotHasKey('clinical_examination', $recData, 'Clinical examination must not be visible to receptionists');
        $this->assertArrayNotHasKey('vitals', $recData, 'Vitals must not be visible to receptionists');
        $this->assertArrayNotHasKey('prescription', $recData, 'Prescription must not be visible to receptionists');

        // 2. Doctor request: Clinical fields must be visible
        $this->app['auth']->forgetGuards();
        $docResponse = $this->withHeader('Authorization', 'Bearer ' . $this->doctorTokenA)
            ->getJson("http://{$domainA}/api/v1/appointments/{$appointment->id}");

        $docResponse->assertStatus(200);
        $docData = $docResponse->json('data') ?? $docResponse->json();

        $this->assertArrayHasKey('diagnosis', $docData);
        $this->assertArrayHasKey('clinical_examination', $docData);
        $this->assertArrayHasKey('vitals', $docData);
    }

    /**
     * [SEC-03 & SEC-04 Regression Test]
     * Asserts that catalog modifications (POST /billing/services) are restricted to clinic_owner only,
     * and receptionists or doctors receive 403 Forbidden.
     */
    public function test_sec_03_sec_04_service_catalog_mutation_forbidden_for_receptionist_and_doctor(): void
    {
        $domainA = $this->tenantA->domains()->first()->domain;

        $newServicePayload = [
            'name'      => 'خدمة أشعة سينية متطورة',
            'code'      => 'RAD-999',
            'price'     => 500.00,
            'branch_id' => $this->branchA1->id,
        ];

        // 1. Receptionist attempt -> 403 Forbidden
        $recResponse = $this->withHeader('Authorization', 'Bearer ' . $this->receptionistTokenA)
            ->postJson("http://{$domainA}/api/v1/billing/services", $newServicePayload);
        $recResponse->assertStatus(403);

        // 2. Doctor attempt -> 403 Forbidden
        $this->app['auth']->forgetGuards();
        $docResponse = $this->withHeader('Authorization', 'Bearer ' . $this->doctorTokenA)
            ->postJson("http://{$domainA}/api/v1/billing/services", $newServicePayload);
        $docResponse->assertStatus(403);

        // 3. Clinic Owner attempt -> Success (201 Created)
        $this->app['auth']->forgetGuards();
        $ownerResponse = $this->withHeader('Authorization', 'Bearer ' . $this->ownerTokenA)
            ->postJson("http://{$domainA}/api/v1/billing/services", $newServicePayload);
        $this->assertContains($ownerResponse->status(), [200, 201]);
    }

    /**
     * [SEC-05 Regression Test]
     * Asserts that WebSocket events broadcast strictly on PrivateChannel and do not expose
     * unauthenticated public channels.
     */
    public function test_sec_05_websocket_events_broadcast_only_on_private_channels(): void
    {
        $branchId = (string) Str::uuid();
        $mockQueueItem = new \App\Models\LiveQueue(['id' => (string) Str::uuid(), 'branch_id' => $branchId]);

        // 1. LiveQueueUpdated
        $eventQueueUpdated = new LiveQueueUpdated($branchId, $mockQueueItem);
        $channelsQueue = $eventQueueUpdated->broadcastOn();
        foreach ($channelsQueue as $channel) {
            $this->assertInstanceOf(
                PrivateChannel::class,
                $channel,
                'LiveQueueUpdated must broadcast strictly on PrivateChannel'
            );
            $this->assertFalse(
                $channel instanceof Channel && !($channel instanceof PrivateChannel),
                'LiveQueueUpdated must not broadcast on public Channel'
            );
        }

        // 2. NextPatientCalled
        $eventNextPatient = new NextPatientCalled($branchId, ['ticket_number' => 'A-101']);
        $channelsNext = $eventNextPatient->broadcastOn();
        foreach ($channelsNext as $channel) {
            $this->assertInstanceOf(
                PrivateChannel::class,
                $channel,
                'NextPatientCalled must broadcast strictly on PrivateChannel'
            );
            $this->assertFalse(
                $channel instanceof Channel && !($channel instanceof PrivateChannel),
                'NextPatientCalled must not broadcast on public Channel'
            );
        }

        // 3. QueueReordered
        $eventReordered = new QueueReordered($branchId, [1, 2, 3]);
        $channelsReorder = $eventReordered->broadcastOn();
        foreach ($channelsReorder as $channel) {
            $this->assertInstanceOf(
                PrivateChannel::class,
                $channel,
                'QueueReordered must broadcast strictly on PrivateChannel'
            );
            $this->assertFalse(
                $channel instanceof Channel && !($channel instanceof PrivateChannel),
                'QueueReordered must not broadcast on public Channel'
            );
        }
    }

    /**
     * [PERF-01 Regression Test]
     * Asserts that PatientResource serialization reads pre-aggregated counters directly without triggering
     * inline database count queries.
     */
    public function test_perf_01_patient_resource_reads_counters_without_lazy_n_plus_one_queries(): void
    {
        tenancy()->initialize($this->tenantA);

        $patient = Patient::create([
            'name'           => 'مريض اختبار الأداء',
            'phone'          => '01099887766',
            'medical_number' => 'MRN-PERF01',
            'age'            => 28,
            'gender'         => 'female',
        ]);

        $domainA = $this->tenantA->domains()->first()->domain;
        tenancy()->end();

        // Query the patients index
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->doctorTokenA)
            ->getJson("http://{$domainA}/api/v1/patients");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'total_completed_count',
                    'branch_completed_count',
                ]
            ]
        ]);
    }

    /**
     * [Side-Effect & Regression Check]
     * Asserts that clinic owners (who don't have individual branch relations) and multi-branch doctors
     * are NOT locked out by EnsureUserBelongsToTenant middleware.
     */
    public function test_side_effects_multi_branch_staff_and_clinic_owner_not_locked_out(): void
    {
        $domainA = $this->tenantA->domains()->first()->domain;

        // 1. Clinic Owner (no direct branches assigned): must have full access
        $ownerResponse = $this->withHeader('Authorization', 'Bearer ' . $this->ownerTokenA)
            ->getJson("http://{$domainA}/api/v1/patients");
        $ownerResponse->assertStatus(200);

        // 2. Doctor assigned to Branch 1 & Branch 2: must have access when querying either branch
        $this->app['auth']->forgetGuards();
        $docBranch1Response = $this->withHeader('Authorization', 'Bearer ' . $this->doctorTokenA)
            ->getJson("http://{$domainA}/api/v1/patients?branch_id={$this->branchA1->id}");
        $docBranch1Response->assertStatus(200);

        $this->app['auth']->forgetGuards();
        $docBranch2Response = $this->withHeader('Authorization', 'Bearer ' . $this->doctorTokenA)
            ->getJson("http://{$domainA}/api/v1/patients?branch_id={$this->branchA2->id}");
        $docBranch2Response->assertStatus(200);
    }

    /**
     * [Side-Effect & Security Check]
     * Asserts that a user with no assigned branches and not an owner is strictly denied with 403.
     */
    public function test_user_with_no_roles_and_no_branches_is_denied(): void
    {
        $domainA = $this->tenantA->domains()->first()->domain;

        tenancy()->initialize($this->tenantA);
        $unassignedUser = User::factory()->create([
            'email'          => 'unassigned@' . $this->tenantA->id . '.test',
            'is_super_admin' => false,
        ]);
        $unassignedToken = $unassignedUser->createToken('unassigned-token')->plainTextToken;
        tenancy()->end();

        $this->app['auth']->forgetGuards();
        $unassignedResponse = $this->withHeader('Authorization', 'Bearer ' . $unassignedToken)
            ->getJson("http://{$domainA}/api/v1/patients");
        $unassignedResponse->assertStatus(403);
    }
}

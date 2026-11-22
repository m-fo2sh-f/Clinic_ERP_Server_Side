<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ClinicSetting;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Clinic\EncounterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MultiDoctorPrescriptionSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected Branch $branch;

    protected User $owner;

    protected User $doctorA;

    protected User $doctorB;

    protected string $domain;

    protected function setUp(): void
    {
        parent::setUp();
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }

        $tenantId = 'md-'.Str::random(6);
        $this->domain = "{$tenantId}.test";
        $this->tenant = Tenant::create(['id' => $tenantId]);
        $this->tenant->domains()->create(['domain' => $this->domain]);

        tenancy()->initialize($this->tenant);
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($this->tenant->id);
        }

        $this->branch = Branch::create(['name' => 'Main', 'phone' => '0100', 'is_active' => true]);
        $this->owner = $this->createStaff("o@{$this->domain}", 'clinic_owner');
        $this->doctorA = $this->createStaff("a@{$this->domain}", 'doctor');
        $this->doctorB = $this->createStaff("b@{$this->domain}", 'doctor');
        tenancy()->end();
    }

    protected function tearDown(): void
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            tenancy()->end();
        }
        parent::tearDown();
    }

    protected function createStaff(string $email, string $role): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret')]);
        $user->assignRole(Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']));
        $user->branches()->attach($this->branch->id);

        return $user;
    }

    protected function authJson(User $user, string $method, string $uri, array $data = []): TestResponse
    {
        return $this->actingAs($user, 'sanctum')->withHeader('X-Branch-ID', $this->branch->id)->json($method, "http://{$this->domain}{$uri}", $data);
    }

    public function test_branch_default_fallback_when_doctor_has_no_custom_settings(): void
    {
        tenancy()->initialize($this->tenant);
        ClinicSetting::create([
            'branch_id' => $this->branch->id, 'doctor_id' => null,
            'prescription_template' => ['mode' => 'builtin', 'paper_size' => 'A5', 'primary_color' => '#123456'],
            'vitals_config' => ['fields' => [['key' => 'pulse', 'label' => 'Pulse', 'is_enabled' => true, 'show_in_prescription' => true]]],
        ]);
        tenancy()->end();

        $this->authJson($this->doctorA, 'GET', '/api/v1/clinic-settings/prescription-template')
            ->assertOk()->assertJsonPath('data.is_doctor_override', false)->assertJsonPath('data.primary_color', '#123456');
        $this->authJson($this->doctorA, 'GET', '/api/v1/clinic-settings/vitals-config')
            ->assertOk()->assertJsonPath('data.is_doctor_override', false)->assertJsonPath('data.fields.0.key', 'pulse');
    }

    public function test_doctor_can_create_and_update_own_custom_settings(): void
    {
        $this->authJson($this->doctorA, 'PUT', '/api/v1/clinic-settings/prescription-template', [
            'mode' => 'builtin', 'paper_size' => 'A4', 'primary_color' => '#2563eb',
        ])->assertOk()->assertJsonPath('data.is_doctor_override', true)->assertJsonPath('data.paper_size', 'A4');

        $this->authJson($this->doctorA, 'PUT', '/api/v1/clinic-settings/vitals-config', [
            'refraction_card_enabled' => true,
            'fields' => [['key' => 'iop', 'label' => 'IOP', 'is_enabled' => true, 'show_in_prescription' => true]],
        ])->assertOk()->assertJsonPath('data.is_doctor_override', true)->assertJsonPath('data.refraction_card_enabled', true);

        tenancy()->initialize($this->tenant);
        $this->assertDatabaseHas('clinic_settings', ['branch_id' => $this->branch->id, 'doctor_id' => $this->doctorA->id]);
        tenancy()->end();
    }

    public function test_idor_prevention_doctor_cannot_update_another_doctors_settings(): void
    {
        tenancy()->initialize($this->tenant);
        ClinicSetting::create([
            'branch_id' => $this->branch->id, 'doctor_id' => $this->doctorB->id,
            'prescription_template' => ['mode' => 'builtin', 'paper_size' => 'A5', 'primary_color' => '#111'],
        ]);
        tenancy()->end();

        $this->authJson($this->doctorA, 'PUT', '/api/v1/clinic-settings/prescription-template', [
            'mode' => 'builtin', 'paper_size' => 'A4', 'primary_color' => '#999', 'doctor_id' => (string) $this->doctorB->id,
        ])->assertOk();

        tenancy()->initialize($this->tenant);
        $this->assertEquals('#111', ClinicSetting::where('doctor_id', $this->doctorB->id)->value('prescription_template')['primary_color']);
        $this->assertEquals('#999', ClinicSetting::where('doctor_id', $this->doctorA->id)->value('prescription_template')['primary_color']);
        tenancy()->end();

        $this->authJson($this->owner, 'PUT', '/api/v1/clinic-settings/prescription-template', [
            'mode' => 'builtin', 'paper_size' => 'A4', 'primary_color' => '#00a', 'doctor_id' => (string) $this->doctorB->id,
        ])->assertOk();

        tenancy()->initialize($this->tenant);
        $this->assertEquals('#00a', ClinicSetting::where('doctor_id', $this->doctorB->id)->value('prescription_template')['primary_color']);
        tenancy()->end();
    }

    public function test_freeform_layout_json_persistence_for_both_a4_and_a5(): void
    {
        $layout = [
            'a4' => ['width' => 210, 'height' => 297, 'elements' => [['id' => 'rx', 'x' => 10, 'y' => 15]]],
            'a5' => ['width' => 148, 'height' => 210, 'elements' => [['id' => 'rx', 'x' => 5, 'y' => 8]]],
        ];
        $this->authJson($this->doctorA, 'PUT', '/api/v1/clinic-settings/prescription-template', [
            'layout_mode' => 'freeform_canvas', 'mode' => 'letterhead', 'paper_size' => 'A4', 'freeform_layout' => $layout,
        ])->assertOk()->assertJsonPath('data.freeform_layout.a4.elements.0.x', 10);

        $this->authJson($this->doctorA, 'GET', '/api/v1/clinic-settings/prescription-template')
            ->assertOk()->assertJsonPath('data.freeform_layout.a4.width', 210)->assertJsonPath('data.freeform_layout.a5.width', 148);
    }

    public function test_encounter_service_persists_custom_vitals_and_refraction(): void
    {
        tenancy()->initialize($this->tenant);
        ClinicSetting::create([
            'branch_id' => $this->branch->id, 'doctor_id' => $this->doctorA->id,
            'vitals_config' => ['fields' => [['key' => 'iop', 'label' => 'IOP', 'is_enabled' => true]]],
        ]);
        $p = Patient::create(['medical_number' => 'M1', 'name' => 'Tarek', 'phone' => '0100', 'gender' => 'male']);
        $enc = app(EncounterService::class)->startWalkIn([
            'patient_id' => $p->id,
            'vitals' => [
                'bp_systolic' => 120, 'iop' => '16 mmHg', 'refraction' => ['pd' => 62],
                'od' => ['sphere' => -1.5, 'axis' => 90], 'os' => ['sphere' => -2.0, 'axis' => 85],
                'malicious' => 'stripped',
            ],
        ], $this->doctorA->id, $this->branch->id);

        $this->assertEquals(120, $enc->vitals['bp_systolic']);
        $this->assertEquals('16 mmHg', $enc->vitals['iop']);
        $this->assertEquals(-1.5, $enc->vitals['od']['sphere']);
        $this->assertEquals(85, $enc->vitals['os']['axis']);
        $this->assertEquals(62, $enc->vitals['refraction']['pd']);
        $this->assertArrayNotHasKey('malicious', $enc->vitals);
        tenancy()->end();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $clinicOwner;
    protected User $receptionist;
    protected Tenant $tenant1;
    protected Tenant $tenant2;
    protected string $superAdminToken;
    protected string $clinicOwnerToken;
    protected string $receptionistToken;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create Platform Super Admin
        $this->superAdmin = User::create([
            'name'      => 'Platform Admin',
            'email'     => 'superadmin@platform.test',
            'password'  => Hash::make('password'),
            'tenant_id' => null,
        ]);
        $this->superAdmin->is_super_admin = true;
        $this->superAdmin->save();

        $this->superAdminToken = $this->superAdmin->createToken('super-admin-token')->plainTextToken;

        // 2. Create Tenants
        $this->tenant1 = Tenant::create(['id' => 'test-tenant-1']);
        $this->tenant1->is_active = true;
        $this->tenant1->save();
        $this->tenant1->domains()->create(['domain' => 'tenant1.test']);

        $this->tenant2 = Tenant::create(['id' => 'test-tenant-2']);
        $this->tenant2->is_active = true;
        $this->tenant2->save();
        $this->tenant2->domains()->create(['domain' => 'tenant2.test']);

        $this->tenant1->run(function () {
            setPermissionsTeamId($this->tenant1->id);

            $branch1 = Branch::create([
                'name'    => 'Main Branch Tenant 1',
                'address' => 'Cairo, Egypt',
            ]);

            $ownerRole = Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
            $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
            $recRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

            $this->clinicOwner = User::create([
                'name'      => 'Dr. Clinic Owner',
                'email'     => 'owner@tenant1.test',
                'password'  => Hash::make('password'),
                'tenant_id' => $this->tenant1->id,
            ]);
            $this->clinicOwner->syncRoles([$ownerRole]);
            $this->clinicOwner->branches()->sync([$branch1->id]);

            $this->receptionist = User::create([
                'name'      => 'Receptionist T1',
                'email'     => 'reception@tenant1.test',
                'password'  => Hash::make('password'),
                'tenant_id' => $this->tenant1->id,
            ]);
            $this->receptionist->syncRoles([$recRole]);
            $this->receptionist->branches()->sync([$branch1->id]);
        });

        $this->clinicOwnerToken = $this->clinicOwner->createToken('owner-token')->plainTextToken;
        $this->receptionistToken = $this->receptionist->createToken('rec-token')->plainTextToken;

        tenancy()->end();
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(null);
        }
    }

    /** @test */
    public function test_non_super_admin_receives_403_on_platform_metrics(): void
    {
        // Clinic owner attempt
        $resOwner = $this->withHeader('Authorization', 'Bearer ' . $this->clinicOwnerToken)
            ->getJson('/api/v1/platform/metrics');

        $resOwner->assertStatus(403);
        $resOwner->assertJson([
            'status'  => 'error',
            'message' => 'غير مصرح لك بالوصول إلى لوحة التحكم المركزية للمنصة.',
        ]);

        // Receptionist attempt
        $resRec = $this->withHeader('Authorization', 'Bearer ' . $this->receptionistToken)
            ->getJson('/api/v1/platform/metrics');

        $resRec->assertStatus(403);
    }

    /** @test */
    public function test_super_admin_receives_accurate_global_platform_metrics(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->getJson('/api/v1/platform/metrics');

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data'   => [
                'total_tenants'      => 2,
                'active_tenants'     => 2,
                'total_doctors'      => 0,
                'total_appointments' => 0,
                'today_appointments' => 0,
            ],
        ]);
    }

    /** @test */
    public function test_super_admin_can_list_tenants_and_view_details(): void
    {
        // List tenants
        $listRes = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->getJson('/api/v1/platform/tenants');

        $listRes->assertStatus(200);
        $listRes->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['id', 'clinic_name', 'domain', 'is_active', 'branches_count', 'created_at'],
            ],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);

        // Show tenant details
        $detailRes = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->getJson('/api/v1/platform/tenants/' . $this->tenant1->id);

        $detailRes->assertStatus(200);
        $detailRes->assertJson([
            'status' => 'success',
            'data'   => [
                'id'             => $this->tenant1->id,
                'is_active'      => true,
                'branches_count' => 1,
            ],
        ]);
    }

    /** @test */
    public function test_super_admin_fetches_tenant_staff_with_strictly_scoped_roles(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->getJson('/api/v1/platform/tenants/' . $this->tenant1->id . '/users');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'data' => [
                '*' => ['id', 'name', 'email', 'roles', 'branches'],
            ],
        ]);

        $users = $response->json('data');
        $this->assertNotEmpty($users);

        $ownerData = collect($users)->firstWhere('email', 'owner@tenant1.test');
        $this->assertNotNull($ownerData);
        $this->assertContains('clinic_owner', $ownerData['roles']);
    }

    /** @test */
    public function test_super_admin_can_toggle_tenant_status_and_it_creates_audit_log(): void
    {
        $toggleRes = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->postJson('/api/v1/platform/tenants/' . $this->tenant1->id . '/status', [
                'is_active' => false,
            ]);

        $toggleRes->assertStatus(200);
        $toggleRes->assertJson([
            'status' => 'success',
            'data'   => [
                'id'        => $this->tenant1->id,
                'is_active' => false,
            ],
        ]);

        $this->assertDatabaseHas('tenants', [
            'id'        => $this->tenant1->id,
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('platform_audit_logs', [
            'super_admin_id' => $this->superAdmin->id,
            'action'         => 'suspend_tenant',
            'tenant_id'      => $this->tenant1->id,
        ]);
    }

    /** @test */
    public function test_impersonation_generates_temporary_token_and_creates_audit_record(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->postJson('/api/v1/platform/tenants/' . $this->tenant1->id . '/impersonate');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'message',
            'data' => [
                'token',
                'tenant_id',
                'domain',
                'redirect_url',
                'expires_at',
                'impersonated_user' => ['id', 'name', 'email'],
            ],
        ]);

        $responseData = $response->json('data');
        $this->assertNotEmpty($responseData['token']);
        $this->assertStringContainsString('impersonation_token=', $responseData['redirect_url']);
        $this->assertEquals($this->clinicOwner->id, $responseData['impersonated_user']['id']);

        // Verify audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'super_admin_id' => $this->superAdmin->id,
            'action'         => 'impersonate_clinic_owner',
            'tenant_id'      => $this->tenant1->id,
        ]);

        // Verify audit log model record count
        $auditLog = PlatformAuditLog::where('tenant_id', $this->tenant1->id)
            ->where('action', 'impersonate_clinic_owner')
            ->first();

        $this->assertNotNull($auditLog);
        $this->assertEquals($this->superAdmin->id, $auditLog->super_admin_id);
    }
}

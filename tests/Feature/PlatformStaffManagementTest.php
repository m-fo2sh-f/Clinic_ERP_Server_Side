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

class PlatformStaffManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $clinicOwner;
    protected User $doctor;
    protected Tenant $tenant1;
    protected Tenant $tenant2;
    protected Branch $branch1;
    protected Branch $branch2;
    protected string $superAdminToken;
    protected string $clinicOwnerToken;

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
        $this->tenant1 = Tenant::create(['id' => 'tenant-alpha']);
        $this->tenant1->is_active = true;
        $this->tenant1->save();
        $this->tenant1->domains()->create(['domain' => 'alpha.test']);

        $this->tenant2 = Tenant::create(['id' => 'tenant-beta']);
        $this->tenant2->is_active = true;
        $this->tenant2->save();
        $this->tenant2->domains()->create(['domain' => 'beta.test']);

        // 3. Setup Tenant 1 entities
        $this->tenant1->run(function () {
            setPermissionsTeamId($this->tenant1->id);

            $this->branch1 = Branch::create([
                'name'      => 'Alpha Main Branch',
                'address'   => '123 Alpha St',
                'phone'     => '01000000001',
                'is_active' => true,
            ]);

            $ownerRole = Role::firstOrCreate(['name' => 'clinic_owner', 'guard_name' => 'web']);
            $doctorRole = Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);
            $recRole = Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);

            $this->clinicOwner = User::create([
                'name'      => 'Dr. Alpha Owner',
                'email'     => 'owner@alpha.test',
                'password'  => Hash::make('password123'),
                'tenant_id' => $this->tenant1->id,
            ]);
            $this->clinicOwner->syncRoles([$ownerRole]);
            $this->clinicOwner->branches()->sync([$this->branch1->id]);

            $this->doctor = User::create([
                'name'      => 'Dr. Alpha Doctor',
                'email'     => 'doctor@alpha.test',
                'password'  => Hash::make('password123'),
                'tenant_id' => $this->tenant1->id,
            ]);
            $this->doctor->syncRoles([$doctorRole]);
            $this->doctor->branches()->sync([$this->branch1->id]);
        });

        // 4. Setup Tenant 2 entities
        $this->tenant2->run(function () {
            setPermissionsTeamId($this->tenant2->id);

            $this->branch2 = Branch::create([
                'name'      => 'Beta Main Branch',
                'address'   => '456 Beta Ave',
                'phone'     => '01000000002',
                'is_active' => true,
            ]);
        });

        $this->clinicOwnerToken = $this->clinicOwner->createToken('owner-token')->plainTextToken;

        tenancy()->end();
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(null);
        }
    }

    /** @test */
    public function test_non_super_admin_receives_403_on_all_staff_and_branch_management_routes(): void
    {
        // 1. Attempt to update tenant staff
        $resUpdateStaff = $this->withHeader('Authorization', 'Bearer ' . $this->clinicOwnerToken)
            ->putJson("/api/v1/platform/tenants/{$this->tenant1->id}/users/{$this->doctor->id}", [
                'name'       => 'Hacked Doctor',
                'email'      => 'hacked@alpha.test',
                'roles'      => ['receptionist'],
                'branch_ids' => [$this->branch1->id],
            ]);
        $resUpdateStaff->assertStatus(403);

        // 2. Attempt to reset password
        $resResetPass = $this->withHeader('Authorization', 'Bearer ' . $this->clinicOwnerToken)
            ->postJson("/api/v1/platform/tenants/{$this->tenant1->id}/users/{$this->doctor->id}/reset-password", [
                'password'              => 'new_secret_pass123',
                'password_confirmation' => 'new_secret_pass123',
            ]);
        $resResetPass->assertStatus(403);

        // 3. Attempt to update branch
        $resUpdateBranch = $this->withHeader('Authorization', 'Bearer ' . $this->clinicOwnerToken)
            ->putJson("/api/v1/platform/tenants/{$this->tenant1->id}/branches/{$this->branch1->id}", [
                'name'      => 'Hacked Branch',
                'is_active' => false,
            ]);
        $resUpdateBranch->assertStatus(403);
    }

    /** @test */
    public function test_super_admin_can_update_staff_roles_and_branches_with_tenant_scoping(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->putJson("/api/v1/platform/tenants/{$this->tenant1->id}/users/{$this->doctor->id}", [
                'name'       => 'Dr. Alpha Updated',
                'email'      => 'doctor_updated@alpha.test',
                'roles'      => ['receptionist', 'doctor'],
                'branch_ids' => [$this->branch1->id],
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data'   => [
                'name'  => 'Dr. Alpha Updated',
                'email' => 'doctor_updated@alpha.test',
            ],
        ]);

        // Verify user updated in DB
        $this->doctor->refresh();
        $this->assertEquals('Dr. Alpha Updated', $this->doctor->name);
        $this->assertEquals('doctor_updated@alpha.test', $this->doctor->email);

        // Verify Spatie roles are scoped to tenant1
        setPermissionsTeamId($this->tenant1->id);
        $this->assertTrue($this->doctor->hasRole('receptionist'));
        $this->assertTrue($this->doctor->hasRole('doctor'));
        $this->assertFalse($this->doctor->hasRole('clinic_owner'));

        // Verify global / other tenant team id has no roles
        setPermissionsTeamId($this->tenant2->id);
        $this->doctor->unsetRelation('roles');
        $this->assertFalse($this->doctor->hasRole('receptionist'));
        $this->assertFalse($this->doctor->hasRole('doctor'));
        setPermissionsTeamId(null);

        // Verify audit log
        $this->assertDatabaseHas('platform_audit_logs', [
            'super_admin_id' => $this->superAdmin->id,
            'action'         => 'update_tenant_user',
            'tenant_id'      => $this->tenant1->id,
        ]);
    }

    /** @test */
    public function test_super_admin_cannot_assign_branch_belonging_to_another_tenant(): void
    {
        // Branch 2 belongs to Tenant 2 (Beta)
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->putJson("/api/v1/platform/tenants/{$this->tenant1->id}/users/{$this->doctor->id}", [
                'name'       => 'Dr. Alpha Doctor',
                'email'      => 'doctor@alpha.test',
                'roles'      => ['doctor'],
                'branch_ids' => [$this->branch2->id], // Cross-tenant IDOR attempt
            ]);

        $response->assertStatus(422);

        // Ensure user's branches were NOT modified
        $this->doctor->refresh();
        $this->assertFalse($this->doctor->branches->contains('id', $this->branch2->id));
    }

    /** @test */
    public function test_super_admin_resets_password_revokes_tokens_and_writes_audit_log(): void
    {
        // Create an existing token for the doctor
        $doctorExistingToken = $this->doctor->createToken('doctor-phone')->plainTextToken;
        $this->assertCount(1, $this->doctor->tokens);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->postJson("/api/v1/platform/tenants/{$this->tenant1->id}/users/{$this->doctor->id}/reset-password", [
                'password'              => 'BrandNewPassword123!',
                'password_confirmation' => 'BrandNewPassword123!',
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
        ]);

        // Verify new password is valid
        $this->doctor->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword123!', $this->doctor->password));

        // Verify old Sanctum tokens are completely revoked
        $this->assertCount(0, $this->doctor->tokens);

        // Verify audit log record exists
        $this->assertDatabaseHas('platform_audit_logs', [
            'super_admin_id' => $this->superAdmin->id,
            'action'         => 'reset_tenant_user_password',
            'tenant_id'      => $this->tenant1->id,
        ]);
    }

    /** @test */
    public function test_super_admin_updates_branch_details_and_active_status(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->putJson("/api/v1/platform/tenants/{$this->tenant1->id}/branches/{$this->branch1->id}", [
                'name'      => 'Renovated Alpha Branch',
                'address'   => '789 Updated Blvd, Cairo',
                'phone'     => '01122334455',
                'is_active' => false,
            ]);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'success',
            'data'   => [
                'name'      => 'Renovated Alpha Branch',
                'address'   => '789 Updated Blvd, Cairo',
                'phone'     => '01122334455',
                'is_active' => false,
            ],
        ]);

        // Verify DB update
        $this->branch1->refresh();
        $this->assertEquals('Renovated Alpha Branch', $this->branch1->name);
        $this->assertEquals('789 Updated Blvd, Cairo', $this->branch1->address);
        $this->assertEquals('01122334455', $this->branch1->phone);
        $this->assertFalse($this->branch1->is_active);

        // Verify audit log record
        $this->assertDatabaseHas('platform_audit_logs', [
            'super_admin_id' => $this->superAdmin->id,
            'action'         => 'update_tenant_branch',
            'tenant_id'      => $this->tenant1->id,
        ]);
    }

    /** @test */
    public function test_super_admin_cannot_update_branch_belonging_to_another_tenant(): void
    {
        // Attempting to update branch 2 (Tenant 2) via Tenant 1's route
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->superAdminToken)
            ->putJson("/api/v1/platform/tenants/{$this->tenant1->id}/branches/{$this->branch2->id}", [
                'name'      => 'Malicious Branch Rename',
                'address'   => 'Fake Address',
                'phone'     => '01999999999',
                'is_active' => true,
            ]);

        $response->assertStatus(404);
    }
}

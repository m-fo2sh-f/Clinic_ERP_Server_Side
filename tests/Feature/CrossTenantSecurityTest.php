<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

class CrossTenantSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $doctorA;
    protected string $tokenA;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. إنشاء Tenant A و Tenant B
        $this->tenantA = Tenant::create(['id' => 'tenant-a']);
        $this->tenantA->domains()->create(['domain' => 'tenant-a.test']);

        $this->tenantB = Tenant::create(['id' => 'tenant-b']);
        $this->tenantB->domains()->create(['domain' => 'tenant-b.test']);

        // 2. إعداد الأدوار وتعيين معزل التينانت A تحت Spatie
        tenancy()->initialize($this->tenantA);
        setPermissionsTeamId($this->tenantA->id);

        Role::firstOrCreate(['name' => 'doctor', 'guard_name' => 'web']);

        // 3. إنشاء Doctor A ينتمي حصرياً وتنظيمياً لـ Tenant A فقط
        $this->doctorA = User::factory()->create([
            'tenant_id' => 'tenant-a',
            'email'     => 'doctorA@tenant-a.test',
        ]);
        setPermissionsTeamId($this->tenantA->id);
        $this->doctorA->assignRole('doctor');

        // إنشاء فرع داخل Tenant A وتعيين الدكتور له
        $branchA = Branch::factory()->create(['tenant_id' => 'tenant-a']);
        $this->doctorA->branches()->attach($branchA->id);
        tenancy()->end();

        // 4. توليد Sanctum Token للدكتور A
        $this->tokenA = $this->doctorA->createToken('doctor-a-token')->plainTextToken;
    }

    /** @test */
    public function test_prevents_user_from_accessing_another_tenant_data_with_valid_sanctum_token(): void
    {
        // 5. إرسال طلب من Doctor A إلى Endpoint يخص Tenant B عبر النطاق (tenant-b.test)
        $crossTenantResponse = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://tenant-b.test/api/v1/patients');

        // 6. التحقق من رد الرفض 403 Forbidden مع الكود المخصص TENANT_ACCESS_DENIED
        $crossTenantResponse->assertStatus(403);
        $crossTenantResponse->assertJson([
            'status'  => 'error',
            'code'    => 'TENANT_ACCESS_DENIED',
            'message' => 'غير مصرح لك بالوصول لبيانات هذه العيادة.',
        ]);

        // 7. إرسال نفس الطلب بنفس التوكين إلى Endpoint يخص Tenant A (tenant-a.test)
        $validTenantResponse = $this->withHeader('Authorization', 'Bearer ' . $this->tokenA)
            ->getJson('http://tenant-a.test/api/v1/patients');

        // 8. التحقق من النجاح 200 OK
        $validTenantResponse->assertStatus(200);
    }

    /** @test */
    public function test_spatie_roles_are_strictly_isolated_between_tenants(): void
    {
        // 1. في سياق Tenant A: الدكتور A يمتلك دور doctor
        tenancy()->initialize($this->tenantA);
        setPermissionsTeamId($this->tenantA->id);
        $this->assertTrue($this->doctorA->hasRole('doctor'));
        $this->assertEquals(['doctor'], $this->doctorA->getRoleNames()->toArray());
        tenancy()->end();

        // 2. في سياق Tenant B: الدكتور A لا يمتلك أي دور تحت Tenant B
        tenancy()->initialize($this->tenantB);
        setPermissionsTeamId($this->tenantB->id);
        $this->doctorA->unsetRelation('roles');
        $this->assertFalse($this->doctorA->hasRole('doctor'));
        $this->assertEmpty($this->doctorA->getRoleNames());
        tenancy()->end();
    }

    /** @test */
    public function test_user_attached_via_branch_only_has_access_to_their_tenant_and_denied_others(): void
    {
        // 1. إنشاء مستخدم بدون tenant_id مباشر وربطه بفرع في Tenant A
        tenancy()->initialize($this->tenantA);
        $branchUser = User::factory()->create([
            'tenant_id' => null,
            'email'     => 'nurseA@tenant-a.test',
        ]);
        $branchA = Branch::firstOrCreate(['tenant_id' => 'tenant-a', 'name' => 'فرع التمريض']);
        $branchUser->branches()->attach($branchA->id);
        Role::firstOrCreate(['name' => 'receptionist', 'guard_name' => 'web']);
        setPermissionsTeamId($this->tenantA->id);
        $branchUser->assignRole('receptionist');
        $nurseToken = $branchUser->createToken('nurse-token')->plainTextToken;
        tenancy()->end();

        // 2. الوصول لبيانات Tenant A مسموح 200 OK
        $validResponse = $this->withHeader('Authorization', 'Bearer ' . $nurseToken)
            ->getJson('http://tenant-a.test/api/v1/patients');
        $validResponse->assertStatus(200);

        // 3. محاولة استخدام نفس التوكين للوصول لـ Tenant B تُرفض 403
        $crossResponse = $this->withHeader('Authorization', 'Bearer ' . $nurseToken)
            ->getJson('http://tenant-b.test/api/v1/patients');
        $crossResponse->assertStatus(403);
        $crossResponse->assertJson([
            'status' => 'error',
            'code'   => 'TENANT_ACCESS_DENIED',
        ]);
    }

    /** @test */
    public function test_unauthenticated_request_returns_401_for_sanctum_handling(): void
    {
        $response = $this->getJson('http://tenant-a.test/api/v1/patients');
        $response->assertStatus(401);
    }

    /** @test */
    public function test_cross_tenant_login_attempt_is_rejected(): void
    {
        // محاولة تسجيل دخول مستخدم تابع لـ Tenant A في نطاق Tenant B
        $response = $this->postJson('http://tenant-b.test/api/v1/login', [
            'email'    => 'doctorA@tenant-a.test',
            'password' => 'password',
        ]);

        $response->assertStatus(403);
        $response->assertJson([
            'status'  => 'error',
            'code'    => 'TENANT_ACCESS_DENIED',
        ]);
    }
}

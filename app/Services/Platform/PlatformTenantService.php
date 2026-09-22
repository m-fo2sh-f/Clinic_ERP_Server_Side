<?php

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformTenantService
{
    /**
     * Get paginated list of tenants with optional search and active status filter.
     */
    public function getTenants(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Tenant::query()->with(['domains']);

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->where(function ($q) use ($search) {
                $q->where('id', 'LIKE', "%{$search}%")
                  ->orWhere('data->clinic_name', 'LIKE', "%{$search}%")
                  ->orWhereHas('domains', function ($domainQuery) use ($search) {
                      $domainQuery->where('domain', 'LIKE', "%{$search}%");
                  });
            });
        }

        if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'all') {
            $isActive = filter_var($filters['status'], FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        $paginator = $query->latest()->paginate($perPage);

        $paginator->getCollection()->transform(function ($tenant) {
            try {
                $tenant->branches_count = $tenant->run(fn () => Branch::count());
            } catch (\Throwable) {
                $tenant->branches_count = 0;
            }
            return $tenant;
        });

        return $paginator;
    }

    /**
     * Create a new tenant with dedicated database, domain, and initial seeder.
     */
    public function createTenant(array $data, User $superAdmin, Request $request): Tenant
    {
        $subdomain = \Illuminate\Support\Str::slug($data['subdomain']);
        $centralDomain = config('tenancy.central_domains')[0] ?? 'localhost';
        $domainName = "{$subdomain}.{$centralDomain}";

        // 1. Create Tenant (triggers Stancl pipeline: CreateDatabase, MigrateDatabase, SeedDatabase)
        $tenant = Tenant::create([
            'id'             => $subdomain,
            'clinic_name'    => $data['clinic_name'],
            'owner_email'    => $data['admin_email'],
            'is_active'      => true,
            'admin_name'     => $data['admin_name'],
            'admin_password' => $data['admin_password'],
            'phone'          => $data['phone'] ?? null,
        ]);

        // 2. Create Domain record
        $tenant->domains()->create([
            'domain' => $domainName,
        ]);

        // 3. Log Immutable Audit Record
        PlatformAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action'         => 'create_tenant',
            'tenant_id'      => $tenant->id,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);

        $branches = $tenant->run(fn () => Branch::all());
        $tenant->setRelation('branches', $branches);

        return $tenant->load('domains');
    }

    /**
     * Get detailed tenant record with relationships.
     */
    public function getTenantDetails(string $tenantId): Tenant
    {
        $tenant = Tenant::with('domains')->findOrFail($tenantId);
        $branches = $tenant->run(fn () => Branch::all());
        $tenant->setRelation('branches', $branches);
        return $tenant;
    }

    /**
     * Get paginated tenant users with strictly scoped Spatie roles.
     */
    public function getTenantUsers(string $tenantId, int $perPage = 15): LengthAwarePaginator
    {
        $tenant = Tenant::findOrFail($tenantId);

        return $tenant->run(function () use ($perPage) {
            $paginator = User::with('branches')->latest()->paginate($perPage);

            $paginator->getCollection()->transform(function ($user) {
                $user->tenant_roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->toArray() : [];
                $user->tenant_branches = $user->branches->pluck('name')->toArray();
                $user->tenant_branch_ids = $user->branches->pluck('id')->toArray();
                $user->formatted_created_at = $user->created_at?->toIso8601String();
                $user->setConnection(config('database.default', 'mysql'));

                return $user;
            });

            return $paginator;
        });
    }

    /**
     * Toggle tenant active/suspended status and log audit record.
     */
    public function toggleStatus(string $tenantId, bool $isActive, User $superAdmin, Request $request): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);
        $tenant->is_active = $isActive;
        $tenant->save();

        // Create immutable audit log entry
        PlatformAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action'         => $isActive ? 'activate_tenant' : 'suspend_tenant',
            'tenant_id'      => $tenant->id,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);

        return $tenant;
    }

    /**
     * Update tenant clinic details.
     */
    public function updateTenant(string $tenantId, array $data, User $superAdmin, Request $request): Tenant
    {
        $tenant = Tenant::findOrFail($tenantId);

        if (isset($data['clinic_name'])) {
            $tenant->clinic_name = $data['clinic_name'];
        }

        if (isset($data['is_active'])) {
            $tenant->is_active = (bool) $data['is_active'];
        }

        $tenant->save();

        PlatformAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action'         => 'update_tenant',
            'tenant_id'      => $tenant->id,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);

        return $tenant;
    }

    /**
     * Delete tenant and trigger automated database cleanup pipeline.
     */
    public function deleteTenant(string $tenantId, User $superAdmin, Request $request): void
    {
        $tenant = Tenant::findOrFail($tenantId);

        PlatformAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action'         => 'delete_tenant',
            'tenant_id'      => $tenant->id,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);

        $tenant->delete();
    }
}

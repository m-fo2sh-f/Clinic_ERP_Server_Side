<?php

namespace App\Services\Platform;

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

        return $query->withCount('branches')->latest()->paginate($perPage);
    }

    /**
     * Get detailed tenant record with relationships.
     */
    public function getTenantDetails(string $tenantId): Tenant
    {
        return Tenant::with(['domains', 'branches'])->findOrFail($tenantId);
    }

    /**
     * Get paginated tenant users with strictly scoped Spatie roles.
     * (Technical Directive 1: strictly scoped to $tenantId).
     */
    public function getTenantUsers(string $tenantId, int $perPage = 15): LengthAwarePaginator
    {
        // Find users attached via direct tenant_id or via branches belonging to this tenant
        $paginator = User::query()
            ->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)
                  ->orWhereHas('branches', function ($branchQuery) use ($tenantId) {
                      $branchQuery->where('branches.tenant_id', $tenantId);
                  });
            })
            ->distinct()
            ->paginate($perPage);

        $teamKey = config('permission.column_names.team_foreign_key', 'tenant_id');

        // Map strictly tenant-scoped roles and branches
        $paginator->getCollection()->transform(function ($user) use ($tenantId, $teamKey) {
            // 🛡️ Technical Directive 1: Set permissions team id to current tenant, read roles, reset to null
            $originalTeamId = function_exists('getPermissionsTeamId') ? getPermissionsTeamId() : null;
            
            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($tenantId);
            }

            // Direct DB fallback query on model_has_roles to guarantee team isolation
            $scopedRoles = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('model_has_roles.model_type', User::class)
                ->where('model_has_roles.model_id', $user->id)
                ->where("model_has_roles.{$teamKey}", $tenantId)
                ->pluck('roles.name')
                ->unique()
                ->values()
                ->toArray();

            // If empty, also check user relation if Spatie uses team context
            if (empty($scopedRoles) && method_exists($user, 'getRoleNames')) {
                $scopedRoles = $user->getRoleNames()->toArray();
            }

            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($originalTeamId);
            }

            $userBranches = $user->branches()
                ->where('branches.tenant_id', $tenantId)
                ->pluck('branches.name')
                ->toArray();

            $user->tenant_roles = $scopedRoles;
            $user->tenant_branches = $userBranches;

            return $user;
        });

        return $paginator;
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
}

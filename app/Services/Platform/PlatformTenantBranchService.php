<?php

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\ClinicSetting;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

class PlatformTenantBranchService
{
    /**
     * Update tenant branch details with tenant scoping and audit logging.
     */
    public function updateBranch(string $tenantId, string $branchId, array $data, User $superAdmin, Request $request): Branch
    {
        $tenant = Tenant::findOrFail($tenantId);

        return $tenant->run(function () use ($branchId, $data, $superAdmin, $request, $tenantId) {
            $branch = Branch::findOrFail($branchId);
            $branch->update([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_active' => (bool) $data['is_active'],
            ]);

            PlatformAuditLog::create([
                'super_admin_id' => $superAdmin->id,
                'action' => 'update_tenant_branch',
                'tenant_id' => $tenantId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return $branch;
        });
    }

    /**
     * Create a new tenant branch with default clinic settings and audit logging.
     */
    public function createBranch(string $tenantId, array $data, User $superAdmin, Request $request): Branch
    {
        $tenant = Tenant::findOrFail($tenantId);

        return $tenant->run(function () use ($data, $superAdmin, $request, $tenantId) {
            $branch = Branch::create([
                'name' => $data['name'],
                'address' => $data['address'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            ClinicSetting::firstOrCreate(
                ['branch_id' => $branch->id],
                ['clinic_mode' => 'solo']
            );

            PlatformAuditLog::create([
                'super_admin_id' => $superAdmin->id,
                'action' => 'create_tenant_branch',
                'tenant_id' => $tenantId,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
            ]);

            return $branch;
        });
    }
}

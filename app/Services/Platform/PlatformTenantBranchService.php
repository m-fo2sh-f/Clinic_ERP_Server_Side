<?php


namespace App\Services\Platform;

use App\Models\Branch;
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
        // 1. Verify tenant existence
        Tenant::findOrFail($tenantId);

        // 2. Locate branch strictly scoped to tenant (anti-IDOR)
        $branch = Branch::where('tenant_id', $tenantId)->findOrFail($branchId);

        // 3. Update branch details
        $branch->update([
            'name'      => $data['name'],
            'address'   => $data['address'] ?? null,
            'phone'     => $data['phone'] ?? null,
            'is_active' => (bool) $data['is_active'],
        ]);

        // 4. Write immutable audit log
        PlatformAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action'         => 'update_tenant_branch',
            'tenant_id'      => $tenantId,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);

        return $branch;
    }
}

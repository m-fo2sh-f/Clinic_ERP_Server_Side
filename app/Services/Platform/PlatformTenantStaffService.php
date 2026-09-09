<?php

namespace App\Services\Platform;

use App\Models\Branch;
use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class PlatformTenantStaffService
{
    /**
     * Update tenant staff member demographic data, branch assignments, and tenant-scoped roles.
     */
    public function updateUser(string $tenantId, string $userId, array $data, User $superAdmin, Request $request): User
    {
        // 1. Verify tenant existence
        Tenant::findOrFail($tenantId);

        // 2. Locate user and verify association with target tenant
        $user = User::where(function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId)
              ->orWhereHas('branches', function ($branchQuery) use ($tenantId) {
                  $branchQuery->where('branches.tenant_id', $tenantId);
              });
        })->findOrFail($userId);

        // 3. Anti-IDOR: Verify all branch IDs strictly belong to this tenant
        $branchIds = $data['branch_ids'] ?? [];
        $validBranchesCount = Branch::where('tenant_id', $tenantId)
            ->whereIn('id', $branchIds)
            ->count();

        if ($validBranchesCount !== count(array_unique($branchIds))) {
            abort(422, 'واحد أو أكثر من الفروع المحددة لا تنتمي إلى هذا المستأجر.');
        }

        // 4. Update demographic data
        $user->update([
            'name'  => $data['name'],
            'email' => $data['email'],
        ]);

        // 5. Sync branch assignments
        $user->branches()->sync($branchIds);

        // 6. Scoped Spatie roles synchronization with immediate cleanup
        $originalTeamId = function_exists('getPermissionsTeamId') ? getPermissionsTeamId() : null;
        try {
            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($tenantId);
            }
            $user->syncRoles($data['roles']);
        } finally {
            if (function_exists('setPermissionsTeamId')) {
                setPermissionsTeamId($originalTeamId);
            }
        }

        // 7. Write immutable audit log
        PlatformAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action'         => 'update_tenant_user',
            'tenant_id'      => $tenantId,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);

        // 8. Hydrate tenant-scoped fields for response
        $teamKey = config('permission.column_names.team_foreign_key', 'tenant_id');
        $scopedRoles = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $user->id)
            ->where("model_has_roles.{$teamKey}", $tenantId)
            ->pluck('roles.name')
            ->unique()
            ->values()
            ->toArray();

        $user->tenant_roles = $scopedRoles;
        $user->tenant_branches = $user->branches()
            ->where('branches.tenant_id', $tenantId)
            ->pluck('branches.name')
            ->toArray();
        $user->tenant_branch_ids = $user->branches()
            ->where('branches.tenant_id', $tenantId)
            ->pluck('branches.id')
            ->toArray();

        return $user;
    }

    /**
     * Reset tenant staff password, revoke Sanctum tokens, and log audit event.
     */
    public function resetPassword(string $tenantId, string $userId, string $newPassword, User $superAdmin, Request $request): void
    {
        // 1. Verify tenant existence
        Tenant::findOrFail($tenantId);

        // 2. Locate user and verify association with target tenant
        $user = User::where(function ($q) use ($tenantId) {
            $q->where('tenant_id', $tenantId)
              ->orWhereHas('branches', function ($branchQuery) use ($tenantId) {
                  $branchQuery->where('branches.tenant_id', $tenantId);
              });
        })->findOrFail($userId);

        // 3. Update password hash
        $user->update([
            'password' => Hash::make($newPassword),
        ]);

        // 4. Revoke all active personal access tokens
        $user->tokens()->delete();

        // 5. Write immutable audit log
        PlatformAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action'         => 'reset_tenant_user_password',
            'tenant_id'      => $tenantId,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);
    }
}

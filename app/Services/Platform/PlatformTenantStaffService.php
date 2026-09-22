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
        $tenant = Tenant::findOrFail($tenantId);

        return $tenant->run(function () use ($tenantId, $userId, $data, $superAdmin, $request) {
            $user = User::findOrFail($userId);

            $branchIds = $data['branch_ids'] ?? [];
            $validBranchesCount = Branch::whereIn('id', $branchIds)->count();

            if ($validBranchesCount !== count(array_unique($branchIds))) {
                abort(422, 'واحد أو أكثر من الفروع المحددة لا تنتمي إلى هذا المستأجر.');
            }

            $user->update([
                'name'  => $data['name'],
                'email' => $data['email'],
            ]);

            $user->branches()->sync($branchIds);

            if (isset($data['roles'])) {
                $user->syncRoles($data['roles']);
            }

            PlatformAuditLog::create([
                'super_admin_id' => $superAdmin->id,
                'action'         => 'update_tenant_user',
                'tenant_id'      => $tenantId,
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
                'created_at'     => now(),
            ]);

            $user->tenant_roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames()->toArray() : [];
            $user->tenant_branches = $user->branches->pluck('name')->toArray();
            $user->tenant_branch_ids = $user->branches->pluck('id')->toArray();

            return $user;
        });
    }

    /**
     * Reset tenant staff password, revoke Sanctum tokens, and log audit event.
     */
    public function resetPassword(string $tenantId, string $userId, string $newPassword, User $superAdmin, Request $request): void
    {
        $tenant = Tenant::findOrFail($tenantId);

        $tenant->run(function () use ($userId, $newPassword) {
            $user = User::findOrFail($userId);
            $user->update([
                'password' => Hash::make($newPassword),
            ]);
            $user->tokens()->delete();
        });

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

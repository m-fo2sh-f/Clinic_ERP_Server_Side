<?php

namespace App\Services\Platform;

use App\Models\PlatformAuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlatformImpersonationService
{
    /**
     * Issue a short-lived (15-min TTL) Sanctum token for the clinic_owner,
     * record immutable audit log, and return domain redirect URL.
     */
    public function impersonateClinicOwner(string $tenantId, User $superAdmin, Request $request): array
    {
        $tenant = Tenant::with('domains')->findOrFail($tenantId);

        $teamKey = config('permission.column_names.team_foreign_key', 'tenant_id');

        // 1. Locate the clinic_owner for this tenant
        $ownerId = DB::table('model_has_roles')
            ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
            ->where('roles.name', 'clinic_owner')
            ->where('model_has_roles.model_type', User::class)
            ->where("model_has_roles.{$teamKey}", $tenantId)
            ->value('model_has_roles.model_id');

        if (!$ownerId) {
            // Fallback: check users directly assigned to this tenant
            $owner = User::where('tenant_id', $tenantId)->first();
        } else {
            $owner = User::find($ownerId);
        }

        if (!$owner) {
            abort(404, 'لم يتم العثور على مالك أو مستخدم صالح لهذه العيادة للتقمص.');
        }

        // 2. Issue a strictly 15-minute Sanctum token
        $token = $owner->createToken('impersonation', ['*'], now()->addMinutes(15))->plainTextToken;

        // 3. Create immutable audit record
        PlatformAuditLog::create([
            'super_admin_id' => $superAdmin->id,
            'action'         => 'impersonate_clinic_owner',
            'tenant_id'      => $tenant->id,
            'ip_address'     => $request->ip(),
            'user_agent'     => $request->userAgent(),
            'created_at'     => now(),
        ]);

        // 4. Construct domain redirect URL
        $domain = $tenant->domains->first()?->domain ?? ($tenant->id . '.my-saas.test');
        $redirectUrl = "http://{$domain}:5173/dashboard?impersonation_token={$token}";

        return [
            'token'             => $token,
            'tenant_id'         => $tenant->id,
            'domain'            => $domain,
            'redirect_url'      => $redirectUrl,
            'expires_at'        => now()->addMinutes(15)->toIso8601String(),
            'impersonated_user' => [
                'id'    => $owner->id,
                'name'  => $owner->name,
                'email' => $owner->email,
            ],
        ];
    }
}

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

        [$owner, $token] = $tenant->run(function () {
            $owner = User::role('clinic_owner')->first() 
                ?? User::where('email', 'LIKE', '%@%')->first();

            if (!$owner) {
                abort(404, 'لم يتم العثور على مالك أو مستخدم صالح لهذه العيادة للتقمص.');
            }

            $token = $owner->createToken('impersonation', ['*'], now()->addMinutes(15))->plainTextToken;

            return [$owner, $token];
        });

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

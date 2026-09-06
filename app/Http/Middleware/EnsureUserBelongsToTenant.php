<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserBelongsToTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // لو الريكويست مش مسجل دخول، اتركه لـ auth:sanctum يتعامل معه
        if (!$user) {
            return $next($request);
        }

        $currentTenantId = function_exists('tenant') ? tenant('id') : null;

        if (!$currentTenantId) {
            return $next($request);
        }

        // 🎯 1. التحقق من التبعية المباشرة للتينانت أو من خلال الفروع
        $isDirectTenantMember = isset($user->tenant_id) && $user->tenant_id === $currentTenantId;
        $hasBranchInTenant    = method_exists($user, 'branches') && $user->branches()->where('branches.tenant_id', $currentTenantId)->exists();

        if (!$isDirectTenantMember && !$hasBranchInTenant) {
            return response()->json([
                'status'  => 'error',
                'code'    => 'TENANT_ACCESS_DENIED',
                'message' => 'غير مصرح لك بالوصول لبيانات هذه العيادة.'
            ], 403);
        }

        // 🎯 2. ضبط معرف الفريق الخاص بـ Spatie Permissions ليكون المعزل بالعين للتينانت الحالي
        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($currentTenantId);
        }

        return $next($request);
    }
}
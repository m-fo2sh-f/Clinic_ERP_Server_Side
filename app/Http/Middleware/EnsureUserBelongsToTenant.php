<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        
        // تجاهل التحقق إذا كان المستخدم هو super_admin
        if ((bool) ($user->is_super_admin ?? false)) {
            return $next($request);
        }

        $currentTenantId = function_exists('tenant') ? tenant('id') : null;

        if (!$currentTenantId) {
            return $next($request);
        }

        // 🎯 1. التحقق من التبعية المباشرة للتينانت أو من خلال الفروع
        $isOwner = method_exists($user, 'hasRole') && $user->hasRole('clinic_owner');

        // باقي الموظفين (أطباء واستقبال) لازم يكون عندهم فرع واحد على الأقل في هذه العيادة
        $hasBranchInTenant = method_exists($user, 'branches') && $user->branches()->exists();

       if (!$isOwner && !$hasBranchInTenant) {
            // تسجيل خروج فوري لإنهاء الجلسة المسربة
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->logout();
            }

            return response()->json([
                'status'  => 'error',
                'code'    => 'TENANT_ACCESS_DENIED',
                'message' => 'غير مصرح لك بالوصول لبيانات هذه العيادة، الحساب غير مربوط بأي فرع.'
            ], 403);
        }

        return $next($request);
    }
}
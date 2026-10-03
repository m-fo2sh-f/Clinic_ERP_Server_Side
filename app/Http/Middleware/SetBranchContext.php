<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (function_exists('tenant') && tenant('id') && function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(tenant('id'));
        }

        $branchId = $request->header('X-Branch-ID') ?: $request->input('branch_id');
        $user = $request->user();

        if ($branchId) {
            // التحقق من صلاحية المستخدم على الفرع (IDOR Protection)
            if ($user && method_exists($user, 'branches')) {
                $isOwnerOrSuper = $user->hasRole('clinic_owner') || (bool) ($user->is_super_admin ?? false);
                if (! $isOwnerOrSuper) {
                    $hasAccess = $user->branches()->where('branches.id', $branchId)->exists();
                    if (! $hasAccess) {
                        return response()->json([
                            'success' => false,
                            'error_code' => 'BRANCH_ACCESS_DENIED',
                            'message' => 'عذراً، ليس لديك صلاحية للوصول إلى هذا السجل أو هذا الفرع.',
                            'details' => [],
                        ], 403);
                    }
                }
            }

            app()->instance('active_branch_id', $branchId);
            config(['app.active_branch_id' => $branchId]);
        }

        return $next($request);
    }
}

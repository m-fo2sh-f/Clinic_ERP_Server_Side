<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $branchId = $request->header('X-Branch-ID');
        $user = $request->user();

        if ($branchId) {
            // التحقق من صلاحية المستخدم على الفرع (IDOR Protection)
            if ($user && method_exists($user, 'branches')) {
                $hasAccess = $user->branches()->where('branches.id', $branchId)->exists();
                if (!$hasAccess) {
                    return response()->json([
                        'message' => 'غير مصرح لك بالوصول لبيانات هذا الفرع.'
                    ], 403);
                }
            }

            app()->instance('active_branch_id', $branchId);
            config(['app.active_branch_id' => $branchId]);
        }

        return $next($request);
    }
}
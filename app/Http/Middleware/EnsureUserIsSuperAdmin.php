<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !(bool) $user->is_super_admin) {
            return response()->json([
                'status'  => 'error',
                'message' => 'غير مصرح لك بالوصول إلى لوحة التحكم المركزية للمنصة.',
            ], 403);
        }

        return $next($request);
    }
}

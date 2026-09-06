<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(Request $request)
{
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($credentials)) {
        return response()->json(['message' => 'بيانات الدخول غير صحيحة'], 401);
    }

    $user = Auth::user();

    $currentTenantId = function_exists('tenant') ? tenant('id') : null;
    if ($currentTenantId && function_exists('setPermissionsTeamId')) {
        setPermissionsTeamId($currentTenantId);
    }

    if ($currentTenantId) {
        $isDirectMember = $user->tenant_id === $currentTenantId;
        $hasBranchInTenant = method_exists($user, 'branches') && $user->branches()->where('branches.tenant_id', $currentTenantId)->exists();

        if (!$isDirectMember && !$hasBranchInTenant) {
            Auth::logout();
            return response()->json([
                'status'  => 'error',
                'code'    => 'TENANT_ACCESS_DENIED',
                'message' => 'هذا الحساب لا يمتلك صلاحية الدخول لهذه العيادة'
            ], 403);
        }
    }

    if ($request->hasSession()) {
        $request->session()->regenerate();
    }

    // جلب فروع المستخدم في العيادة الحالية
    $branches = method_exists($user, 'branches') 
        ? $user->branches()->get(['branches.id', 'branches.name']) 
        : [];

    // 🎯 2. لو المستخدم ملوش فروع مسجلة في هذه العيادة (مثل ندى في tenant-2) -> رفض الدخول
    if ($branches->isEmpty() && !$user->hasRole('clinic_owner')) {
        Auth::logout();
        return response()->json(['message' => 'الحساب غير مربوط بأي فرع في هذه العيادة'], 403);
    }

    $roles = method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [];
    $permissions = method_exists($user, 'getAllPermissions') ? $user->getAllPermissions()->pluck('name') : [];

    return response()->json([
        'message' => 'تم تسجيل الدخول بنجاح',
        'tenant' => tenant() ? [
            'id' => tenant('id'),
            'clinic_name' => tenant('clinic_name') ?? tenant('id'),
        ] : null,
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $roles,
            'permissions' => $permissions,
        ],
        'branches' => $branches
    ]);
}

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'تم تسجيل الخروج بنجاح']);
    }

    public function me(Request $request)
    {
        $currentTenantId = function_exists('tenant') ? tenant('id') : null;
        if ($currentTenantId && function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($currentTenantId);
        }

        $user = $request->user();
        $branches = ($user && method_exists($user, 'branches')) 
            ? $user->branches()->get(['branches.id', 'branches.name']) 
            : [];

        $tenant = tenant();

        return response()->json([
            'tenant' => $tenant ? [
                'id' => $tenant->id,
                'clinic_name' => $tenant->clinic_name ?? $tenant->id,
            ] : null,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => method_exists($user, 'getRoleNames') ? $user->getRoleNames() : [],
                'permissions' => method_exists($user, 'getAllPermissions') ? $user->getAllPermissions()->pluck('name') : [],
            ] : null,
            'branches' => $branches
        ]);
    }
}
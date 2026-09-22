<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Platform\PlatformMetricsController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantStaffController;
use App\Http\Controllers\Api\V1\Platform\PlatformTenantBranchController;
use App\Http\Controllers\Api\V1\Platform\PlatformImpersonationController;
use App\Http\Controllers\Api\V1\Auth\AuthController;

/*
|--------------------------------------------------------------------------
| Central Platform Routes (Free from Tenant Scope Isolation)
|--------------------------------------------------------------------------
*/

// 🔓 Central Public Authentication & Sanctum CSRF Cookie
Route::get('/sanctum/csrf-cookie', fn() => response()->noContent());

Route::prefix('api/v1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/sanctum/csrf-cookie', fn() => response()->noContent());

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

// 🔒 Central Platform Protected Routes (Super Admin Only)
Route::prefix('api/v1/platform')
    ->middleware(['auth:sanctum', 'platform.admin'])
    ->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        // Platform Metrics
        Route::get('/metrics', [PlatformMetricsController::class, 'index']);

        // Platform Tenants Management
        Route::get('/tenants/{id}/users', [PlatformTenantController::class, 'users']);
        Route::post('/tenants/{id}/status', [PlatformTenantController::class, 'toggleStatus']);
        Route::apiResource('tenants', PlatformTenantController::class);

        // Platform Tenant Impersonation
        Route::post('/tenants/{id}/impersonate', [PlatformImpersonationController::class, 'impersonate']);

        // Platform Tenant Staff Management
        Route::put('/tenants/{tenantId}/users/{userId}', [PlatformTenantStaffController::class, 'update']);
        Route::post('/tenants/{tenantId}/users/{userId}/reset-password', [PlatformTenantStaffController::class, 'resetPassword']);

        // Platform Tenant Branch Management
        Route::put('/tenants/{tenantId}/branches/{branchId}', [PlatformTenantBranchController::class, 'update']);
    });

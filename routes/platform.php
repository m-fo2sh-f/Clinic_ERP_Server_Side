<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Platform\PlatformMetricsController;
use App\Http\Controllers\Api\Platform\PlatformTenantController;
use App\Http\Controllers\Api\Platform\PlatformImpersonationController;
use App\Http\Controllers\Api\AuthController;

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
});

// 🔒 Central Platform Protected Routes (Super Admin Only)
Route::prefix('api/v1/platform')
    ->middleware(['auth:sanctum', 'platform.admin'])
    ->group(function () {
        // Platform Metrics
        Route::get('/metrics', [PlatformMetricsController::class, 'index']);

        // Platform Tenants Management
        Route::get('/tenants', [PlatformTenantController::class, 'index']);
        Route::get('/tenants/{id}', [PlatformTenantController::class, 'show']);
        Route::get('/tenants/{id}/users', [PlatformTenantController::class, 'users']);
        Route::post('/tenants/{id}/status', [PlatformTenantController::class, 'toggleStatus']);

        // Platform Tenant Impersonation
        Route::post('/tenants/{id}/impersonate', [PlatformImpersonationController::class, 'impersonate']);
    });

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use App\Http\Controllers\api\v1\AppointmentController;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->prefix('api/v1')->group(function () {

    
    Route::get('appointment', [AppointmentController::class, 'index']);
    Route::post('appointment/create', [AppointmentController::class, 'store']);
    Route::put('appointment/{id}', [AppointmentController::class, 'update']);
    Route::delete('appointment/{id}', [AppointmentController::class, 'destroy']);
});

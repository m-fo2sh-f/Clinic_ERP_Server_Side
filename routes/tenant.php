<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

use App\Http\Controllers\api\v1\AppointmentController;
use App\Http\Controllers\api\v1\LiveQueueController;
use App\Http\Controllers\api\v1\PatientController;

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

    
    Route::apiResource('appointments', AppointmentController::class);
    Route::post('appointments/{id}/check-in', [AppointmentController::class, 'checkIn']);

    Route::post('live-queues/next', [LiveQueueController::class, 'nextPatient']);
    Route::post('live-queues/reorder', [LiveQueueController::class, 'reorder']);
    Route::apiResource('live-queues', LiveQueueController::class);

    Route::get('patients/search', [PatientController::class, 'search']);
    Route::get('patients/{id}/history', [PatientController::class, 'getHistory']);

});

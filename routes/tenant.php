<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\LiveQueueController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Broadcast;


Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {

    // 🔓 1. روتات عامة مفتوحة للجميع (Public Routes)
    Route::get('/sanctum/csrf-cookie', fn() => response()->noContent());
    Route::post('/api/v1/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/api/v1/public/live-queues', [LiveQueueController::class, 'publicIndex'])->middleware('throttle:120,1');

    // 🔒 2. روتات تتطلب تسجيل دخول إجباري (Authenticated Routes)
    Route::middleware('auth:sanctum')->group(function () {

        // 📡 روت مصادقة القنوات الخاصة بالـ WebSockets تحت الـ Tenant
        Broadcast::routes(['middleware' => ['auth:sanctum']]);

        // تسجيل الخروج وجلب البيانات الشخصية
        Route::post('/api/v1/logout', [AuthController::class, 'logout']);
        Route::get('/api/v1/me', [AuthController::class, 'me']);

        // 🎯 الروتات المحمية حسب الـ Roles
        $registerProtectedApiRoutes = function () {
            Broadcast::routes(['middleware' => ['auth:sanctum']]);

            // 🩺 أ. روتات خاصة بالدكتور ومالك العيادة فقط (Doctor & Clinic Owner Only)
            Route::middleware('role:doctor|clinic_owner')->group(function () {
                Route::post('live-queues/next', [LiveQueueController::class, 'nextPatient']);
                Route::get('patients/{id}/history', [PatientController::class, 'getHistory']);
            });

            // 📋 ب. روتات خاصة بالريسبشن والمالك والدكتور (Receptionist, Doctor & Clinic Owner)
            Route::middleware('role:receptionist|doctor|clinic_owner')->group(function () {
                // الحجوزات والتسجيل
                Route::apiResource('appointments', AppointmentController::class);
                Route::post('appointments/{id}/check-in', [AppointmentController::class, 'checkIn']);

                // صالة الانتظار الحية (عرض، إضافة، تعديل ترتيب، تسجيل مباشر Walk-In)
                Route::post('live-queues/reorder', [LiveQueueController::class, 'reorder']);
                Route::post('live-queues/check-in-walkin', [LiveQueueController::class, 'checkInWalkIn']);
                Route::apiResource('live-queues', LiveQueueController::class);

                // المرضى وقائمة الأدلة
                Route::get('patients/search', [PatientController::class, 'search'])->middleware('throttle:60,1');
                Route::apiResource('patients', PatientController::class);
            });
        };

        Route::prefix('api/v1')->middleware('throttle:120,1')->group($registerProtectedApiRoutes);
    });
});
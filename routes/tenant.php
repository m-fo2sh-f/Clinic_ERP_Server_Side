<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\LiveQueueController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\AuthController;


Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {

    // 🔓 1. روتات عامة مفتوحة للجميع (Public Routes)
    Route::get('/sanctum/csrf-cookie', fn() => response()->noContent());
    Route::post('/api/login', [AuthController::class, 'login']);
    Route::post('/api/v1/login', [AuthController::class, 'login']);

    // 🔒 2. روتات تتطلب تسجيل دخول إجباري (Authenticated Routes)
    Route::middleware('auth:sanctum')->group(function () {

        // تسجيل الخروج وجلب البيانات الشخصية
        Route::post('/api/logout', [AuthController::class, 'logout']);
        Route::post('/api/v1/logout', [AuthController::class, 'logout']);
        Route::get('/api/me', [AuthController::class, 'me']);
        Route::get('/api/v1/me', [AuthController::class, 'me']);

        // 🎯 الروتات المحمية حسب الـ Roles
        $registerProtectedApiRoutes = function () {

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

                // صالة الانتظار الحية (عرض، إضافة، تعديل ترتيب)
                Route::post('live-queues/reorder', [LiveQueueController::class, 'reorder']);
                Route::apiResource('live-queues', LiveQueueController::class);

                // البحث عن مرضى
                Route::get('patients/search', [PatientController::class, 'search']);
            });
        };

        Route::prefix('api')->group($registerProtectedApiRoutes);
        Route::prefix('api/v1')->group($registerProtectedApiRoutes);
    });
});
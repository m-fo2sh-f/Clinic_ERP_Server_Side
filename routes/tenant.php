<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Clinic\AppointmentController;
use App\Http\Controllers\Api\V1\Clinic\BillingController;
use App\Http\Controllers\Api\V1\Clinic\BranchController;
use App\Http\Controllers\Api\V1\Clinic\EncounterController;
use App\Http\Controllers\Api\V1\Clinic\LiveQueueController;
use App\Http\Controllers\Api\V1\Clinic\PatientController;
use App\Http\Middleware\SetBranchContext;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

Route::middleware([
    'web',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {

    // 🔓 1. روتات عامة مفتوحة للجميع (Public Routes)
    Route::get('/sanctum/csrf-cookie', fn () => response()->noContent());
    Route::post('/api/v1/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/api/v1/public/live-queues', [LiveQueueController::class, 'publicIndex'])->middleware('throttle:120,1');

    // 🔒 2. روتات تتطلب تسجيل دخول إجباري (Authenticated Routes)
    Route::middleware(['auth:sanctum', 'tenant.user', SetBranchContext::class])->group(function () {

        // 📡 روت مصادقة القنوات الخاصة بالـ WebSockets تحت الـ Tenant
        Broadcast::routes(['prefix' => 'api/v1', 'middleware' => ['auth:sanctum']]);
        Broadcast::routes(['middleware' => ['auth:sanctum']]);

        // تسجيل الخروج وجلب البيانات الشخصية
        Route::post('/api/v1/logout', [AuthController::class, 'logout']);
        Route::get('/api/v1/me', [AuthController::class, 'me']);
        Route::prefix('api/v1')->middleware('throttle:120,1')->group(function () {
            // 👑 أ. روتات مالك العيادة فقط (Clinic Owner Only) - إدارة الكتالوج والأسعار
            Route::middleware('role:clinic_owner')->group(function () {
                Route::post('billing/services', [BillingController::class, 'storeService']);
                Route::put('billing/services/{id}', [BillingController::class, 'updateService']);
                Route::delete('billing/services/{id}', [BillingController::class, 'deleteService']);
            });

            // 🩺 ب. روتات خاصة بالدكتور ومالك العيادة فقط (Doctor & Clinic Owner Only)
            Route::middleware('role:doctor|clinic_owner')->group(function () {
                Route::post('live-queues/next', [LiveQueueController::class, 'nextPatient']);
                Route::get('patients/{id}/history', [PatientController::class, 'getHistory']);
                Route::get('patients/{id}/medical-profile', [PatientController::class, 'medicalProfile']);

                // 🩺 جلسات الفحص الموحدة (Solo & Polyclinic Encounters)
                Route::prefix('encounters')->group(function () {
                    Route::get('active', [EncounterController::class, 'active']);
                    Route::post('quick-start', [EncounterController::class, 'quickStart']);
                    Route::post('walk-in', [EncounterController::class, 'quickStart']);
                    Route::match(['put', 'patch'], '{id}/draft', [EncounterController::class, 'saveDraft']);
                    Route::post('{id}/abandon', [EncounterController::class, 'abandon']);
                    Route::post('{id}/complete', [EncounterController::class, 'complete']);
                    Route::get('today-summary', [EncounterController::class, 'todaySummary']);
                    Route::get('{id}', [EncounterController::class, 'show']);
                });
            });

            // 📋 ج. روتات تشغيلية مشتركة (Receptionist, Doctor & Clinic Owner)
            Route::middleware('role:receptionist|doctor|clinic_owner')->group(function () {
                // أطباء الفرع
                Route::get('branches/{branchId}/doctors', [BranchController::class, 'doctors']);

                // الحجوزات والتسجيل
                Route::apiResource('appointments', AppointmentController::class);
                Route::post('appointments/{id}/check-in', [AppointmentController::class, 'checkIn'])->middleware('role:receptionist|clinic_owner');

                // صالة الانتظار الحية (عرض، إضافة، إلغاء، تعديل ترتيب، تسجيل مباشر Walk-In)
                Route::patch('live-queues/{id}/cancel', [LiveQueueController::class, 'cancel'])->middleware('role:receptionist|clinic_owner');
                Route::post('live-queues/reorder', [LiveQueueController::class, 'reorder']);
                Route::post('live-queues/check-in-walkin', [LiveQueueController::class, 'checkInWalkIn'])->middleware('role:receptionist|clinic_owner');
                Route::apiResource('live-queues', LiveQueueController::class);

                // المرضى وقائمة الأدلة
                Route::get('patients/search', [PatientController::class, 'search'])->middleware('throttle:60,1');
                Route::get('patients/{id}/summary', [PatientController::class, 'summary']);
                Route::apiResource('patients', PatientController::class);

                // 💳 الفواتير والمدفوعات التشغيلية (عرض الخدمات وإصدار الفواتير وتحصيلها)
                Route::get('billing/services', [BillingController::class, 'services']);
                Route::get('invoices/pending', [BillingController::class, 'pending']);
                Route::get('invoices/appointment/{appointmentId}', [BillingController::class, 'forAppointment']);
                Route::get('invoices/live-queue/{queueId}', [BillingController::class, 'forQueueItem']);
                Route::get('invoices/encounter/{encounterId}', [BillingController::class, 'forEncounter']);
                Route::get('invoices', [BillingController::class, 'index']);
                Route::get('invoices/{id}', [BillingController::class, 'show']);
                Route::post('invoices/{id}/items', [BillingController::class, 'addItem']);
                Route::delete('invoices/{id}/items/{itemId}', [BillingController::class, 'removeItem']);
                Route::post('invoices/{id}/pay', [BillingController::class, 'pay'])->middleware('role:receptionist|clinic_owner');
            });
        });
    });
});

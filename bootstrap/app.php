<?php

use App\Http\Middleware\EnsureUserBelongsToTenant;
use App\Http\Middleware\EnsureUserIsSuperAdmin;
use App\Http\Middleware\SetBranchContext;
use Illuminate\Auth\Access\AuthorizationException;
// 🎯 1. استدعاء Middleware الخاصة بـ Spatie Permission
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 🎯 تسجيل الـ Middleware Aliases
        $middleware->alias([
            'tenant.user' => EnsureUserBelongsToTenant::class,
            'platform.admin' => EnsureUserIsSuperAdmin::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // 🎯 استثناء كافة روتات الـ API من فحص الـ HTML Form CSRF Token
        $middleware->validateCsrfTokens(except: [
            'api/*',
            'api/v1/*',
            '*/api/*',
            '*/api/v1/*',
            'sanctum/csrf-cookie',
        ]);

        // SetBranchContext moved to authenticated tenant routes
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                // 1. ValidationException
                if ($e instanceof ValidationException) {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'VALIDATION_FAILED',
                        'message' => 'بيانات الإدخال غير صالحة أو غير مكتملة.',
                        'details' => $e->errors(),
                    ], 422);
                }

                // 2. AuthenticationException
                if ($e instanceof AuthenticationException) {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'UNAUTHENTICATED',
                        'message' => 'انتهت صلاحية الجلسة، يرجى تسجيل الدخول مرة أخرى.',
                        'details' => [],
                    ], 401);
                }

                // 3. AuthorizationException, AccessDeniedHttpException & Spatie UnauthorizedException
                if ($e instanceof AuthorizationException ||
                    $e instanceof AccessDeniedHttpException ||
                    $e instanceof UnauthorizedException ||
                    ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403)) {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'FORBIDDEN',
                        'message' => $e->getMessage() ?: 'عذراً، ليس لديك صلاحية للوصول إلى هذا السجل أو هذا الفرع.',
                        'details' => [],
                    ], 403);
                }

                // 4. ModelNotFoundException & NotFoundHttpException
                if ($e instanceof ModelNotFoundException ||
                    $e instanceof NotFoundHttpException) {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'NOT_FOUND',
                        'message' => 'عذراً، السجل المطلوب غير موجود أو تم حذفه.',
                        'details' => [],
                    ], 404);
                }

                // 5. ConflictHttpException
                if ($e instanceof ConflictHttpException) {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'CONFLICT',
                        'message' => $e->getMessage() ?: 'تعارض في حالة العملية الحالية.',
                        'details' => [],
                    ], 409);
                }

                // 6. InvalidArgumentException with specific HTTP status codes
                if ($e instanceof InvalidArgumentException) {
                    $status = in_array((int) $e->getCode(), [400, 403, 404, 409, 422]) ? (int) $e->getCode() : 422;
                    $errorCode = match ($status) {
                        409 => 'CONFLICT',
                        403 => 'FORBIDDEN',
                        404 => 'NOT_FOUND',
                        default => 'UNPROCESSABLE_ENTITY',
                    };

                    return response()->json([
                        'success' => false,
                        'error_code' => $errorCode,
                        'message' => $e->getMessage(),
                        'details' => [],
                    ], $status);
                }

                // 7. General HttpExceptionInterface
                if ($e instanceof HttpExceptionInterface) {
                    return response()->json([
                        'success' => false,
                        'error_code' => 'HTTP_ERROR_'.$e->getStatusCode(),
                        'message' => $e->getMessage() ?: 'حدث خطأ في معالجة الطلب.',
                        'details' => [],
                    ], $e->getStatusCode());
                }

                // 8. Fail-safe Catch-All Server / Database Errors (500)
                // Log internally with full contextual metadata
                Log::error('Unhandled API Exception: '.$e->getMessage(), [
                    'tenant_id' => function_exists('tenant') && tenant() ? tenant('id') : null,
                    'user_id' => auth()->id(),
                    'path' => $request->path(),
                    'method' => $request->method(),
                    'exception' => get_class($e),
                    'file' => $e->getFile().':'.$e->getLine(),
                ]);

                // Never expose raw SQL or stack traces to client
                return response()->json([
                    'success' => false,
                    'error_code' => 'INTERNAL_SERVER_ERROR',
                    'message' => 'حدث خطأ غير متوقع في الخادم، تم تسجيل المشكلة وجارٍ التعامل معها.',
                    'details' => config('app.debug') ? [$e->getMessage()] : [],
                ], 500);
            }
        });
    })->create();

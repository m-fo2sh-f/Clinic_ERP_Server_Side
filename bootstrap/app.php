<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// 🎯 1. استدعاء Middleware الخاصة بـ Spatie Permission
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 🎯 2. تسجيل الـ Aliases لكي يفهم لارافيل كلمة 'role' في الروتات
        $middleware->alias([
            'role'               => RoleMiddleware::class,
            'permission'         => PermissionMiddleware::class,
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
        
        $middleware->append(\App\Http\Middleware\SetBranchContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
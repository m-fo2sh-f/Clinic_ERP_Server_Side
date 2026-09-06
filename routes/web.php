<?php

use Illuminate\Support\Facades\Route;

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->group(function () {
        Route::get('/', function () {
            return view('welcome'); // صفحة لارافيل العامة (أو صفحة الـ SaaS بتاعتك)
        });

        require __DIR__ . '/platform.php';
    });
}

// Global fallback for direct IP/local testing
require __DIR__ . '/platform.php';


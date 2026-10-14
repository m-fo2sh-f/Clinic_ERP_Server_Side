<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

return [

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS',
        'localhost,localhost:5173,*.localhost,*.localhost:5173,localhost:3000,127.0.0.1,127.0.0.1:8000,::1,my-saas.test,my-saas.test:5173,clinic1.my-saas.test,clinic2.my-saas.test,clinic3.my-saas.test,clinic1.my-saas.test:5173,clinic2.my-saas.test:5173,clinic3.my-saas.test:5173,clinic1.localhost,clinic2.localhost,clinic3.localhost'
    )),

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];

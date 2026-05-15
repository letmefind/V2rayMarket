<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$webRoutes = filter_var(env('APP_SHARE_PICKUP_ONLY', false), FILTER_VALIDATE_BOOL)
    ? __DIR__.'/../routes/share-pickup.php'
    : __DIR__.'/../routes/web.php';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: $webRoutes,
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',  // تلگرام از CSRF معاف
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

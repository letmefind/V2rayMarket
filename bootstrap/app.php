<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
        // پشت Traefik: بدون این، اپ فکر می‌کند http است → آدرس assetها http و مرورگر CSS را با Mixed Content قطع می‌کند (صفحه بدون استایل).
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
        );
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',  // تلگرام از CSRF معاف
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

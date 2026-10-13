<?php

use App\Http\Controllers\HealthController;
use App\Http\Middleware\DemoMode;
use App\Http\Middleware\RejectDisabledDemo;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: fn () => Route::get('/health', HealthController::class)->name('health'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo('/dashboard');
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT);
        $middleware->prepend(RejectDisabledDemo::class);
        $middleware->web(append: [DemoMode::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

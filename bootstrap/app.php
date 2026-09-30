<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Гости на маршрутах с middleware `auth` → на вход Filament (админка).
        // Без этого Laravel редиректует на несуществующий маршрут `login`
        // (Route [login] not defined → 500) — дефект майской волны,
        // пойман репетицией слияния 30.09.2026 на `/competition/{id}/scores-summary`.
        $middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

<?php

use App\Core\Http\SecurityHeaders;
use App\Core\Tenancy\Http\EnsureSessionBelongsToClinic;
use App\Core\Tenancy\Http\ResolveClinic;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Every web request belongs to a clinic. The clinic is resolved
        // before anything else, and the session is tied to it afterwards.
        $middleware->redirectGuestsTo('/login');

        $middleware->web(
            prepend: [SecurityHeaders::class, ResolveClinic::class],
            append: [EnsureSessionBelongsToClinic::class],
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

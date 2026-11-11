<?php

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureCanPlan;
use App\Http\Middleware\EnsureIsEmployee;
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
        $middleware->alias(['planner' => EnsureCanPlan::class, 'employee' => EnsureIsEmployee::class]);
        $middleware->web(append: [ContentSecurityPolicy::class]);

        // Achter een https-proxy (Codespaces, Docker) de juiste host en https-links gebruiken.
        // Op de VPS leeg laten: daar staat nginx direct aan de buitenkant.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

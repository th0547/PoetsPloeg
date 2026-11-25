<?php

use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureCanPlan;
use App\Http\Middleware\EnsureIsEmployee;
use App\Http\Middleware\EnsureTwoFactor;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // De app van de planners (token, geen sessie). Los van de JSON-routes onder /api/ in web.php,
        // die het roosterbord met de sessie-cookie gebruikt.
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['planner' => EnsureCanPlan::class, 'employee' => EnsureIsEmployee::class]);
        $middleware->web(append: [ContentSecurityPolicy::class, EnsureTwoFactor::class]);
        // Voorkeur "zijbalk ingeklapt": door de browser gezet (resources/js/app.js), dus niet versleuteld.
        $middleware->encryptCookies(except: ['pp_nav']);

        // Achter een https-proxy (Codespaces, Docker) de juiste host en https-links gebruiken.
        // Op de VPS leeg laten: daar staat nginx direct aan de buitenkant.
        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // De app krijgt altijd JSON terug (ook bij 401, 403, 404 en validatiefouten).
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/v1/*') || $request->expectsJson());
    })->create();

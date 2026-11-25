<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Toegang tot de app-API (api/v1): een geldig token met recht 'plan', van een planner of teamleider
 * die (als dat verplicht is) tweestapsverificatie heeft. Zie ook EnsureCanPlan en EnsureTwoFactor.
 */
class EnsureAppAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user && $user->tokenCan('plan') && Gate::forUser($user)->allows('plan'), 403, 'Je hebt geen toegang tot de planning.');
        abort_if($user->requiresTwoFactor() && ! $user->hasTwoFactor(), 403, 'Stel eerst tweestapsverificatie in op de website.');

        return $next($request);
    }
}

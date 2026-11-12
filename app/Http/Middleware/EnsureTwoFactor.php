<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wie tweestapsverificatie verplicht heeft (planners en teamleiders) maar het nog niet heeft
 * ingesteld, gaat eerst naar de instelpagina (issue #40).
 */
class EnsureTwoFactor
{
    /** Routes die zonder 2FA bereikbaar blijven: instellen, wachtwoord bevestigen, uitloggen. */
    private const ALLOWED = ['two-factor.*', 'password.confirm', 'logout', 'verification.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // confirm-password: de POST-route heeft geen naam, dus ook op pad toestaan.
        $allowed = $request->routeIs(...self::ALLOWED) || $request->is('confirm-password');
        if ($user && $user->requiresTwoFactor() && ! $user->hasTwoFactor() && ! $allowed) {
            if ($request->expectsJson()) {
                abort(403, 'Stel eerst tweestapsverificatie in.');
            }

            return redirect()->route('two-factor.show')
                ->with('status', 'Tweestapsverificatie is verplicht voor jouw rol. Stel het nu in om verder te gaan.');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanPlan
{
    public function handle(Request $request, Closure $next): Response
    {
        // Een medewerker die op de startpagina van de planners belandt, gaat naar de eigen planning.
        if ($request->user()?->isEmployee() && $request->routeIs('dashboard')) {
            return redirect()->route('my.planning');
        }
        abort_unless($request->user() && Gate::forUser($request->user())->allows('plan'), 403, 'Je hebt geen toegang tot de planning.');

        return $next($request);
    }
}

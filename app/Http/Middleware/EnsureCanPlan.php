<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCanPlan
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canPlan(), 403, 'Je hebt geen toegang tot de planning.');

        return $next($request);
    }
}

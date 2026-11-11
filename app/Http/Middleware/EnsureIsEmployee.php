<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Alleen voor medewerker-accounts die aan een actieve medewerker gekoppeld zijn. */
class EnsureIsEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user?->isEmployee() && $user->employee, 403, 'Deze pagina is alleen voor medewerkers.');

        // Inactief gezet terwijl de medewerker nog ingelogd was: direct uitloggen.
        if (! $user->employee->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Je account is niet actief. Neem contact op met je teamleider.']);
        }

        return $next($request);
    }
}

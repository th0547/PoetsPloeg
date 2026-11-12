<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Tweede stap bij het inloggen: code uit de authenticator-app of een herstelcode (issue #40). */
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Log opnieuw in: de tijd voor de code is verstreken.']);
        }
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:30'],
        ]);

        $key = 'two-factor:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Te veel pogingen. Probeer het over '.RateLimiter::availableIn($key).' seconden opnieuw.',
            ]);
        }

        $usedRecovery = false;
        $ok = filled($data['code'] ?? null) && $twoFactor->verify($user, $data['code']);
        if (! $ok && filled($data['recovery_code'] ?? null)) {
            $ok = $usedRecovery = $twoFactor->useRecoveryCode($user, $data['recovery_code']);
        }

        if (! $ok) {
            RateLimiter::hit($key, 60);
            Audit::log('auth.2fa_failed', "Verkeerde code bij inloggen van {$user->name}", $user, [], $user);

            throw ValidationException::withMessages([
                filled($data['recovery_code'] ?? null) ? 'recovery_code' : 'code' => 'Deze code klopt niet.',
            ]);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull(LoginRequest::TWO_FACTOR_SESSION)['remember'];
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();

        if ($usedRecovery) {
            Audit::log('twofactor.recovery_used', "{$user->name} logde in met een herstelcode", $user);
            $left = $user->fresh()->recoveryCodesLeft();
            session()->flash('status', "Je bent ingelogd met een herstelcode. Je hebt er nog {$left} over. Maak nieuwe codes aan bij Mijn profiel, of stel je app opnieuw in als je telefoon kwijt is.");
        }

        return redirect()->intended(route($user->homeRoute(), absolute: false));
    }

    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(LoginRequest::TWO_FACTOR_SESSION);
        if (! is_array($pending) || ($pending['expires'] ?? 0) < now()->getTimestamp()) {
            $request->session()->forget(LoginRequest::TWO_FACTOR_SESSION);

            return null;
        }
        $user = User::find($pending['id'] ?? null);

        return $user?->hasTwoFactor() ? $user : null;
    }
}

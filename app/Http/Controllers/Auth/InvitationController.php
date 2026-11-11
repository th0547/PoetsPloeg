<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/** Een uitgenodigde medewerker stelt hier het eerste wachtwoord in (link uit de uitnodigingsmail). */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): View
    {
        return view('auth.invitation', ['token' => $token, 'email' => (string) $request->query('email')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $account = null;
        $status = Password::broker('invites')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$account) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    // De link kwam via dit adres binnen, dus het adres is bevestigd.
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
                event(new PasswordReset($user));
                $account = $user;
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => 'Deze uitnodiging is ongeldig of verlopen. Vraag je teamleider om een nieuwe uitnodiging.']);
        }

        if ($account->isEmployee() && ! $account->employee?->active) {
            return redirect()->route('login')->with('status', 'Je wachtwoord is ingesteld. Je kunt inloggen zodra je teamleider je account heeft geactiveerd.');
        }

        Auth::login($account);
        $request->session()->regenerate();

        return redirect()->route($account->homeRoute())->with('status', 'Welkom! Je wachtwoord is ingesteld.');
    }
}

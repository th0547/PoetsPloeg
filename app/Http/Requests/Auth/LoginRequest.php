<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /** Sessiesleutel voor iemand die het wachtwoord goed had en nog de code uit de app moet invullen. */
    public const TWO_FACTOR_SESSION = 'login.two_factor';

    /**
     * Controleer e-mail en wachtwoord. Zonder tweestapsverificatie wordt de gebruiker ingelogd;
     * met tweestapsverificatie nog niet: dan komt de code-stap (TwoFactorChallengeController).
     *
     * @return bool true als de code-stap nog moet volgen
     *
     * @throws ValidationException
     */
    public function authenticate(): bool
    {
        $this->ensureIsNotRateLimited();

        $guard = Auth::guard('web');
        $credentials = $this->only('email', 'password');
        if (! $guard->validate($credentials)) {
            event(new Failed('web', $guard->getLastAttempted(), $credentials));
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        // Een medewerker-account werkt alleen zolang de medewerker actief is.
        $user = $guard->getLastAttempted();
        if ($user->isEmployee() && ! $user->employee?->active) {
            throw ValidationException::withMessages([
                'email' => 'Je account is niet actief. Neem contact op met je teamleider.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        if ($user->hasTwoFactor()) {
            $this->session()->put(self::TWO_FACTOR_SESSION, [
                'id' => $user->getKey(),
                'remember' => $this->boolean('remember'),
                'expires' => now()->addMinutes(5)->getTimestamp(),
            ]);

            return true;
        }

        $guard->login($user, $this->boolean('remember'));

        return false;
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AppLoginRequest;
use App\Models\User;
use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Inloggen in de app (api/v1). Werkt zoals de website: eerst e-mail en wachtwoord, dan de code uit de
 * authenticator-app (of een herstelcode). Daarna krijgt de app een token, dat na 60 dagen verloopt
 * (config/sanctum.php) en vervalt als het wachtwoord, de rol of de 2FA verandert (User::booted()).
 */
class AuthController extends Controller
{
    /** Zo lang blijft de tweede stap geldig, net als op de website (LoginRequest). */
    private const CHALLENGE_MINUTES = 5;

    public function login(AppLoginRequest $request): JsonResponse
    {
        $user = $request->validateCredentials();

        // De app is (voorlopig) alleen voor planners en teamleiders.
        abort_unless($user->canPlan(), 403, 'De app is alleen voor planners en teamleiders. Gebruik de website.');
        abort_if($user->requiresTwoFactor() && ! $user->hasTwoFactor(), 403,
            'Stel eerst tweestapsverificatie in op de website (Mijn profiel). Daarna kun je inloggen in de app.');

        if (! $user->hasTwoFactor()) {
            return $this->issueToken($user, $request->string('device_name'));
        }

        $challenge = Str::random(64);
        Cache::put($this->challengeKey($challenge), [
            'id' => $user->getKey(),
            'device_name' => (string) $request->string('device_name'),
        ], now()->addMinutes(self::CHALLENGE_MINUTES));

        return response()->json(['two_factor' => true, 'challenge' => $challenge]);
    }

    public function twoFactor(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:30'],
        ]);

        $pending = Cache::get($this->challengeKey($data['challenge']));
        $user = is_array($pending) ? User::find($pending['id']) : null;
        if (! $user) {
            throw ValidationException::withMessages(['challenge' => 'Log opnieuw in: de tijd voor de code is verstreken.']);
        }

        // Zelfde teller als op de website: samen hoogstens 5 pogingen per minuut.
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
            Audit::log('auth.2fa_failed', "Verkeerde code bij inloggen in de app van {$user->name}", $user, [], $user);

            throw ValidationException::withMessages([
                filled($data['recovery_code'] ?? null) ? 'recovery_code' : 'code' => 'Deze code klopt niet.',
            ]);
        }

        RateLimiter::clear($key);
        Cache::forget($this->challengeKey($data['challenge']));
        if ($usedRecovery) {
            Audit::log('twofactor.recovery_used', "{$user->name} logde in de app in met een herstelcode", $user, [], $user);
        }

        return $this->issueToken($user->fresh(), $pending['device_name'], $usedRecovery);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();
        Audit::log('auth.app_logout', "{$user->name} uitgelogd in de app", $user);

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->presentUser($request->user())]);
    }

    private function issueToken(User $user, string $deviceName, bool $usedRecovery = false): JsonResponse
    {
        $token = $user->createToken(mb_substr($deviceName, 0, 100), ['plan']);
        Audit::log('auth.app_login', "{$user->name} ingelogd in de app ({$deviceName})", $user, [], $user);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String()
                ?? (config('sanctum.expiration') ? now()->addMinutes((int) config('sanctum.expiration'))->toIso8601String() : null),
            'user' => $this->presentUser($user),
            'recovery_codes_left' => $usedRecovery ? $user->recoveryCodesLeft() : null,
        ]);
    }

    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'can' => [
                'view_audit_log' => $user->can('view-audit-log'),
                'manage_policy' => $user->can('manage-policy'),
            ],
        ];
    }

    private function challengeKey(string $challenge): string
    {
        return 'app-login:'.hash('sha256', $challenge);
    }
}

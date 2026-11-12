<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Tweestapsverificatie met een authenticator-app (TOTP, RFC 6238), issue #40.
 * De QR-code wordt op de server gemaakt (SVG); het geheim gaat nooit naar een externe dienst.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public function __construct(private Google2FA $google2fa) {}

    public function newSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(config('app.name', 'PoetsPloeg'), $user->email, $secret);
    }

    /** QR-code als inline SVG. */
    public function qrSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));
        $svg = $writer->writeString($this->otpauthUrl($user, $secret));

        return trim(preg_replace('/^<\?xml.*?\?>/', '', $svg));
    }

    /** Klopt de code voor dit geheim (bij het instellen, nog zonder opgeslagen geheim)? */
    public function verifySecret(string $secret, string $code): bool
    {
        $code = $this->normalize($code);

        return strlen($code) === 6 && $this->google2fa->verifyKey($secret, $code, 1) !== false;
    }

    /** Controleer een code bij het inloggen; een code werkt maar één keer. */
    public function verify(User $user, string $code): bool
    {
        $code = $this->normalize($code);
        if (! $user->two_factor_secret || strlen($code) !== 6) {
            return false;
        }
        $step = $this->google2fa->verifyKeyNewer($user->two_factor_secret, $code, $user->two_factor_last_step, 1);
        if ($step === false) {
            return false;
        }
        $user->forceFill(['two_factor_last_step' => $step === true ? $this->google2fa->getTimestamp() : $step])->save();

        return true;
    }

    /** Herstelcode gebruiken; daarna is die code niet meer geldig. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = strtolower(trim($code));
        $hashes = $user->two_factor_recovery_codes ?? [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    /**
     * Nieuwe herstelcodes; de vorige vervallen. Geeft de codes één keer leesbaar terug.
     *
     * @return list<string>
     */
    public function newRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))
            ->all();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();

        return $codes;
    }

    public function enable(User $user, string $secret): array
    {
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => null,
        ])->save();

        return $this->newRecoveryCodes($user);
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    private function normalize(string $code): string
    {
        return preg_replace('/\D/', '', $code);
    }
}

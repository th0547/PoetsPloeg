<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Notifications\TwoFactorChanged;
use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Tweestapsverificatie instellen, herstelcodes vernieuwen en (indien toegestaan) uitzetten (issue #40). */
class TwoFactorController extends Controller
{
    private const SETUP_SESSION = 'two_factor.setup_secret';

    public function __construct(private TwoFactor $twoFactor) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $secret = null;
        if (! $user->hasTwoFactor()) {
            // Nieuw geheim per instelpoging; pas na een juiste code wordt het opgeslagen.
            $secret = $request->session()->get(self::SETUP_SESSION) ?? $this->twoFactor->newSecret();
            $request->session()->put(self::SETUP_SESSION, $secret);
        }

        return view('auth.two-factor', [
            'user' => $user,
            'secret' => $secret,
            'qr' => $secret ? $this->twoFactor->qrSvg($user, $secret) : null,
            'recoveryCodes' => $request->session()->get('two_factor.recovery_codes'),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $request->user();
        $secret = $request->session()->get(self::SETUP_SESSION);
        if ($user->hasTwoFactor()) {
            return redirect()->route('two-factor.show');
        }
        if (! $secret || ! $this->twoFactor->verifySecret($secret, $request->input('code'))) {
            throw ValidationException::withMessages(['code' => 'Deze code klopt niet. Controleer of de tijd op je telefoon goed staat en probeer de volgende code.']);
        }

        $codes = $this->twoFactor->enable($user, $secret);
        $request->session()->forget(self::SETUP_SESSION);
        Audit::log('twofactor.enabled', "{$user->name} heeft tweestapsverificatie aangezet", $user);
        $this->notify($user, 'aangezet');

        return redirect()->route('two-factor.show')
            ->with('two_factor.recovery_codes', $codes)
            ->with('status', 'Tweestapsverificatie staat aan. Bewaar je herstelcodes op een veilige plek.');
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->hasTwoFactor(), 404);
        $codes = $this->twoFactor->newRecoveryCodes($user);
        Audit::log('twofactor.recovery_regenerated', "{$user->name} heeft nieuwe herstelcodes gemaakt", $user);

        return redirect()->route('two-factor.show')
            ->with('two_factor.recovery_codes', $codes)
            ->with('status', 'Nieuwe herstelcodes gemaakt. De oude werken niet meer.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        if ($user->requiresTwoFactor()) {
            return back()->withErrors(['two_factor' => 'Tweestapsverificatie is verplicht voor jouw rol en kan niet uit.']);
        }
        $this->twoFactor->disable($user);
        Audit::log('twofactor.disabled', "{$user->name} heeft tweestapsverificatie uitgezet", $user);
        $this->notify($user, 'uitgezet');

        return redirect()->route('two-factor.show')->with('status', 'Tweestapsverificatie staat uit.');
    }

    private function notify($user, string $what): void
    {
        rescue(fn () => $user->notify(new TwoFactorChanged($what)), report: true);
    }
}

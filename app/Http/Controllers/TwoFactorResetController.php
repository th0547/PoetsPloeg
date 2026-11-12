<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\TwoFactorChanged;
use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Een teamleider reset de tweestapsverificatie van een ander account, bijv. bij een nieuwe telefoon (issue #40). */
class TwoFactorResetController extends Controller
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function user(Request $request, User $user): RedirectResponse
    {
        $this->reset($request, $user);

        return redirect()->route('users.index')->with('status', "Tweestapsverificatie van {$user->name} is gereset. Bij de volgende keer inloggen stelt {$user->name} het opnieuw in.");
    }

    public function employee(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->user, 404);
        $this->reset($request, $employee->user);

        return redirect()->route('employees.edit', $employee)->with('status', "Tweestapsverificatie van {$employee->name} is gereset.");
    }

    private function reset(Request $request, User $target): void
    {
        abort_if($target->is($request->user()), 422, 'Je eigen tweestapsverificatie beheer je bij Mijn profiel.');
        abort_unless($target->hasTwoFactor(), 422, 'Dit account heeft geen tweestapsverificatie.');

        $this->twoFactor->disable($target);
        Audit::log('twofactor.reset', "Tweestapsverificatie van {$target->name} gereset", $target);
        rescue(fn () => $target->notify(new TwoFactorChanged('gereset', $request->user()->name)), report: true);
    }
}

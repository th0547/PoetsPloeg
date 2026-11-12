<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use App\Services\TwoFactor;
use Illuminate\Console\Command;

class ResetTwoFactor extends Command
{
    protected $signature = 'poetsploeg:reset-2fa {email : E-mailadres van het account}';

    protected $description = 'Noodroute: tweestapsverificatie van een account uitzetten (bijv. als geen teamleider meer kan inloggen)';

    public function handle(TwoFactor $twoFactor): int
    {
        $user = User::where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Geen account met dit e-mailadres.');

            return self::FAILURE;
        }
        if (! $user->hasTwoFactor()) {
            $this->warn("{$user->name} heeft geen tweestapsverificatie.");

            return self::SUCCESS;
        }
        $twoFactor->disable($user);
        Audit::log('twofactor.reset', "Tweestapsverificatie van {$user->name} gereset via de server (artisan)", $user);
        $this->info("Tweestapsverificatie van {$user->name} is uitgezet. Bij de volgende keer inloggen wordt het opnieuw ingesteld.");

        return self::SUCCESS;
    }
}

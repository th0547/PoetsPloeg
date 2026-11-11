<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Notifications\EmployeeInvitation;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Inlogaccounts voor medewerkers: aanmaken, uitnodigen en bijhouden. */
class EmployeeAccounts
{
    /** Maak een account met de rol Medewerker en stuur de uitnodiging om een wachtwoord in te stellen. */
    public function invite(Employee $employee): User
    {
        $user = $employee->user ?? new User;
        $user->forceFill([
            'name' => $employee->name,
            'email' => $employee->email,
            'role' => 'medewerker',
            'employee_id' => $employee->id,
        ]);
        if (! $user->exists) {
            // Niemand kent dit wachtwoord: inloggen kan pas na het instellen via de uitnodiging.
            $user->password = Str::random(64);
        }
        $user->save();
        $employee->setRelation('user', $user);

        $this->sendInvitation($user);

        return $user;
    }

    /** (Opnieuw) een uitnodiging sturen; een eerdere link vervalt daarmee. */
    public function sendInvitation(User $user): void
    {
        $token = Password::broker('invites')->createToken($user);
        $user->notify(new EmployeeInvitation($token));
    }

    /**
     * Naam en e-mailadres van het account gelijk houden aan de medewerkergegevens. Wie nog geen
     * wachtwoord had ingesteld, krijgt de uitnodiging opnieuw op het nieuwe adres.
     *
     * @return bool of er een nieuwe uitnodiging is verstuurd
     */
    public function sync(Employee $employee): bool
    {
        $user = $employee->user;
        if (! $user) {
            return false;
        }
        $user->name = $employee->name;
        $emailChanged = $employee->email && $employee->email !== $user->email;
        if ($emailChanged) {
            // Een link naar het oude adres werkt niet meer: die hoort niet bij een account.
            Password::broker('invites')->getRepository()->delete($user);
            $user->email = $employee->email;
        }
        $user->save();

        if ($emailChanged && $user->invitationPending()) {
            $this->sendInvitation($user);

            return true;
        }

        return false;
    }
}

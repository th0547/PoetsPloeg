<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreatePlanner extends Command
{
    protected $signature = 'poetsploeg:create-planner {--name=} {--email=} {--role=planner}';

    protected $description = 'Maak een planner- of teamleideraccount aan (bijv. het eerste account na installatie)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Naam');
        $email = $this->option('email') ?: $this->ask('E-mailadres');
        $password = $this->secret('Wachtwoord (min. 8 tekens)');

        $validator = Validator::make(compact('name', 'email', 'password') + ['role' => $this->option('role')], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'in:'.implode(',', User::STAFF_ROLES)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => $this->option('role'),
        ]);
        $this->info("Account voor {$email} aangemaakt.");

        return self::SUCCESS;
    }
}

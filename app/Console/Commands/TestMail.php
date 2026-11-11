<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class TestMail extends Command
{
    protected $signature = 'poetsploeg:test-mail {adres : E-mailadres waar de testmail heen gaat}';

    protected $description = 'Stuur een testmail om de mailinstellingen (bijv. Brevo) te controleren';

    public function handle(): int
    {
        $address = (string) $this->argument('adres');
        if (Validator::make(['adres' => $address], ['adres' => ['required', 'email']])->fails()) {
            $this->error('Geen geldig e-mailadres.');

            return self::FAILURE;
        }

        $mailer = config('mail.default');
        $this->line("Mailer: {$mailer}".($mailer === 'smtp' ? ' ('.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port').')' : ''));
        $this->line('Afzender: '.config('mail.from.address'));

        try {
            Mail::raw('Dit is een testmail van '.config('app.name').'. De mailinstellingen werken.', function ($message) use ($address) {
                $message->to($address)->subject('Testmail '.config('app.name'));
            });
        } catch (\Throwable $e) {
            $this->error('Versturen mislukt: '.$e->getMessage());

            return self::FAILURE;
        }

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->warn("De mailer is '{$mailer}': er is niets echt verstuurd. Zet MAIL_MAILER=smtp in .env.");

            return self::SUCCESS;
        }
        $this->info("Testmail verstuurd naar {$address}.");

        return self::SUCCESS;
    }
}

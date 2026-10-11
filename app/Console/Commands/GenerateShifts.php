<?php

namespace App\Console\Commands;

use App\Services\ShiftGenerator;
use Illuminate\Console\Command;

class GenerateShifts extends Command
{
    protected $signature = 'novon:generate-shifts';

    protected $description = 'Maak diensten uit de vaste taken aan voor de komende weken';

    public function handle(ShiftGenerator $generator): int
    {
        $count = $generator->generateAhead();
        $this->info("{$count} dienst(en) aangemaakt.");

        return self::SUCCESS;
    }
}

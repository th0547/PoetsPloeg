<?php

namespace App\Console\Commands;

use App\Services\BuildingKnowledge;
use Illuminate\Console\Command;

class LearnBuildings extends Command
{
    protected $signature = 'poetsploeg:learn-buildings';

    protected $description = 'Markeer medewerkers als ingewerkt op panden waar ze al een aantal diensten hebben gedraaid';

    public function handle(BuildingKnowledge $knowledge): int
    {
        $this->info($knowledge->learn().' pand(en) toegevoegd aan bekende panden.');

        return self::SUCCESS;
    }
}

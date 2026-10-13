<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\AvailabilityEnforcer;
use Illuminate\Console\Command;

class ReleaseUnavailable extends Command
{
    protected $signature = 'novon:release-unavailable';

    protected $description = 'Haal medewerkers van toekomstige diensten die buiten hun beschikbaarheid vallen (eenmalig opschonen)';

    public function handle(AvailabilityEnforcer $enforcer): int
    {
        $total = 0;
        foreach (Employee::has('availabilityPatterns')->with('availabilityPatterns')->get() as $employee) {
            $released = $enforcer->release($employee);
            foreach ($released as $shift) {
                $this->line(sprintf('%s: %s %s · %s', $employee->name, $shift->starts_at->format('d-m H:i'), $shift->building->name, $shift->title));
            }
            $total += $released->count();
        }
        $this->info("{$total} toewijzing(en) verwijderd; deze diensten staan nu bij Open diensten.");

        return self::SUCCESS;
    }
}

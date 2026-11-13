<?php

namespace App\Console\Commands;

use App\Models\Absence;
use App\Services\LeaveHours;
use Illuminate\Console\Command;

class BackfillLeaveHours extends Command
{
    protected $signature = 'poetsploeg:leave-hours {--all : Ook afwezigheid die al uren heeft opnieuw schatten (behalve handmatig ingevulde)}';

    protected $description = 'Verlofuren invullen voor bestaande afwezigheid (schatting: diensten, vaste taken, contracturen)';

    public function handle(LeaveHours $leaveHours): int
    {
        $count = 0;
        Absence::withTrashed()->with(['employee.availabilityPatterns', 'employee.availabilityDays'])
            ->when(! $this->option('all'), fn ($q) => $q->whereNull('hours'))
            ->where(fn ($q) => $q->whereNull('hours_source')->orWhere('hours_source', '!=', 'handmatig'))
            ->chunkById(200, function ($absences) use ($leaveHours, &$count) {
                foreach ($absences as $absence) {
                    $leaveHours->fill($absence);
                    if ($absence->hours_source !== null) {
                        $absence->hours_source = 'schatting';
                    }
                    $absence->saveQuietly();
                    $count++;
                }
            });
        $this->info("{$count} afwezigheid bijgewerkt.");

        return self::SUCCESS;
    }
}

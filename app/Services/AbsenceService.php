<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Shift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Verwerkt verlof en uitval: de medewerker wordt van getroffen diensten gehaald,
 * zodat die als open dienst zichtbaar worden en een vervanger kan worden gezocht.
 */
class AbsenceService
{
    /**
     * Diensten (vanaf nu) waarop de medewerker staat tijdens de afwezigheid.
     *
     * @return Collection<int, Shift>
     */
    public function affectedShifts(Absence $absence): Collection
    {
        return Shift::query()
            ->active()
            ->overlapping($absence->starts_at, $absence->ends_at)
            ->where('ends_at', '>', now())
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $absence->employee_id))
            ->with('building')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Haal de medewerker van alle getroffen diensten af.
     *
     * @return Collection<int, Shift> de diensten die nu (deels) open staan
     */
    public function apply(Absence $absence): Collection
    {
        $shifts = $this->affectedShifts($absence);

        DB::transaction(function () use ($shifts, $absence) {
            foreach ($shifts as $shift) {
                $shift->assignments()->where('employee_id', $absence->employee_id)->delete();
                $shift->update(['is_modified' => true]);
            }
        });

        return $shifts;
    }
}

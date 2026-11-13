<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Haalt een medewerker van toekomstige diensten die buiten zijn of haar beschikbaarheid vallen
 * ("kan niet", of buiten de "kan wel"-tijden). Die diensten komen daarmee bij de open diensten.
 */
class AvailabilityEnforcer
{
    /**
     * @return Collection<int, Shift> de diensten waar de medewerker af is gehaald
     */
    public function release(Employee $employee): Collection
    {
        $schedule = $employee->availabilitySchedule();
        if ($schedule->isEmpty()) {
            return collect();
        }

        $shifts = Shift::query()->active()
            ->where('starts_at', '>', now())
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $employee->id))
            ->with('building')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Shift $s) => AvailabilityCheck::isOutside($schedule, $s->starts_at, $s->ends_at))
            ->values();

        DB::transaction(function () use ($shifts, $employee) {
            foreach ($shifts as $shift) {
                $shift->assignments()->where('employee_id', $employee->id)->delete();
            }
        });

        return $shifts;
    }
}

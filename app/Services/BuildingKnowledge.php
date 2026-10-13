<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * "Je bent er nu een paar keer geweest, nu ken je het pand": wie een aantal diensten op een pand
 * heeft gedraaid, wordt automatisch als ingewerkt op dat pand gemarkeerd.
 */
class BuildingKnowledge
{
    /**
     * @return int aantal nieuw toegevoegde (medewerker, pand)-combinaties
     */
    public function learn(?int $employeeId = null): int
    {
        $threshold = max(1, (int) config('novon.learn_building_after_shifts'));

        $pairs = DB::table('shift_assignments')
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->whereNull('shifts.cancelled_at')
            ->where('shifts.ends_at', '<=', now())
            ->when($employeeId, fn ($q) => $q->where('shift_assignments.employee_id', $employeeId))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('building_employee')
                ->whereColumn('building_employee.building_id', 'shifts.building_id')
                ->whereColumn('building_employee.employee_id', 'shift_assignments.employee_id'))
            ->groupBy('shift_assignments.employee_id', 'shifts.building_id')
            ->havingRaw('count(*) >= ?', [$threshold])
            ->get(['shift_assignments.employee_id', 'shifts.building_id']);

        foreach ($pairs as $pair) {
            DB::table('building_employee')->insertOrIgnore([
                'building_id' => $pair->building_id,
                'employee_id' => $pair->employee_id,
            ]);
        }

        return $pairs->count();
    }
}

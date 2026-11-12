<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\AvailabilityWeek;
use App\Models\Employee;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Beschikbare uren per week (issue #45): het weekpatroon (kan wel / kan niet) min afwezigheid
 * en "Niet beschikbaar". Zonder "kan wel"-tijdvakken telt een dag als helemaal beschikbaar.
 */
class WeekAvailability
{
    /** Eerste week die bevestigd moet worden (maandag), volgens het beleid. */
    public static function targetWeek(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();

        $policy = Policy::at($now);
        // Altijd vóórdat het rooster van die week bekend moet zijn (roostertermijn + 1).
        $ahead = max($policy->get('availability.confirm_weeks_ahead'), $policy->get('roster.notice_weeks') + 1);

        return $now->startOfWeek()->addWeeks($ahead);
    }

    /** Weken die een medewerker kan bevestigen: volgende week t/m de doelweek. */
    public static function weeks(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $target = self::targetWeek($now);
        $weeks = collect();
        for ($w = $now->startOfWeek()->addWeek(); $w->lte($target); $w = $w->addWeek()) {
            $weeks->push($w);
        }

        return $weeks;
    }

    /** Beschikbare uren in de week die op $weekStart (maandag) begint. */
    public function hours(Employee $employee, CarbonImmutable $weekStart): float
    {
        $employee->loadMissing('availabilityPatterns');
        $patterns = $employee->availabilityPatterns;
        $hasAvailable = $patterns->where('type', 'available')->isNotEmpty();
        $absences = Absence::query()->effective()
            ->where('employee_id', $employee->id)
            ->where('starts_at', '<', $weekStart->addWeek())
            ->where('ends_at', '>', $weekStart)
            ->get();

        $minutes = 0;
        for ($d = 0; $d < 7; $d++) {
            $day = $weekStart->addDays($d);
            // Minuut-voor-minuut: begin met alles (geen voorkeur) of niets (alleen "kan wel" telt).
            $free = array_fill(0, 1440, ! $hasAvailable);
            foreach (['available' => true, 'unavailable' => false] as $type => $value) {
                foreach ($patterns->where('type', $type) as $p) {
                    if (! $p->occursOn($day)) {
                        continue;
                    }
                    [$from, $to] = [$this->minutes($p->start_time), $this->minutes($p->end_time)];
                    $to = $to <= $from ? 1440 : $to;
                    for ($m = $from; $m < $to; $m++) {
                        $free[$m] = $value;
                    }
                }
            }
            foreach ($absences as $absence) {
                $from = max(0, (int) $day->diffInMinutes($absence->starts_at, false));
                $to = min(1440, (int) $day->diffInMinutes($absence->ends_at, false) + 1);
                for ($m = $from; $m < $to; $m++) {
                    $free[$m] = false;
                }
            }
            $minutes += count(array_filter($free));
        }

        return round($minutes / 60, 2);
    }

    /** Waarschuwing nodig: beschikbaar onder de (minimale) contracturen? */
    public function belowContract(Employee $employee, float $hours, ?CarbonImmutable $weekStart = null): bool
    {
        $contract = $employee->contract_hours_min;

        return $contract !== null && $hours < (float) $contract
            && Policy::at($weekStart ?? CarbonImmutable::today())->get('contract.signal_available_below_contract');
    }

    /**
     * Na een wijziging van het weekpatroon of "Niet beschikbaar": bevestigde weken bijwerken.
     * locked = wijzigen geweigerd (de aanroeper controleert dat vooraf), request = markeren voor de planner, free = alleen bijwerken.
     */
    public function afterChange(Employee $employee): void
    {
        $mode = Policy::current()->get('availability.after_confirm');
        $employee->unsetRelation('availabilityPatterns');
        foreach ($this->confirmedFuture($employee) as $week) {
            $hours = $this->hours($employee, CarbonImmutable::instance($week->week_start));
            if (abs($hours - (float) $week->available_hours) < 0.01) {
                continue;
            }
            $week->forceFill(['available_hours' => $hours, 'changed_after_confirm' => $mode === 'request' || $week->changed_after_confirm])->save();
        }
    }

    /** @return Collection<int, AvailabilityWeek> */
    public function confirmedFuture(Employee $employee): Collection
    {
        return AvailabilityWeek::where('employee_id', $employee->id)
            ->whereNotNull('confirmed_at')
            ->whereDate('week_start', '>=', CarbonImmutable::today()->startOfWeek())
            ->orderBy('week_start')
            ->get();
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return min(1440, $h * 60 + $m);
    }
}

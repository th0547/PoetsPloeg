<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\TaskTemplate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Uren personeel (duur × aantal personen) voor de pandpagina: wat de vaste taken per week vragen, en wat er in
 * een bepaalde week echt is ingepland.
 */
class StaffHours
{
    /**
     * Uren personeel per week voor een vaste taak: duur × aantal personen, gemiddeld over de hele weken van het komende
     * jaar waarin de taak geldt (een halve week aan het begin of eind telt niet mee). Feestdagen tellen als gewone dag:
     * dit is wat de taak in een normale week vraagt. Patronen om de week of per maand geven zo een eerlijk gemiddelde.
     */
    public function perWeek(TaskTemplate $template, ?CarbonImmutable $from = null): float
    {
        if (! $template->active) {
            return 0.0;
        }
        $monday = ($from ?? CarbonImmutable::today())->startOfWeek();
        $validFrom = CarbonImmutable::instance($template->valid_from)->startOfDay();
        $validUntil = $template->valid_until ? CarbonImmutable::instance($template->valid_until)->startOfDay() : null;
        // Elke week gelijk: één hele week rekenen is genoeg. Anders het komende jaar.
        $regular = self::isRegular($template);
        [$minutes, $weeks] = [0, 0];
        for ($w = 0; $w < 52 && ! ($regular && $weeks === 1); $w++) {
            $start = $monday->addWeeks($w);
            if ($start->lt($validFrom) || ($validUntil && $start->addDays(6)->gt($validUntil))) {
                continue;
            }
            for ($d = 0; $d < 7; $d++) {
                $minutes += $this->minutesOn($template, $start->addDays($d));
            }
            $weeks++;
        }
        if ($weeks === 0) {
            // Geldt de taak korter dan een hele week: dan wat er in die dagen valt.
            for ($d = 0; $d < 14; $d++) {
                $day = $monday->addDays($d);
                $minutes += $template->validOn($day) ? $this->minutesOn($template, $day) : 0;
            }
            $weeks = 1;
        }
        $hours = $minutes / 60 * max(1, $template->required_staff) / $weeks;

        // Elke week gelijk: exact (bijv. 3,75 u). Een gemiddelde (om de week, per maand) op één decimaal.
        return round($hours, $regular ? 2 : 1);
    }

    /** Minuten werk op deze dag; twee patronen op dezelfde dag en begintijd geven één dienst (zoals in de ShiftGenerator). */
    private function minutesOn(TaskTemplate $template, CarbonImmutable $day): int
    {
        $minutes = 0;
        $starts = [];
        foreach ($template->patterns as $pattern) {
            if (! $pattern->occursOn($day, $template)) {
                continue;
            }
            [$startsAt, $endsAt] = ShiftGenerator::timesFor($day, $pattern->startTime($template), $pattern->endTime($template));
            if (! isset($starts[$startsAt->format('H:i')])) {
                $starts[$startsAt->format('H:i')] = true;
                $minutes += (int) $startsAt->diffInMinutes($endsAt);
            }
        }

        return $minutes;
    }

    /** Valt de taak elke week op dezelfde manier (alleen "elke week op …")? Anders is perWeek() een gemiddelde. */
    public static function isRegular(TaskTemplate $template): bool
    {
        return $template->patterns->every(fn ($p) => $p->recurrence === 'weekly' && (int) $p->repeat_every === 1);
    }

    /**
     * Wat er in deze diensten is ingepland, in uren personeel.
     *
     * @param  Collection<int, Shift>  $shifts  met assignments.employee geladen
     * @return array{needed: float, planned: float, open: float, per_row: array<int|string, array{needed: float, planned: float}>, per_employee: list<array{employee: Employee, hours: float}>}
     */
    public function forShifts(Collection $shifts): array
    {
        $result = ['needed' => 0.0, 'planned' => 0.0, 'open' => 0.0, 'per_row' => [], 'per_employee' => []];
        $employees = [];
        foreach ($shifts as $shift) {
            $hours = $shift->durationHours();
            $key = $shift->task_template_id ?? 'los';
            $needed = $hours * $shift->required_staff;
            $planned = $hours * $shift->assignments->count();
            $result['needed'] += $needed;
            $result['planned'] += $planned;
            $result['open'] += $hours * $shift->openSlots();
            $result['per_row'][$key]['needed'] = ($result['per_row'][$key]['needed'] ?? 0) + $needed;
            $result['per_row'][$key]['planned'] = ($result['per_row'][$key]['planned'] ?? 0) + $planned;
            foreach ($shift->assignments as $assignment) {
                $employees[$assignment->employee_id] ??= ['employee' => $assignment->employee, 'hours' => 0.0];
                $employees[$assignment->employee_id]['hours'] += $hours;
            }
        }
        $result['per_employee'] = collect($employees)->sortBy([['hours', 'desc'], fn ($a, $b) => strcmp($a['employee']->name, $b['employee']->name)])->values()->all();

        return $result;
    }
}

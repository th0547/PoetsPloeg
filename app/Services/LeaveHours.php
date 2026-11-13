<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\TaskTemplate;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Verlofuren (issue #43): "de uren die je normaal op deze dagen zou werken" (cao art. 30/31).
 * Per dag, in deze volgorde:
 *  1. diensten waarop de medewerker staat;
 *  2. vaste taken waarop de medewerker vaste bezetting is;
 *  3. contracturen gedeeld door het aantal werkdagen volgens het weekpatroon.
 */
class LeaveHours
{
    /** Volgorde van de bronnen, voor "gemengd". */
    public const SOURCES = ['dienst' => 'geplande diensten', 'vaste bezetting' => 'vaste taken', 'contract' => 'contracturen', 'gemengd' => 'diensten, vaste taken en contract', 'handmatig' => 'door de planner ingevuld', 'schatting' => 'schatting (oude registratie)'];

    /**
     * @return array{hours: float, days: int, source: ?string, per_day: array<string, array{hours: float, source: ?string}>}
     */
    public function estimate(Employee $employee, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);
        $shifts = Shift::query()->active()
            ->overlapping($from, $to)
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $employee->id))
            ->get(['id', 'starts_at', 'ends_at']);
        $templates = $employee->fixedTemplates()->where('task_templates.active', true)->with('patterns')->get();
        $workdays = $this->workdays($employee);
        $schedule = $employee->availabilitySchedule();
        $perWorkday = $workdays ? ((float) ($employee->contract_hours_min ?? $employee->contract_hours_max ?? 0)) / count($workdays) : 0.0;

        $perDay = [];
        for ($day = $from->startOfDay(); $day->lt($to); $day = $day->addDay()) {
            $windowStart = $from->max($day);
            $windowEnd = $to->min($day->addDay());
            if ($windowEnd->lte($windowStart)) {
                continue;
            }
            $hours = 0.0;
            $source = null;

            $dayShifts = $shifts->filter(fn (Shift $s) => $s->starts_at->isSameDay($day));
            if ($dayShifts->isNotEmpty()) {
                $hours = $dayShifts->sum(fn (Shift $s) => $this->overlapHours($s->starts_at, $s->ends_at, $from, $to));
                $source = 'dienst';
            } elseif (($fixed = $this->fixedHours($templates, $day, $from, $to)) !== null) {
                [$hours, $source] = [$fixed, 'vaste bezetting'];
            } elseif ($perWorkday > 0 && ($schedule->hasDateRules($day) ? $schedule->isWorkday($day) : in_array($day->dayOfWeekIso, $workdays, true))) {
                // Losse datum (bijv. "hele dag niet") gaat vóór het weekpatroon.
                $hours = min($perWorkday, $windowStart->diffInMinutes($windowEnd) / 60);
                $source = 'contract';
            }

            $perDay[$day->toDateString()] = ['hours' => round($hours, 2), 'source' => $source];
        }

        $sources = collect($perDay)->pluck('source')->filter()->unique()->values();

        return [
            'hours' => round(collect($perDay)->sum('hours'), 2),
            'days' => collect($perDay)->filter(fn ($d) => $d['hours'] > 0)->count(),
            'source' => $sources->count() > 1 ? 'gemengd' : $sources->first(),
            'per_day' => $perDay,
        ];
    }

    /** Uren en bron invullen op een (nieuwe) afwezigheid. "Niet beschikbaar" telt nooit als verlof. */
    public function fill(Absence $absence): void
    {
        if (($absence->leaveType()['counts_as'] ?? null) === 'unavailable' || ! $absence->employee) {
            $absence->forceFill(['hours' => 0, 'days' => 0, 'hours_source' => null]);

            return;
        }
        $estimate = $this->estimate($absence->employee, $absence->starts_at, $absence->ends_at);
        $absence->forceFill(['hours' => $estimate['hours'], 'days' => $estimate['days'], 'hours_source' => $estimate['source']]);
    }

    /**
     * Ziek tijdens goedgekeurde vakantie (cao art. 29 lid 2): de vakantie-uren op de ziektedagen vervallen.
     *
     * @return float aantal uren dat van vakantie naar ziek is gegaan
     */
    public function applySickness(Absence $sickness): float
    {
        if (! Policy::at($sickness->starts_at)->get('vacation.sickness_not_vacation')) {
            return 0.0;
        }
        $moved = 0.0;
        $vacations = Absence::query()->effective()
            ->where('employee_id', $sickness->employee_id)
            ->where('id', '!=', $sickness->id)
            ->where('starts_at', '<', $sickness->ends_at)
            ->where('ends_at', '>', $sickness->starts_at)
            ->get()
            ->filter(fn (Absence $a) => ($a->leaveType()['counts_as'] ?? null) === 'vacation');

        foreach ($vacations as $vacation) {
            $estimate = $this->estimate($vacation->employee, $vacation->starts_at, $vacation->ends_at);
            $sickDays = collect($estimate['per_day'])->filter(function ($d, $date) use ($sickness) {
                $day = CarbonImmutable::parse($date);

                return $sickness->starts_at->lt($day->addDay()) && $sickness->ends_at->gt($day);
            });
            $remove = min((float) $vacation->hours, (float) $sickDays->sum('hours'));
            if ($remove <= 0) {
                continue;
            }
            $vacation->forceFill([
                'hours' => round((float) $vacation->hours - $remove, 2),
                'days' => max(0, (int) $vacation->days - $sickDays->filter(fn ($d) => $d['hours'] > 0)->count()),
            ])->save();
            $moved += $remove;
        }

        return round($moved, 2);
    }

    /** Uren van vaste taken op deze dag binnen de periode, of null als er geen vaste taak is. */
    private function fixedHours(Collection $templates, CarbonImmutable $day, CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $hours = null;
        foreach ($templates as $template) {
            /** @var TaskTemplate $template */
            if ($template->skip_holidays && app(DutchHolidays::class)->isSkippable($day)) {
                continue;
            }
            foreach ($template->patternsOn($day) as $pattern) {
                [$start, $end] = ShiftGenerator::timesFor($day, $pattern->startTime($template), $pattern->endTime($template));
                $hours = ($hours ?? 0) + $this->overlapHours($start, $end, $from, $to);
            }
        }

        return $hours;
    }

    /** Werkdagen (ISO 1–7) volgens het weekpatroon: dagen met "kan wel", anders ma t/m vr zonder "kan niet" de hele dag. */
    private function workdays(Employee $employee): array
    {
        $patterns = $employee->availabilityPatterns;
        $available = $patterns->where('type', 'available')->where('repeat_unit', 'week')->pluck('weekday')->map(fn ($d) => (int) $d)->unique()->values()->all();
        if ($available) {
            sort($available);

            return $available;
        }
        $blocked = $patterns->where('type', 'unavailable')->where('repeat_unit', 'week')->where('repeat_every', 1)
            ->filter(fn ($p) => substr($p->start_time, 0, 5) <= '00:00' && substr($p->end_time, 0, 5) >= '23:59')
            ->pluck('weekday')->map(fn ($d) => (int) $d)->all();

        return array_values(array_diff([1, 2, 3, 4, 5], $blocked));
    }

    private function overlapHours(\DateTimeInterface $aStart, \DateTimeInterface $aEnd, CarbonImmutable $bStart, CarbonImmutable $bEnd): float
    {
        $start = max(CarbonImmutable::instance($aStart), $bStart);
        $end = min(CarbonImmutable::instance($aEnd), $bEnd);

        return $end->gt($start) ? $start->diffInMinutes($end) / 60 : 0.0;
    }
}

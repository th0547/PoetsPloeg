<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Controleert toewijzingen van medewerkers aan diensten: dubbel ingepland, afwezig, buiten
 * beschikbaarheid, ontbrekende vaardigheid, onbekend pand en contracturen.
 *
 * Alle benodigde gegevens worden per aanroep in één keer geladen, zodat een hele week
 * of maand zonder N+1-queries kan worden gecontroleerd.
 */
class ConflictChecker
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /** @var Collection<int, Employee> */
    private Collection $employees;

    /** @var Collection<int, Collection<int, Absence>> */
    private Collection $absences;

    /** @var Collection<int, Collection<int, object>> toewijzingen per medewerker */
    private Collection $assignments;

    /** @var array<int, string> */
    private array $skillNames = [];

    /**
     * Waarschuwingen voor alle bestaande toewijzingen van de gegeven diensten.
     *
     * @param  Collection<int, Shift>  $shifts  met relaties assignments en skills geladen
     * @return array<int, array<int, list<array{type: string, level: string, message: string}>>> [shift_id][employee_id]
     */
    public function forShifts(Collection $shifts): array
    {
        $shifts = $shifts->filter(fn (Shift $s) => $s->assignments->isNotEmpty());
        if ($shifts->isEmpty()) {
            return [];
        }

        $this->load(
            $shifts->flatMap->assignments->pluck('employee_id')->unique()->values(),
            $shifts->min('starts_at'),
            $shifts->max('ends_at'),
        );

        $result = [];
        foreach ($shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                $result[$shift->id][$assignment->employee_id] = $this->evaluate($shift, $assignment->employee_id)['warnings'];
            }
        }

        return $result;
    }

    /**
     * Wat als deze medewerker aan deze dienst wordt toegewezen? De dienst mag ongesaved gewijzigde tijden hebben.
     *
     * @return array{warnings: list<array>, checks: array}
     */
    public function forCandidate(Shift $shift, int $employeeId): array
    {
        return $this->forCandidates($shift, collect([$employeeId]))[$employeeId];
    }

    /**
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, array{warnings: list<array>, checks: array}>
     */
    public function forCandidates(Shift $shift, Collection $employeeIds): array
    {
        $shift->loadMissing('skills');
        $this->load($employeeIds, $shift->starts_at, $shift->ends_at);

        $result = [];
        foreach ($employeeIds as $id) {
            $result[$id] = $this->evaluate($shift, $id);
        }

        return $result;
    }

    /**
     * Geplande uren per medewerker in de ISO-week die op weekStart begint.
     *
     * @return array<int, float>
     */
    public function weekHours(Collection $employeeIds, \DateTimeInterface $weekStart): array
    {
        $start = CarbonImmutable::instance($weekStart)->startOfWeek();
        $this->load($employeeIds, $start, $start->endOfWeek());

        return $employeeIds->mapWithKeys(fn ($id) => [$id => $this->hoursInWeek($id, $start)])->all();
    }

    private function load(Collection $employeeIds, \DateTimeInterface $from, \DateTimeInterface $to): void
    {
        // Volledige ISO-weken laden voor de urentelling, plus een dag marge voor nachtdiensten.
        $windowStart = CarbonImmutable::instance($from)->startOfWeek()->subDay();
        $windowEnd = CarbonImmutable::instance($to)->endOfWeek()->addDay();

        $this->employees = Employee::query()
            ->with(['skills:id', 'buildings:id', 'availabilityPatterns'])
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $this->absences = Absence::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->get()
            ->groupBy('employee_id');

        $this->assignments = DB::table('shift_assignments')
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->join('buildings', 'buildings.id', '=', 'shifts.building_id')
            ->whereIn('shift_assignments.employee_id', $employeeIds)
            ->whereNull('shifts.cancelled_at')
            ->where('shifts.starts_at', '<', $windowEnd)
            ->where('shifts.ends_at', '>', $windowStart)
            ->get([
                'shift_assignments.employee_id', 'shifts.id as shift_id', 'shifts.starts_at', 'shifts.ends_at',
                'shifts.title', 'buildings.name as building_name',
            ])
            ->map(function ($row) {
                $row->starts_at = CarbonImmutable::parse($row->starts_at);
                $row->ends_at = CarbonImmutable::parse($row->ends_at);

                return $row;
            })
            ->groupBy('employee_id');

        if ($this->skillNames === []) {
            $this->skillNames = Skill::pluck('name', 'id')->all();
        }
    }

    /**
     * @return array{warnings: list<array>, checks: array}
     */
    private function evaluate(Shift $shift, int $employeeId): array
    {
        $employee = $this->employees->get($employeeId);
        $start = CarbonImmutable::instance($shift->starts_at);
        $end = CarbonImmutable::instance($shift->ends_at);
        $warnings = [];

        if (! $employee) {
            return ['warnings' => [], 'checks' => []];
        }

        if (! $employee->active) {
            $warnings[] = $this->warning('inactive', self::WARNING, 'Medewerker is niet actief');
        }

        $absence = ($this->absences[$employeeId] ?? collect())
            ->first(fn (Absence $a) => $a->starts_at->lt($end) && $a->ends_at->gt($start));
        if ($absence) {
            $warnings[] = $this->warning('absent', self::DANGER, sprintf(
                'Afwezig (%s %s t/m %s)',
                strtolower($absence->typeLabel()),
                $absence->starts_at->format('d-m H:i'),
                $absence->ends_at->format('d-m H:i'),
            ));
        }

        $mine = $this->assignments[$employeeId] ?? collect();
        $overlap = $mine->first(fn ($a) => $a->shift_id !== $shift->id && $a->starts_at->lt($end) && $a->ends_at->gt($start));
        if ($overlap) {
            $warnings[] = $this->warning('overlap', self::DANGER, sprintf(
                'Dubbel ingepland: %s – %s (%s–%s)',
                $overlap->building_name, $overlap->title, $overlap->starts_at->format('H:i'), $overlap->ends_at->format('H:i'),
            ));
        }

        $availability = $this->availability($employee, $start, $end);
        if ($availability === 'fail') {
            $warnings[] = $this->warning('availability', self::WARNING, 'Buiten opgegeven beschikbaarheid');
        }

        $missing = $shift->skills->pluck('id')->diff($employee->skills->pluck('id'))
            ->map(fn ($id) => $this->skillNames[$id] ?? '?')->values()->all();
        if ($missing) {
            $warnings[] = $this->warning('skill', self::WARNING, 'Mist vaardigheid: '.implode(', ', $missing));
        }

        $knowsBuilding = $employee->buildings->contains('id', $shift->building_id);
        if (! $knowsBuilding) {
            $warnings[] = $this->warning('building', self::INFO, 'Niet ingewerkt op dit pand');
        }

        $weekStart = $start->startOfWeek();
        $weekHours = $this->hoursInWeek($employeeId, $weekStart, $shift->id);
        $newWeekHours = round($weekHours + $start->diffInMinutes($end) / 60, 2);
        $max = $employee->contract_hours_max;
        if ($max !== null && $newWeekHours > $max) {
            $warnings[] = $this->warning('hours', self::WARNING, sprintf(
                'Boven contracturen: %s / %s u deze week', $this->num($newWeekHours), $this->num($max),
            ));
        }

        return [
            'warnings' => $warnings,
            'checks' => [
                'absent' => (bool) $absence,
                'overlap' => (bool) $overlap,
                'availability' => $availability,
                'missing_skills' => $missing,
                'knows_building' => $knowsBuilding,
                'week_hours' => $weekHours,
                'new_week_hours' => $newWeekHours,
                'contract_min' => $employee->contract_hours_min,
                'contract_max' => $max,
            ],
        ];
    }

    /**
     * 'ok' als de dienst binnen een tijdvak van het weekpatroon valt, 'none' als er geen patroon is, anders 'fail'.
     */
    private function availability(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $patterns = $employee->availabilityPatterns;
        if ($patterns->isEmpty()) {
            return 'none';
        }

        $startMin = $start->hour * 60 + $start->minute;
        $endMin = $startMin + (int) $start->diffInMinutes($end);

        $fits = $patterns
            ->where('weekday', $start->dayOfWeekIso)
            ->contains(function ($p) use ($startMin, $endMin) {
                $pStart = $this->minutes($p->start_time);
                $pEnd = $this->minutes($p->end_time);
                if ($pEnd <= $pStart) {
                    $pEnd += 1440; // tot middernacht of later
                }

                return $pStart <= $startMin && $pEnd >= $endMin;
            });

        return $fits ? 'ok' : 'fail';
    }

    private function hoursInWeek(int $employeeId, CarbonImmutable $weekStart, ?int $excludeShiftId = null): float
    {
        $weekEnd = $weekStart->addWeek();
        $minutes = ($this->assignments[$employeeId] ?? collect())
            ->filter(fn ($a) => $a->shift_id !== $excludeShiftId && $a->starts_at->gte($weekStart) && $a->starts_at->lt($weekEnd))
            ->sum(fn ($a) => $a->starts_at->diffInMinutes($a->ends_at));

        return round($minutes / 60, 2);
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }

    private function warning(string $type, string $level, string $message): array
    {
        return compact('type', 'level', 'message');
    }
}

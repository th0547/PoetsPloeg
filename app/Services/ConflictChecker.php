<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Support\Policy;
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
            ->with(['skills:id', 'buildings:id', 'availabilityPatterns', 'availabilityDays'])
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $this->absences = Absence::query()->notRejected()
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

        $overlapping = ($this->absences[$employeeId] ?? collect())
            ->filter(fn (Absence $a) => $a->starts_at->lt($end) && $a->ends_at->gt($start));
        $absence = $overlapping->first(fn (Absence $a) => ! $a->isPending());
        $request = $overlapping->first(fn (Absence $a) => $a->isPending());
        if ($request) {
            $warnings[] = $this->warning('request', self::INFO, sprintf(
                'Verlofverzoek ingediend (%s t/m %s), zoek een vervanger',
                $request->starts_at->format('d-m'), $request->ends_at->format('d-m'),
            ));
        }
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

        [$availability, $availabilityReason] = AvailabilityCheck::evaluate($employee, $start, $end);
        if ($availability === 'blocked') {
            $warnings[] = $this->warning('availability', self::WARNING, 'Kan niet: '.$availabilityReason);
        } elseif ($availability === 'fail') {
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

        array_push($warnings, ...$this->workingTimeWarnings($shift, $mine, $start, $end, $newWeekHours));

        return [
            'warnings' => $warnings,
            'checks' => [
                'absent' => (bool) $absence,
                'request' => (bool) $request,
                'overlap' => (bool) $overlap,
                'availability' => $availability,
                'availability_reason' => $availabilityReason,
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
     * Rusttijden en dienstduur volgens het beleid (issue #42: cao art. 14, Arbeidstijdenwet).
     *
     * @return list<array>
     */
    private function workingTimeWarnings(Shift $shift, Collection $mine, CarbonImmutable $start, CarbonImmutable $end, float $weekHours): array
    {
        $policy = Policy::at($start);
        if (! $policy->get('roster.warn_in_planning')) {
            return [];
        }
        $warnings = [];
        $hours = $start->diffInMinutes($end) / 60;

        $lawMax = $policy->get('roster.max_shift_hours_law');
        if ($hours > $lawMax) {
            $warnings[] = $this->warning('worktime', self::WARNING, sprintf('Dienst langer dan %s uur (Arbeidstijdenwet)', $this->num($lawMax)));
        }
        if ($policy->get('roster.shift_length_applies') === 'all') {
            if ($hours < $policy->get('roster.shift_min_hours')) {
                $warnings[] = $this->warning('worktime', self::INFO, sprintf('Korter dan %s uur (cao)', $this->num($policy->get('roster.shift_min_hours'))));
            } elseif ($hours > $policy->get('roster.shift_max_hours') && $hours <= $lawMax) {
                $warnings[] = $this->warning('worktime', self::WARNING, sprintf('Langer dan %s uur (cao)', $this->num($policy->get('roster.shift_max_hours'))));
            }
        }

        $others = $mine->filter(fn ($a) => $a->shift_id !== $shift->id);
        array_push($warnings, ...$this->restWarnings($shift, $others, $start, $end, $policy));

        $weekMax = $policy->get('roster.max_week_hours_law');
        if ($weekHours > $weekMax) {
            $warnings[] = $this->warning('worktime', self::WARNING, sprintf('Meer dan %s uur deze week (Arbeidstijdenwet)', $this->num($weekMax)));
        }

        return $warnings;
    }

    /**
     * Rusttijd rond een dienst.
     *
     * - Na een nachtdienst die na 02:00 eindigt minimaal rest_after_night_hours rust (ATW art. 5:8).
     * - Verder minimaal rest_daily_hours aaneengesloten rust binnen elke periode van 24 uur die
     *   begint bij de start van een dienst (ATW art. 5:3). Een gebroken dienst (ochtend en
     *   middag op dezelfde dag) mag dus, zolang er binnen die 24 uur genoeg rust aan één stuk zit.
     *
     * @return list<array>
     */
    private function restWarnings(Shift $shift, Collection $others, CarbonImmutable $start, CarbonImmutable $end, Policy $policy): array
    {
        $nightRest = $policy->get('roster.rest_after_night_hours');
        $previous = $others->filter(fn ($a) => $a->ends_at->lte($start))->sortByDesc(fn ($a) => $a->ends_at->getTimestamp())->first();
        $next = $others->filter(fn ($a) => $a->starts_at->gte($end))->sortBy(fn ($a) => $a->starts_at->getTimestamp())->first();

        $warnings = [];
        if ($previous && $this->isNightShift($previous->starts_at, $previous->ends_at)
            && ($gap = $previous->ends_at->diffInMinutes($start) / 60) < $nightRest) {
            $warnings[] = $this->warning('rest', self::WARNING, sprintf(
                'Rusttijd %s u na nachtdienst %s (%s); minimaal %s u', $this->num($gap), $previous->title, $previous->ends_at->format('d-m H:i'), $this->num($nightRest),
            ));
        }
        if ($next && $this->isNightShift($start, $end)
            && ($gap = $end->diffInMinutes($next->starts_at) / 60) < $nightRest) {
            $warnings[] = $this->warning('rest', self::WARNING, sprintf(
                'Rusttijd %s u na deze nachtdienst tot %s (%s); minimaal %s u', $this->num($gap), $next->title, $next->starts_at->format('d-m H:i'), $this->num($nightRest),
            ));
        }
        if ($warnings !== []) {
            return $warnings;
        }

        $dailyRest = $policy->get('roster.rest_daily_hours');
        $periods = $others->map(fn ($a) => ['start' => $a->starts_at, 'end' => $a->ends_at, 'title' => $a->title])
            ->push(['start' => $start, 'end' => $end, 'title' => $shift->title])
            ->sortBy(fn ($p) => $p['start']->getTimestamp())
            ->values();

        // Elke 24-uursperiode waarin deze dienst valt, begint bij de start van deze of een eerdere dienst.
        $worst = null;
        foreach ($periods->filter(fn ($p) => $p['start']->lte($start) && $p['start']->gt($start->subDay())) as $window) {
            $windowEnd = $window['start']->addDay();
            $cursor = $window['start'];
            $longest = 0;
            foreach ($periods as $p) {
                if ($p['start']->gte($windowEnd) || $p['end']->lte($window['start'])) {
                    continue;
                }
                $longest = max($longest, $cursor->diffInMinutes(max($p['start'], $cursor)));
                $cursor = max($cursor, min($p['end'], $windowEnd));
            }
            $longest = max($longest, $cursor->diffInMinutes($windowEnd));
            if ($longest / 60 < $dailyRest && ($worst === null || $longest < $worst['minutes'])) {
                $worst = ['minutes' => $longest, 'window' => $window];
            }
        }

        if ($worst) {
            $warnings[] = $this->warning('rest', self::WARNING, sprintf(
                'Rusttijd %s u binnen 24 u vanaf %s (%s); minimaal %s u aaneengesloten',
                $this->num($worst['minutes'] / 60), $worst['window']['title'], $worst['window']['start']->format('d-m H:i'), $this->num($dailyRest),
            ));
        }

        return $warnings;
    }

    /**
     * Nachtdienst die na 02:00 eindigt (ATW art. 1:7 en 5:8): meer dan een uur werk tussen 00:00
     * en 06:00, en de dienst loopt in die nacht door tot na 02:00.
     */
    private function isNightShift(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        for ($day = $start->startOfDay(); $day->lt($end); $day = $day->addDay()) {
            $from = max($start, $day);
            $to = min($end, $day->setTime(6, 0));
            if ($from->lt($to) && $from->diffInMinutes($to) > 60 && $end->gt($day->setTime(2, 0))) {
                return true;
            }
        }

        return false;
    }

    private function hoursInWeek(int $employeeId, CarbonImmutable $weekStart, ?int $excludeShiftId = null): float
    {
        $weekEnd = $weekStart->addWeek();
        $minutes = ($this->assignments[$employeeId] ?? collect())
            ->filter(fn ($a) => $a->shift_id !== $excludeShiftId && $a->starts_at->gte($weekStart) && $a->starts_at->lt($weekEnd))
            ->sum(fn ($a) => $a->starts_at->diffInMinutes($a->ends_at));

        return round($minutes / 60, 2);
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

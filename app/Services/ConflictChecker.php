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
 * beschikbaarheid, ontbrekende vaardigheid, onbekend pand, contracturen, rusttijden en dienstduur.
 *
 * Alle benodigde gegevens worden per aanroep in één keer geladen, zodat een hele week
 * of maand zonder N+1-queries kan worden gecontroleerd.
 */
class ConflictChecker
{
    public const DANGER = 'danger';

    public const WARNING = 'warning';

    public const INFO = 'info';

    private const DAY = 86400;

    /** @var Collection<int, Employee> */
    private Collection $employees;

    /** @var Collection<int, Collection<int, Absence>> */
    private Collection $absences;

    /** @var Collection<int, Collection<int, object>> toewijzingen per medewerker */
    private Collection $assignments;

    /** @var array<int, string> */
    private array $skillNames = [];

    /** @var array<int, list<array>> rustvensters per medewerker zoals opgeslagen (zie restWindows) */
    private array $restWindowCache = [];

    /** @var array<int, list<array{s: int, e: int, day: string, id: int, row: object}>> diensten per medewerker als timestamps, op begin */
    private array $timelineCache = [];

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
     * Toets meerdere (nog niet opgeslagen) tijdvakken tegelijk voor dezelfde medewerkers; de gegevens
     * worden één keer geladen. Diensten van $ignoreTemplateId tellen niet mee als "dubbel ingepland":
     * dat is de taak zelf, waarvan de diensten opnieuw worden opgebouwd.
     *
     * @param  Collection<int, Shift>  $shifts  met relatie skills gezet; mogen ongesaved zijn
     * @param  Collection<int, int>  $employeeIds
     * @return array<int, array<int, array{warnings: list<array>, checks: array}>> [index van de dienst][employee_id]
     */
    public function forOccurrences(Collection $shifts, Collection $employeeIds, ?int $ignoreTemplateId = null): array
    {
        if ($shifts->isEmpty() || $employeeIds->isEmpty()) {
            return [];
        }
        $this->load($employeeIds, $shifts->min('starts_at'), $shifts->max('ends_at'), $ignoreTemplateId);

        $result = [];
        foreach ($shifts->values() as $i => $shift) {
            foreach ($employeeIds as $id) {
                $result[$i][$id] = $this->evaluate($shift, $id);
            }
        }

        return $result;
    }

    /**
     * Andere diensten van een medewerker op deze dag, uit de laatst geladen gegevens
     * (dus na forShifts(), forCandidates() of forOccurrences()).
     *
     * @return list<array{shift_id: int, start: string, end: string, building: string, title: string}>
     */
    public function dayShifts(int $employeeId, \DateTimeInterface $day, ?int $exceptShiftId = null): array
    {
        $day = CarbonImmutable::instance($day)->startOfDay();
        $next = $day->addDay();

        return ($this->assignments[$employeeId] ?? collect())
            ->filter(fn ($a) => $a->shift_id !== $exceptShiftId && $a->starts_at->lt($next) && $a->ends_at->gt($day))
            ->sortBy(fn ($a) => $a->starts_at->getTimestamp())
            ->map(fn ($a) => [
                'shift_id' => (int) $a->shift_id,
                'start' => $a->starts_at->format('H:i'),
                'end' => $a->ends_at->format('H:i'),
                'building' => $a->building_name,
                'title' => $a->title,
            ])
            ->values()
            ->all();
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

    private function load(Collection $employeeIds, \DateTimeInterface $from, \DateTimeInterface $to, ?int $ignoreTemplateId = null): void
    {
        // 9 dagen marge: genoeg voor volledige ISO-weken (urentelling), nachtdiensten en de rusttijden
        // (ingekorte dagelijkse rust mag één keer per 7 × 24 uur, dus de week ervoor en erna telt mee).
        $windowStart = CarbonImmutable::instance($from)->startOfDay()->subDays(9);
        $windowEnd = CarbonImmutable::instance($to)->endOfDay()->addDays(9);
        $this->restWindowCache = [];
        $this->timelineCache = [];

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
            ->when($ignoreTemplateId, fn ($q) => $q->where(fn ($q) => $q
                ->whereNull('shifts.task_template_id')
                ->orWhere('shifts.task_template_id', '!=', $ignoreTemplateId)))
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
        [$startTs, $endTs] = [$start->getTimestamp(), $end->getTimestamp()];
        $overlap = null;
        foreach ($this->timeline($employeeId, $mine) as $item) {
            if ($item['id'] !== $shift->id && $item['s'] < $endTs && $item['e'] > $startTs) {
                $overlap = $item['row'];
                break;
            }
        }
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

        array_push($warnings, ...$this->workingTimeWarnings($shift, $employeeId, $mine, $start, $end, $newWeekHours));

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
    private function workingTimeWarnings(Shift $shift, int $employeeId, Collection $mine, CarbonImmutable $start, CarbonImmutable $end, float $weekHours): array
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

        array_push($warnings, ...$this->restWarnings($shift, $employeeId, $mine, $start, $end, $policy));

        $weekMax = $policy->get('roster.max_week_hours_law');
        if ($weekHours > $weekMax) {
            $warnings[] = $this->warning('worktime', self::WARNING, sprintf('Meer dan %s uur deze week (Arbeidstijdenwet)', $this->num($weekMax)));
        }

        return $warnings;
    }

    /**
     * Rusttijden (issues #67 en #68).
     *
     * - Na een nachtdienst die na 02:00 eindigt: minstens 14 uur rust tot de volgende dienst (ATW art. 5:8 lid 4).
     * - Dagelijkse rust (ATW art. 5:3 lid 2 en 3): in elke 24 uur, te rekenen vanaf de eerste dienst van de dag,
     *   minstens 11 uur aaneengesloten rust. Het gaat om de langste rust in dat venster, niet om het gat tussen
     *   twee diensten: een gebroken dienst (06:00–08:00 en 17:00–19:00) is in orde als er daarna nog 11 uur rust
     *   volgt. Eén keer per 7 × 24 uur mag die rust worden ingekort tot 8 uur (info); een tweede keer of minder
     *   dan 8 uur is een waarschuwing.
     *
     * @return list<array>
     */
    private function restWarnings(Shift $shift, int $employeeId, Collection $mine, CarbonImmutable $start, CarbonImmutable $end, Policy $policy): array
    {
        $warnings = [];

        $nightRest = $policy->get('roster.rest_after_night_hours');
        // Vorige en volgende dienst, op timestamps (dit loopt voor elke dienst × medewerker; Carbon is hier te traag).
        [$startTs, $endTs] = [$start->getTimestamp(), $end->getTimestamp()];
        $previous = $next = null;
        foreach ($this->timeline($employeeId, $mine) as $item) {
            if ($item['id'] === $shift->id) {
                continue;
            }
            if ($item['e'] <= $startTs && (! $previous || $item['e'] > $previous['e'])) {
                $previous = $item;
            }
            if ($item['s'] >= $endTs && (! $next || $item['s'] < $next['s'])) {
                $next = $item;
            }
        }
        $previous = $previous['row'] ?? null;
        $next = $next['row'] ?? null;
        if ($previous && $this->isNightShift($previous->starts_at, $previous->ends_at)
            && ($gap = $previous->ends_at->diffInMinutes($start) / 60) < $nightRest) {
            $warnings[] = $this->warning('rest', self::WARNING, sprintf(
                'Rusttijd %s u na nachtdienst %s (%s); minimaal %s u', $this->num($gap), $previous->title, $previous->ends_at->format('d-m H:i'), $this->num($nightRest),
            ));
        }
        if ($next && $this->isNightShift($start, $end)
            && ($gap = $end->diffInMinutes($next->starts_at) / 60) < $nightRest) {
            $warnings[] = $this->warning('rest', self::WARNING, sprintf(
                'Rusttijd %s u na deze nachtdienst, tot %s (%s); minimaal %s u', $this->num($gap), $next->title, $next->starts_at->format('d-m H:i'), $this->num($nightRest),
            ));
        }
        $afterNight = $warnings !== [];

        $required = (int) round($policy->get('roster.rest_daily_hours') * 3600);
        $minimum = min($required, (int) round($policy->get('roster.rest_daily_min_shortened') * 3600));
        $windows = $this->restWindowsFor($shift, $employeeId, $mine, $start, $end);
        // Deze dienst valt in het venster van zijn eigen dag, en mogelijk in dat van de dag ervoor.
        $day = $start->toDateString();
        $previousDay = $start->subDay()->toDateString();
        $reported = [];
        foreach ($windows as $window) {
            $contains = $window['day'] === $day
                || ($window['day'] === $previousDay && $start->getTimestamp() < $window['start'] + self::DAY);
            if (! $contains || $window['rest'] >= $required || isset($reported[$window['from']])) {
                continue;
            }
            $reported[$window['from']] = true;

            $from = $this->at($window['start'], $start)->format('d-m H:i');
            $rest = $this->num($window['rest'] / 3600);
            $period = $this->period($this->at($window['from'], $start), $this->at($window['to'], $start));
            if ($window['rest'] < $minimum) {
                $warnings[] = $this->warning('rest', self::WARNING, sprintf(
                    'Minder dan %s u aaneengesloten rust in de 24 u vanaf %s; langste rust %s u (%s)',
                    $this->num($required / 3600), $from, $rest, $period,
                ));

                continue;
            }

            // Ingekorte rust: mag één keer per 7 × 24 uur. Een andere korte rust in die periode telt als eerdere keer.
            $other = collect($windows)
                ->filter(fn ($w) => $w['from'] !== $window['from'] && $w['rest'] < $required && abs($w['start'] - $window['start']) < 7 * self::DAY)
                ->sortBy(fn ($w) => abs($w['start'] - $window['start']))
                ->first();
            if ($other) {
                $warnings[] = $this->warning('rest', self::WARNING, sprintf(
                    'Ingekorte rust %s u in de 24 u vanaf %s (%s); ook op %s, mag één keer per 7 dagen (minimaal %s u)',
                    $rest, $from, $period, $this->at($other['from'], $start)->format('d-m'), $this->num($required / 3600),
                ));
            } elseif (! $afterNight) {
                $warnings[] = $this->warning('rest', self::INFO, sprintf(
                    'Ingekorte rust %s u in de 24 u vanaf %s (%s); mag één keer per 7 dagen',
                    $rest, $from, $period,
                ));
            }
        }

        return $warnings;
    }

    /**
     * Rustvensters van de medewerker met deze dienst op de gegeven tijden. Voor bestaande diensten op hun
     * opgeslagen tijden is dat voor alle diensten van de medewerker hetzelfde en wordt het één keer berekend.
     *
     * @return list<array{day: string, start: int, rest: int, from: int, to: int}>
     */
    private function restWindowsFor(Shift $shift, int $employeeId, Collection $mine, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $timeline = $this->timeline($employeeId, $mine);
        [$startTs, $endTs] = [$start->getTimestamp(), $end->getTimestamp()];
        $stored = $shift->id !== null
            && collect($timeline)->contains(fn ($i) => $i['id'] === $shift->id && $i['s'] === $startTs && $i['e'] === $endTs);
        if ($stored) {
            return $this->restWindowCache[$employeeId] ??= $this->restWindows($timeline);
        }

        // Alleen de dagen rond deze dienst doen ertoe: zijn eigen venster, dat van de dag ervoor, en 7 dagen eromheen.
        $from = $startTs - 9 * self::DAY;
        $to = $endTs + 9 * self::DAY;
        $items = array_filter($timeline, fn ($i) => $i['id'] !== $shift->id && $i['e'] > $from && $i['s'] < $to);
        $items[] = ['s' => $startTs, 'e' => $endTs, 'day' => $start->toDateString()];

        return $this->restWindows($items);
    }

    /**
     * Diensten van de medewerker als timestamps, één keer per load() berekend.
     *
     * @return list<array{s: int, e: int, day: string, id: int, row: object}>
     */
    private function timeline(int $employeeId, Collection $mine): array
    {
        return $this->timelineCache[$employeeId] ??= $mine
            ->map(fn ($a) => ['s' => $a->starts_at->getTimestamp(), 'e' => $a->ends_at->getTimestamp(), 'day' => $a->starts_at->toDateString(), 'id' => $a->shift_id, 'row' => $a])
            ->sortBy('s')->values()->all();
    }

    /**
     * Per werkdag het venster van 24 uur vanaf de eerste dienst die op die dag begint (ATW art. 5:3 lid 3), met de
     * langste aaneengesloten rust erin. Tijden zijn timestamps, de rust is in seconden. "from" is het einde van de
     * dienst waarna die rust begint en wijst de rust dus eenduidig aan (twee vensters kunnen dezelfde rust delen).
     *
     * @param  iterable<array{s: int, e: int, day: string}>  $shifts  begin en eind (timestamps) en de dag van het begin
     * @return list<array{day: string, start: int, rest: int, from: int, to: int}>
     */
    private function restWindows(iterable $shifts): array
    {
        $firstStart = [];
        $intervals = [];
        foreach ($shifts as $shift) {
            $firstStart[$shift['day']] = min($firstStart[$shift['day']] ?? PHP_INT_MAX, $shift['s']);
            $intervals[] = [$shift['s'], $shift['e']];
        }
        ksort($firstStart);
        sort($intervals);

        // Aansluitende en overlappende diensten samenvoegen tot werkblokken.
        $blocks = [];
        foreach ($intervals as [$s, $e]) {
            $last = array_key_last($blocks);
            if ($last !== null && $s <= $blocks[$last][1]) {
                $blocks[$last][1] = max($blocks[$last][1], $e);
            } else {
                $blocks[] = [$s, $e];
            }
        }

        $windows = [];
        foreach ($firstStart as $day => $windowStart) {
            $windowEnd = $windowStart + self::DAY;
            $cursor = $windowStart;
            $best = ['rest' => 0, 'from' => $windowStart, 'to' => $windowStart];
            foreach ($blocks as [$s, $e]) {
                if ($e <= $windowStart) {
                    continue;
                }
                if ($s >= $windowEnd) {
                    break;
                }
                if ($s - $cursor > $best['rest']) {
                    $best = ['rest' => $s - $cursor, 'from' => $cursor, 'to' => $s];
                }
                $cursor = max($cursor, $e);
            }
            if ($windowEnd - $cursor > $best['rest']) {
                $best = ['rest' => $windowEnd - $cursor, 'from' => $cursor, 'to' => $windowEnd];
            }
            $windows[] = ['day' => (string) $day, 'start' => $windowStart] + $best;
        }

        return $windows;
    }

    /**
     * Nachtdienst die na 02:00 eindigt (ATW art. 1:7 en 5:8 lid 4): meer dan een uur arbeid tussen 00:00 en 06:00,
     * en de dienst eindigt na 02:00. Een vroege dienst als 05:30–08:00 of 07:30–08:00 is dus geen nachtdienst.
     */
    private function isNightShift(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        $nightMinutes = 0;
        $lastMidnight = $start->startOfDay();
        for ($midnight = $start->startOfDay(); $midnight->lt($end); $midnight = $midnight->addDay()) {
            $from = $start->max($midnight);
            $to = $end->min($midnight->setTime(6, 0));
            if ($to->gt($from)) {
                $nightMinutes += $from->diffInMinutes($to);
            }
            $lastMidnight = $midnight;
        }

        return $nightMinutes > 60 && $end->gt($lastMidnight->setTime(2, 0));
    }

    private function at(int $timestamp, CarbonImmutable $reference): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($timestamp, $reference->getTimezone());
    }

    /** "17-11 23:00–18-11 06:00", of "18-11 08:00–17:00" binnen één dag. */
    private function period(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->format('d-m H:i').'–'.($from->isSameDay($to) ? $to->format('H:i') : $to->format('d-m H:i'));
    }

    private function hoursInWeek(int $employeeId, CarbonImmutable $weekStart, ?int $excludeShiftId = null): float
    {
        [$from, $to] = [$weekStart->getTimestamp(), $weekStart->addWeek()->getTimestamp()];
        $seconds = 0;
        foreach ($this->timeline($employeeId, $this->assignments[$employeeId] ?? collect()) as $item) {
            if ($item['id'] !== $excludeShiftId && $item['s'] >= $from && $item['s'] < $to) {
                $seconds += $item['e'] - $item['s'];
            }
        }

        return round($seconds / 3600, 2);
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

<?php

namespace App\Services;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Models\TaskTemplate;
use App\Models\TaskTemplatePattern;
use App\Support\Planning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kan een medewerker de diensten van een vaste taak ook echt draaien?
 *
 * Bij het aanwijzen van een vaste kracht rekenen we de diensten van de komende weken uit en toetsen
 * we elk tijdvak op beschikbaarheid, afwezigheid en dubbel ingepland, plus contracturen en
 * vaardigheden. De taak mag nog niet opgeslagen zijn (formulier), zodat de planner het vooraf ziet.
 */
class FixedStaffCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const BLOCKED = 'blocked';

    public function __construct(private ConflictChecker $checker, private DutchHolidays $holidays) {}

    /**
     * De tijdvakken van de taak vanaf vandaag, met het patroon (index in $template->patterns) waaruit ze komen.
     * Vallen twee patronen op dezelfde dag en begintijd, dan telt het eerste (zoals in de ShiftGenerator).
     *
     * @return Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}>
     */
    public function occurrences(TaskTemplate $template, ?int $weeks = null): Collection
    {
        $weeks ??= (int) config('poetsploeg.generate_weeks_ahead');
        $from = CarbonImmutable::today();
        $to = $from->addWeeks(max(1, $weeks));
        $patterns = $template->patterns->values();

        $slots = collect();
        $seen = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            if ($template->skip_holidays && $this->holidays->isSkippable($day)) {
                continue;
            }
            foreach ($template->patternsOn($day) as $pattern) {
                [$startsAt, $endsAt] = ShiftGenerator::timesFor($day, $pattern->startTime($template), $pattern->endTime($template));
                $key = $startsAt->format('Y-m-d H:i');
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $slots->push([$startsAt, $endsAt, (int) $patterns->search($pattern, true)]);
                }
            }
        }

        return $slots;
    }

    /**
     * Per patroon: kan elke medewerker de diensten van dat patroon draaien?
     *
     * @param  TaskTemplate  $template  met de relaties patterns en skills gezet; mag ongesaved zijn
     * @param  Collection<int, int>|array<int, int>  $employeeIds
     * @param  int|null  $ignoreTemplateId  bestaande taak waarvan de eigen diensten niet als overlap tellen
     * @return array<int, array<int, array{employee_id: int, name: string, status: string, total: int, possible: int, summary: string, issues: list<array>, notes: list<string>}>>
     *                                                                                                                                                                             [index van het patroon][employee_id]
     */
    public function forTemplate(TaskTemplate $template, Collection|array $employeeIds, ?int $ignoreTemplateId = null, ?int $weeks = null): array
    {
        $patternCount = $template->patterns->count();
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty() || $patternCount === 0) {
            return array_fill(0, $patternCount, []);
        }
        $employees = Employee::whereIn('id', $ids)->get(['id', 'name', 'contract_hours_max'])->keyBy('id');
        $slots = $this->occurrences($template, $weeks);

        $shifts = $slots->map(function (array $slot) use ($template) {
            $shift = new Shift([
                'building_id' => $template->building_id,
                'starts_at' => $slot[0],
                'ends_at' => $slot[1],
                'title' => (string) $template->name,
                'required_staff' => (int) ($template->required_staff ?: 1),
            ]);
            $shift->setRelation('skills', $template->skills);

            return $shift;
        });
        $checks = $this->checker->forOccurrences($shifts, $ids, $ignoreTemplateId);

        $result = [];
        for ($p = 0; $p < $patternCount; $p++) {
            $indexes = $slots->keys()->filter(fn ($i) => $slots[$i][2] === $p)->values();
            $patternSlots = $indexes->map(fn ($i) => $slots[$i]);
            $result[$p] = [];
            foreach ($ids as $id) {
                if ($employee = $employees->get($id)) {
                    $result[$p][$id] = $this->summarise($employee, $patternSlots, $indexes->map(fn ($i) => $checks[$i][$id])->all());
                }
            }
        }

        return $result;
    }

    /**
     * Uitkomsten van forTemplate() voor alleen de gekozen vaste krachten per patroon.
     *
     * @param  array<int, array<int, array>>  $results  van forTemplate()
     * @param  array<int, list<int>>  $selected  [index van het patroon] => employee_ids
     * @return array<int, array<int, array>>
     */
    public static function selected(array $results, array $selected): array
    {
        return collect($results)
            ->map(fn (array $perEmployee, int $p) => array_intersect_key($perEmployee, array_flip($selected[$p] ?? [])))
            ->all();
    }

    /**
     * Een taak uit (gevalideerde) formuliervelden, zonder hem op te slaan.
     *
     * @param  array<string, mixed>  $data  patterns (met tijden), valid_from, valid_until, skip_holidays, skills
     */
    public static function draft(Building $building, array $data): TaskTemplate
    {
        $patterns = array_values($data['patterns']);
        $template = new TaskTemplate([
            'name' => $data['name'] ?? 'Taak',
            'start_time' => $patterns[0]['start_time'] ?? '00:00',
            'end_time' => $patterns[0]['end_time'] ?? '00:00',
            'required_staff' => $data['required_staff'] ?? 1,
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'] ?? null,
            'skip_holidays' => (bool) ($data['skip_holidays'] ?? false),
            'active' => true,
        ]);
        $template->building_id = $building->id;
        $template->setRelation('patterns', collect($patterns)
            ->map(fn (array $p, int $i) => new TaskTemplatePattern(TaskTemplatePattern::attributesFromInput($p, $i))));
        $template->setRelation('skills', Skill::whereIn('id', $data['skills'] ?? [])->get(['id', 'name']));

        return $template;
    }

    /**
     * Vaste krachten van de taak die niet op deze dienst staan, met de reden: zo is te zien waarom
     * een dienst open staat (afwezig, kan niet op dit tijdvak, al ergens anders ingepland).
     *
     * @return list<array{employee_id: int, name: string, reason: string}>
     */
    public function missingOn(Shift $shift): array
    {
        $shift->loadMissing(['template.patterns.employees:id,name,color,active', 'assignments']);
        if (! $shift->template || $shift->openSlots() === 0) {
            return [];
        }
        $missing = $shift->template->staffFor($shift)
            ->where('active', true)
            ->whereNotIn('id', $shift->assignments->pluck('employee_id'))
            ->take(max(1, $shift->required_staff));
        if ($missing->isEmpty()) {
            return [];
        }

        $checks = $this->checker->forCandidates($shift, $missing->pluck('id')->values());

        return $missing->map(function (Employee $employee) use ($checks) {
            $blocking = collect($checks[$employee->id]['warnings'] ?? [])
                ->first(fn ($w) => in_array($w['type'], ['absent', 'overlap', 'availability'], true));

            return [
                'employee_id' => $employee->id,
                'name' => $employee->name,
                'reason' => $blocking['message'] ?? 'niet ingepland op deze dienst',
            ];
        })->values()->all();
    }

    /** Moet de planner dit bevestigen voor het opslaan? */
    public static function needsConfirmation(array $results): bool
    {
        return collect($results)->contains(fn ($r) => $r['status'] !== self::OK);
    }

    /**
     * Eén regel per medewerker met een probleem, voor de waarschuwing bij opslaan.
     *
     * @param  array<int, array>  $results  per medewerker (één patroon)
     * @return list<string>
     */
    public static function messages(array $results): array
    {
        return collect($results)
            ->filter(fn ($r) => $r['status'] !== self::OK)
            ->map(fn ($r) => $r['name'].': '.collect($r['issues'])->pluck('text')->implode('; '))
            ->values()
            ->all();
    }

    /**
     * Zoals messages(), voor alle patronen; bij meer patronen met het patroon ervoor ("Elke week op di 12:00–13:00 – Jan: …").
     *
     * @param  array<int, array<int, array>>  $results  [index van het patroon][employee_id]
     * @return list<string>
     */
    public static function patternMessages(TaskTemplate $template, array $results): array
    {
        $patterns = $template->patterns->values();

        return collect($results)->flatMap(fn (array $perEmployee, int $p) => collect(self::messages($perEmployee))
            ->map(fn ($m) => $patterns->count() > 1 && $patterns->has($p) ? $patterns[$p]->label().' '.$patterns[$p]->timesLabel($template).' – '.$m : $m))
            ->values()->all();
    }

    /** Eén uitkomst per patroon en medewerker, voor needsConfirmation() en de melding na opslaan. */
    public static function flatten(array $results): array
    {
        return collect($results)->flatMap(fn (array $perEmployee) => array_values($perEmployee))->all();
    }

    /**
     * @param  Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable, 2: int}>  $slots
     * @param  list<array{warnings: list<array>, checks: array}>  $checks  per tijdvak
     */
    private function summarise(Employee $employee, Collection $slots, array $checks): array
    {
        $total = $slots->count();
        $possible = 0;
        $unavailable = ['days' => [], 'count' => 0, 'reason' => null];
        $absent = ['count' => 0, 'detail' => null];
        $overlap = ['days' => [], 'count' => 0, 'detail' => null];
        $weekHours = [];
        $missingSkills = [];
        $knowsBuilding = true;

        foreach ($slots->values() as $i => [$startsAt, $endsAt]) {
            $c = $checks[$i]['checks'] ?? [];
            if ($c === []) {
                continue;
            }
            $blocked = false;
            if (in_array($c['availability'], ['blocked', 'fail'], true)) {
                $blocked = true;
                $unavailable['count']++;
                $unavailable['days'][$startsAt->dayOfWeekIso] = true;
                $unavailable['reason'] ??= $c['availability_reason'];
            }
            if ($c['absent']) {
                $blocked = true;
                $absent['count']++;
                $absent['detail'] ??= $this->message($checks[$i]['warnings'], 'absent');
            }
            if ($c['overlap']) {
                $blocked = true;
                $overlap['count']++;
                $overlap['days'][$startsAt->dayOfWeekIso] = true;
                $overlap['detail'] ??= $this->message($checks[$i]['warnings'], 'overlap');
            }
            if (! $blocked) {
                $possible++;
                // Uren per week: wat er al staat (zonder deze taak) plus de diensten van deze taak.
                $week = $startsAt->startOfWeek()->toDateString();
                $weekHours[$week] ??= (float) $c['week_hours'];
                $weekHours[$week] += $startsAt->diffInMinutes($endsAt) / 60;
            }
            $missingSkills = $c['missing_skills'];
            $knowsBuilding = $c['knows_building'];
        }

        $issues = [];
        if ($unavailable['count'] > 0) {
            $issues[] = [
                'type' => 'availability',
                'level' => self::WARNING,
                'count' => $unavailable['count'],
                'text' => 'Kan niet op '.$this->weekdays($unavailable['days'])
                    .($unavailable['reason'] ? ': '.$unavailable['reason'] : ' (buiten opgegeven beschikbaarheid)'),
            ];
        }
        if ($overlap['count'] > 0) {
            $issues[] = [
                'type' => 'overlap',
                'level' => self::WARNING,
                'count' => $overlap['count'],
                'text' => 'Staat op '.$this->weekdays($overlap['days']).' al ergens anders'
                    .($overlap['detail'] ? ' ('.$overlap['detail'].')' : ''),
            ];
        }
        if ($absent['count'] > 0) {
            $issues[] = [
                'type' => 'absent',
                'level' => self::WARNING,
                'count' => $absent['count'],
                'text' => sprintf('Afwezig tijdens %d %s', $absent['count'], $absent['count'] === 1 ? 'dienst' : 'diensten')
                    .($absent['detail'] ? ' ('.$absent['detail'].')' : ''),
            ];
        }
        $max = $employee->contract_hours_max;
        $peak = $weekHours === [] ? 0.0 : max($weekHours);
        if ($max !== null && $peak > $max) {
            $issues[] = [
                'type' => 'hours',
                'level' => self::WARNING,
                'count' => 0,
                'text' => sprintf('Komt op %s u per week (contract max %s)', Planning::hours(round($peak, 2)), Planning::hours($max)),
            ];
        }
        if ($missingSkills !== []) {
            $issues[] = [
                'type' => 'skill',
                'level' => self::WARNING,
                'count' => 0,
                'text' => 'Mist vaardigheid: '.implode(', ', $missingSkills),
            ];
        }

        $status = match (true) {
            $total > 0 && $possible === 0 => self::BLOCKED,
            $issues !== [] => self::WARNING,
            default => self::OK,
        };
        $summary = match (true) {
            $total === 0 => 'Geen diensten in de komende weken',
            $possible === 0 => sprintf('Kan geen van de %d diensten', $total),
            $possible < $total => sprintf('Kan %d van de %d diensten', $possible, $total),
            $issues !== [] => sprintf('Kan alle %d diensten, let op', $total),
            default => sprintf('Kan alle %d diensten', $total),
        };

        return [
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'status' => $status,
            'total' => $total,
            'possible' => $possible,
            'summary' => $summary,
            'issues' => $issues,
            'notes' => $knowsBuilding || $total === 0 ? [] : ['Nog niet ingewerkt op dit pand'],
        ];
    }

    /** Tekst van de eerste waarschuwing van dit type, zonder het voorvoegsel ("Dubbel ingepland: "). */
    private function message(array $warnings, string $type): ?string
    {
        $message = collect($warnings)->firstWhere('type', $type)['message'] ?? null;
        if ($message === null) {
            return null;
        }
        $message = preg_replace('/^(Dubbel ingepland: |Afwezig \()/u', '', $message);

        return $type === 'absent' ? rtrim($message, ')') : $message;
    }

    /** "di en do", "ma, wo en vr". */
    private function weekdays(array $days): string
    {
        ksort($days);
        $names = array_map(fn ($d) => config('poetsploeg.weekdays_short')[$d] ?? (string) $d, array_keys($days));

        return collect($names)->join(', ', ' en ');
    }
}

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
     * De tijdvakken van de taak vanaf vandaag.
     *
     * @return Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function occurrences(TaskTemplate $template, ?int $weeks = null): Collection
    {
        $weeks ??= (int) config('poetsploeg.generate_weeks_ahead');
        $from = CarbonImmutable::today();
        $to = $from->addWeeks(max(1, $weeks));

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
                    $slots->push([$startsAt, $endsAt]);
                }
            }
        }

        return $slots;
    }

    /**
     * @param  TaskTemplate  $template  met de relaties patterns en skills gezet; mag ongesaved zijn
     * @param  Collection<int, int>|array<int, int>  $employeeIds
     * @param  int|null  $ignoreTemplateId  bestaande taak waarvan de eigen diensten niet als overlap tellen
     * @return array<int, array{employee_id: int, name: string, status: string, total: int, possible: int, summary: string, issues: list<array>, notes: list<string>}>
     */
    public function forTemplate(TaskTemplate $template, Collection|array $employeeIds, ?int $ignoreTemplateId = null, ?int $weeks = null): array
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
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
        foreach ($ids as $id) {
            if ($employee = $employees->get($id)) {
                $result[$id] = $this->summarise($employee, $slots, array_map(fn ($row) => $row[$id], $checks));
            }
        }

        return $result;
    }

    /**
     * Een taak uit (gevalideerde) formuliervelden, zonder hem op te slaan.
     *
     * @param  array<string, mixed>  $data  start_time, end_time, patterns, valid_from, valid_until, skip_holidays, skills
     */
    public static function draft(Building $building, array $data): TaskTemplate
    {
        $template = new TaskTemplate([
            'name' => $data['name'] ?? 'Taak',
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'required_staff' => $data['required_staff'] ?? 1,
            'valid_from' => $data['valid_from'],
            'valid_until' => $data['valid_until'] ?? null,
            'skip_holidays' => (bool) ($data['skip_holidays'] ?? false),
            'active' => true,
        ]);
        $template->building_id = $building->id;
        $template->setRelation('patterns', collect(array_values($data['patterns']))
            ->map(fn (array $p, int $i) => new TaskTemplatePattern(TaskTemplatePattern::attributesFromInput($p, $i))));
        $template->setRelation('skills', Skill::whereIn('id', $data['skills'] ?? [])->get(['id', 'name']));

        return $template;
    }

    /** Moet de planner dit bevestigen voor het opslaan? */
    public static function needsConfirmation(array $results): bool
    {
        return collect($results)->contains(fn ($r) => $r['status'] !== self::OK);
    }

    /**
     * Eén regel per medewerker met een probleem, voor de waarschuwing bij opslaan.
     *
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
     * @param  Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $slots
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

<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Shift;
use App\Models\TaskTemplate;
use App\Models\TaskTemplatePattern;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Zet taaksjablonen om in concrete diensten. Idempotent: een dienst per sjabloon per dag
 * wordt maar één keer aangemaakt, en handmatig aangepaste of geannuleerde diensten blijven staan.
 */
class ShiftGenerator
{
    /** @var array<int, list<array{0: CarbonImmutable, 1: CarbonImmutable}>> bezette tijdvakken per medewerker */
    private array $busy = [];

    public function __construct(private DutchHolidays $holidays) {}

    /**
     * Maak ontbrekende diensten aan voor alle (of de opgegeven) sjablonen tussen from en to (inclusief).
     *
     * @param  Collection<int, TaskTemplate>|null  $templates
     * @return int aantal aangemaakte diensten
     */
    public function generate(\DateTimeInterface $from, \DateTimeInterface $to, ?Collection $templates = null): int
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->startOfDay();
        if ($to->lt($from)) {
            return 0;
        }

        $templates ??= TaskTemplate::query()
            ->where('active', true)
            ->whereDate('valid_from', '<=', $to)
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from))
            ->whereHas('building', fn ($q) => $q->where('active', true))
            ->get();
        $templates = new EloquentCollection($templates->filter(fn (TaskTemplate $t) => $t->active)->values()->all());
        if ($templates->isEmpty()) {
            return 0;
        }
        $templates->load(['patterns.employees' => fn ($q) => $q->where('active', true)->with(['availabilityPatterns', 'availabilityDays']), 'skills:id']);
        $staffIds = $templates->flatMap->patterns->flatMap->employees->pluck('id')->unique()->values();

        // Wat er al staat: per patroon per dag, en per taak per dag en begintijd (twee patronen op
        // dezelfde dag en tijd geven één dienst). Geannuleerde diensten tellen mee: die blijven weg.
        $existing = [];
        Shift::query()
            ->whereIn('task_template_id', $templates->pluck('id'))
            // whereDate: SQLite slaat de datum op als "Y-m-d H:i:s"; zo werkt de vergelijking overal.
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['task_template_id', 'task_template_pattern_id', 'date', 'starts_at'])
            ->each(function (Shift $s) use (&$existing) {
                $date = $s->date->toDateString();
                $existing[$s->task_template_id.'|'.$date.'|'.$s->starts_at->format('H:i')] = true;
                if ($s->task_template_pattern_id) {
                    $existing[$s->task_template_id.'|p'.$s->task_template_pattern_id.'|'.$date] = true;
                } else {
                    // Dienst van vóór de patronen: geldt voor de hele taak op die dag.
                    $existing[$s->task_template_id.'|'.$date] = true;
                }
            });

        $absences = Absence::query()->effective()
            ->whereIn('employee_id', $staffIds)
            ->where('starts_at', '<', $to->addDays(2))
            ->where('ends_at', '>', $from)
            ->get()
            ->groupBy('employee_id');

        // Wat de vaste krachten in deze periode al doen: een vaste kracht wordt niet dubbel ingepland.
        // Nieuwe diensten uit deze ronde tellen meteen mee (zie createShift).
        $this->busy = [];
        DB::table('shift_assignments')
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->whereIn('shift_assignments.employee_id', $staffIds)
            ->whereNull('shifts.cancelled_at')
            ->where('shifts.starts_at', '<', $to->addDays(2))
            ->where('shifts.ends_at', '>', $from)
            ->get(['shift_assignments.employee_id', 'shifts.starts_at', 'shifts.ends_at'])
            ->each(function ($row) {
                $this->busy[$row->employee_id][] = [CarbonImmutable::parse($row->starts_at), CarbonImmutable::parse($row->ends_at)];
            });

        $created = 0;
        DB::transaction(function () use ($templates, $from, $to, $existing, $absences, &$created) {
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $day = CarbonImmutable::instance($day);
                foreach ($templates as $template) {
                    if (isset($existing[$template->id.'|'.$day->toDateString()])) {
                        continue;
                    }
                    if ($template->skip_holidays && $this->holidays->isSkippable($day)) {
                        continue;
                    }
                    foreach ($template->patternsOn($day) as $pattern) {
                        $date = $day->toDateString();
                        $startKey = $template->id.'|'.$date.'|'.substr($pattern->startTime($template), 0, 5);
                        $patternKey = $template->id.'|p'.$pattern->id.'|'.$date;
                        if (isset($existing[$patternKey]) || isset($existing[$startKey])) {
                            continue;
                        }
                        $this->createShift($template, $pattern, $day, $absences);
                        $existing[$patternKey] = $existing[$startKey] = true;
                        $created++;
                    }
                }
            }
        });

        return $created;
    }

    /** Diensten aanmaken voor de standaardperiode (vandaag t/m N weken vooruit). */
    public function generateAhead(?Collection $templates = null): int
    {
        $today = CarbonImmutable::today();

        return $this->generate($today, $today->addWeeks(config('poetsploeg.generate_weeks_ahead')), $templates);
    }

    /**
     * Na een wijziging van een sjabloon: toekomstige, niet handmatig aangepaste diensten opnieuw opbouwen.
     */
    public function regenerateTemplate(TaskTemplate $template): void
    {
        DB::transaction(function () use ($template) {
            $template->shifts()
                ->whereDate('date', '>=', CarbonImmutable::today())
                ->where('is_modified', false)
                ->whereNull('cancelled_at')
                ->delete();
        });

        $template->refresh();
        if ($template->active) {
            $this->generateAhead(collect([$template]));
        }
    }

    /** Begin- en eindtijd van een dienst; een eindtijd vóór de begintijd valt op de volgende dag. */
    public static function timesFor(CarbonImmutable $day, string $start, string $end): array
    {
        $startsAt = $day->setTimeFromTimeString($start);
        $endsAt = $day->setTimeFromTimeString($end);
        if ($endsAt->lte($startsAt)) {
            $endsAt = $endsAt->addDay();
        }

        return [$startsAt, $endsAt];
    }

    private function createShift(TaskTemplate $template, TaskTemplatePattern $pattern, CarbonImmutable $day, Collection $absences): Shift
    {
        [$startsAt, $endsAt] = self::timesFor($day, $pattern->startTime($template), $pattern->endTime($template));

        $shift = Shift::create([
            'building_id' => $template->building_id,
            'task_template_id' => $template->id,
            'task_template_pattern_id' => $pattern->id,
            'date' => $day->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'required_staff' => $template->required_staff,
            'title' => $template->name,
        ]);

        if ($template->skills->isNotEmpty()) {
            $shift->skills()->attach($template->skills->pluck('id'));
        }

        // Vaste bezetting van dit patroon invullen, behalve wie op dat moment afwezig is, volgens het weekpatroon
        // niet kan of al op een andere dienst staat. De plek blijft dan open (zie FixedStaffCheck voor de waarschuwing vooraf).
        $assigned = 0;
        foreach ($pattern->employees as $employee) {
            if ($assigned >= $template->required_staff) {
                break;
            }
            $isAbsent = ($absences[$employee->id] ?? collect())->contains(
                fn (Absence $a) => $a->starts_at->lt($endsAt) && $a->ends_at->gt($startsAt)
            );
            $isBusy = collect($this->busy[$employee->id] ?? [])->contains(
                fn (array $slot) => $slot[0]->lt($endsAt) && $slot[1]->gt($startsAt)
            );
            if ($isAbsent || $isBusy || AvailabilityCheck::isOutside($employee, $startsAt, $endsAt)) {
                continue;
            }
            $shift->assignments()->create(['employee_id' => $employee->id, 'source' => 'fixed']);
            $this->busy[$employee->id][] = [$startsAt, $endsAt];
            $assigned++;
        }

        return $shift;
    }
}

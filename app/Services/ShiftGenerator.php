<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Shift;
use App\Models\TaskTemplate;
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
    public function __construct(private DutchHolidays $holidays)
    {
    }

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
        $templates->load(['skills:id', 'employees' => fn ($q) => $q->where('active', true)]);

        $existing = Shift::query()
            ->whereIn('task_template_id', $templates->pluck('id'))
            // whereDate: SQLite slaat de datum op als "Y-m-d H:i:s"; zo werkt de vergelijking overal.
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get(['task_template_id', 'date'])
            ->mapWithKeys(fn (Shift $s) => [$s->task_template_id.'|'.$s->date->toDateString() => true]);

        $absences = Absence::query()
            ->whereIn('employee_id', $templates->flatMap->employees->pluck('id')->unique())
            ->where('starts_at', '<', $to->addDays(2))
            ->where('ends_at', '>', $from)
            ->get()
            ->groupBy('employee_id');

        $created = 0;
        DB::transaction(function () use ($templates, $from, $to, $existing, $absences, &$created) {
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $day = CarbonImmutable::instance($day);
                foreach ($templates as $template) {
                    if (isset($existing[$template->id.'|'.$day->toDateString()])) {
                        continue;
                    }
                    if (! $this->occursOn($template, $day)) {
                        continue;
                    }
                    if ($template->skip_holidays && $this->holidays->isSkippable($day)) {
                        continue;
                    }
                    $this->createShift($template, $day, $absences);
                    $created++;
                }
            }
        });

        return $created;
    }

    /** Diensten aanmaken voor de standaardperiode (vandaag t/m N weken vooruit). */
    public function generateAhead(?Collection $templates = null): int
    {
        $today = CarbonImmutable::today();

        return $this->generate($today, $today->addWeeks(config('novon.generate_weeks_ahead')), $templates);
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

    public function occursOn(TaskTemplate $template, CarbonImmutable $day): bool
    {
        $validFrom = CarbonImmutable::instance($template->valid_from)->startOfDay();
        if ($day->lt($validFrom)) {
            return false;
        }
        if ($template->valid_until && $day->gt(CarbonImmutable::instance($template->valid_until)->startOfDay())) {
            return false;
        }

        $every = max(1, (int) $template->repeat_every);

        switch ($template->recurrence) {
            case 'weekly':
                if (! in_array($day->dayOfWeekIso, array_map('intval', $template->weekdays ?? []), true)) {
                    return false;
                }
                $days = $this->daysBetween($validFrom->startOfWeek(), $day->startOfWeek());

                return intdiv($days, 7) % $every === 0;

            case 'monthly_nth_weekday':
                if ($day->dayOfWeekIso !== (int) $template->weekday || ! $this->monthMatches($validFrom, $day, $every)) {
                    return false;
                }
                if ((int) $template->nth === -1) {
                    return $day->addWeek()->month !== $day->month;
                }

                return (int) ceil($day->day / 7) === (int) $template->nth;

            case 'monthly_day':
                if (! $this->monthMatches($validFrom, $day, $every)) {
                    return false;
                }

                return $day->day === min((int) $template->day_of_month, $day->daysInMonth);
        }

        return false;
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

    /** Aantal kalenderdagen, ongevoelig voor zomer-/wintertijd. */
    private function daysBetween(CarbonImmutable $a, CarbonImmutable $b): int
    {
        $utc = fn (CarbonImmutable $d) => CarbonImmutable::parse($d->toDateString(), 'UTC');

        return (int) round($utc($a)->diffInDays($utc($b)));
    }

    private function monthMatches(CarbonImmutable $anchor, CarbonImmutable $day, int $every): bool
    {
        $months = ($day->year * 12 + $day->month) - ($anchor->year * 12 + $anchor->month);

        return $months % $every === 0;
    }

    private function createShift(TaskTemplate $template, CarbonImmutable $day, Collection $absences): Shift
    {
        [$startsAt, $endsAt] = self::timesFor($day, $template->start_time, $template->end_time);

        $shift = Shift::create([
            'building_id' => $template->building_id,
            'task_template_id' => $template->id,
            'date' => $day->toDateString(),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'required_staff' => $template->required_staff,
            'title' => $template->name,
        ]);

        if ($template->skills->isNotEmpty()) {
            $shift->skills()->attach($template->skills->pluck('id'));
        }

        // Vaste bezetting invullen, behalve wie op dat moment afwezig is.
        $assigned = 0;
        foreach ($template->employees as $employee) {
            if ($assigned >= $template->required_staff) {
                break;
            }
            $isAbsent = ($absences[$employee->id] ?? collect())->contains(
                fn (Absence $a) => $a->starts_at->lt($endsAt) && $a->ends_at->gt($startsAt)
            );
            if ($isAbsent) {
                continue;
            }
            $shift->assignments()->create(['employee_id' => $employee->id, 'source' => 'fixed']);
            $assigned++;
        }

        return $shift;
    }
}

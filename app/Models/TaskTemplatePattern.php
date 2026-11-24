<?php

namespace App\Models;

use App\Rules\ValidRecurrenceRule;
use App\Services\RecurrenceRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\Rule;

/**
 * Eén herhalingspatroon van een vaste taak, met eigen tijden en eigen vaste bezetting. Een taak kan er
 * meerdere hebben, bijv. "ma, wo, vr 10:00–12:00 (Henk)" en "di, do 12:00–13:00 (Jan)", of "om de week op
 * donderdag" en "om de week op maandag" die in verschillende weken beginnen (starts_on).
 */
class TaskTemplatePattern extends Model
{
    use HasFactory;

    public const MAX_PER_TEMPLATE = 10;

    public const RECURRENCES = [
        'weekly' => 'Wekelijks',
        'monthly_nth_weekday' => 'Maandelijks op een weekdag',
        'monthly_day' => 'Maandelijks op een vaste datum',
        'rrule' => 'Aangepast (code)',
    ];

    protected $fillable = [
        'position', 'recurrence', 'repeat_every', 'weekdays', 'nth', 'weekday', 'day_of_month', 'rrule',
        'starts_on', 'start_time', 'end_time',
    ];

    protected $attributes = ['recurrence' => 'weekly', 'repeat_every' => 1];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'starts_on' => 'date',
            'position' => 'integer',
            'repeat_every' => 'integer',
            'nth' => 'integer',
            'weekday' => 'integer',
            'day_of_month' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    /** Vaste bezetting van dit patroon, in volgorde (bij te weinig plekken gaan de eersten voor). */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_task_template_pattern')->withPivot('position')->orderByPivot('position');
    }

    /** Validatie van de patronen uit het formulier (veld "patterns"). */
    public static function rules(): array
    {
        return [
            'patterns' => ['required', 'array', 'min:1', 'max:'.self::MAX_PER_TEMPLATE],
            'patterns.*.recurrence' => ['required', Rule::in(array_keys(self::RECURRENCES))],
            'patterns.*.repeat_every' => ['required_unless:patterns.*.recurrence,rrule', 'nullable', 'integer', 'min:1', 'max:52'],
            'patterns.*.weekdays' => ['required_if:patterns.*.recurrence,weekly', 'array'],
            'patterns.*.weekdays.*' => ['integer', 'between:1,7'],
            'patterns.*.nth' => ['required_if:patterns.*.recurrence,monthly_nth_weekday', 'nullable', Rule::in([1, 2, 3, 4, -1])],
            'patterns.*.weekday' => ['required_if:patterns.*.recurrence,monthly_nth_weekday', 'nullable', 'integer', 'between:1,7'],
            'patterns.*.day_of_month' => ['required_if:patterns.*.recurrence,monthly_day', 'nullable', 'integer', 'between:1,31'],
            'patterns.*.rrule' => ['required_if:patterns.*.recurrence,rrule', 'nullable', 'string', 'max:300', new ValidRecurrenceRule],
            'patterns.*.starts_on' => ['nullable', 'date'],
            'patterns.*.start_time' => ['required', 'date_format:H:i'],
            'patterns.*.end_time' => ['required', 'date_format:H:i', 'different:patterns.*.start_time'],
            'patterns.*.id' => ['nullable', 'integer'],
            'patterns.*.employees' => ['array'],
            'patterns.*.employees.*' => ['integer', 'exists:employees,id'],
        ];
    }

    /** Meldingen bij rules(). */
    public static function messages(): array
    {
        return [
            'patterns.required' => 'Voeg minimaal één patroon toe.',
            'patterns.max' => 'Een taak kan hoogstens '.self::MAX_PER_TEMPLATE.' patronen hebben.',
            'patterns.*.weekdays.required_if' => 'Kies minimaal één weekdag.',
            'patterns.*.rrule.required_if' => 'Vul een code in.',
            'patterns.*.start_time.required' => 'Vul een begintijd in.',
            'patterns.*.end_time.required' => 'Vul een eindtijd in.',
            'patterns.*.end_time.different' => 'Begin- en eindtijd mogen niet gelijk zijn.',
        ];
    }

    /** Vaste krachten uit gevalideerde formulierdata, in volgorde en zonder dubbelen. */
    public static function employeeIdsFromInput(array $input): array
    {
        return collect($input['employees'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** Uit gevalideerde formulierdata naar kolommen. */
    public static function attributesFromInput(array $input, int $position): array
    {
        $recurrence = $input['recurrence'];

        return [
            'position' => $position,
            'recurrence' => $recurrence,
            'repeat_every' => $recurrence === 'rrule' ? 1 : (int) ($input['repeat_every'] ?? 1),
            'weekdays' => $recurrence === 'weekly' ? array_values(array_unique(array_map('intval', $input['weekdays'] ?? []))) : null,
            'nth' => $recurrence === 'monthly_nth_weekday' ? (int) $input['nth'] : null,
            'weekday' => $recurrence === 'monthly_nth_weekday' ? (int) $input['weekday'] : null,
            'day_of_month' => $recurrence === 'monthly_day' ? (int) $input['day_of_month'] : null,
            // Opgeslagen in genormaliseerde vorm: alleen de veilige, geparste onderdelen.
            'rrule' => $recurrence === 'rrule' ? RecurrenceRule::normalize($input['rrule']) : null,
            'starts_on' => $input['starts_on'] ?? null,
            'start_time' => $input['start_time'],
            'end_time' => $input['end_time'],
        ];
    }

    public function hasOwnTimes(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    /** "10:00–12:00". */
    public function timesLabel(TaskTemplate $template): string
    {
        return substr($this->startTime($template), 0, 5).'–'.substr($this->endTime($template), 0, 5);
    }

    /** Begintijd (H:i:s of H:i). Patronen hebben eigen tijden; die van de taak zijn alleen een terugval voor oude gegevens. */
    public function startTime(TaskTemplate $template): string
    {
        return $this->hasOwnTimes() ? $this->start_time : $template->start_time;
    }

    public function endTime(TaskTemplate $template): string
    {
        return $this->hasOwnTimes() ? $this->end_time : $template->end_time;
    }

    /** Het anker voor de telling: starts_on, anders Vanaf van de taak. */
    public function anchor(TaskTemplate $template): CarbonImmutable
    {
        return CarbonImmutable::instance($this->starts_on ?? $template->valid_from)->startOfDay();
    }

    /** Valt dit patroon op deze dag? (De geldigheidsperiode van de taak controleert de aanroeper.) */
    public function occursOn(CarbonImmutable $day, TaskTemplate $template): bool
    {
        $day = $day->startOfDay();
        $anchor = $this->anchor($template);
        if ($day->lt($anchor)) {
            return false;
        }
        $every = max(1, (int) $this->repeat_every);

        switch ($this->recurrence) {
            case 'weekly':
                if (! in_array($day->dayOfWeekIso, array_map('intval', $this->weekdays ?? []), true)) {
                    return false;
                }

                return intdiv(self::daysBetween($anchor->startOfWeek(), $day->startOfWeek()), 7) % $every === 0;

            case 'monthly_nth_weekday':
                if ($day->dayOfWeekIso !== (int) $this->weekday || ! self::monthMatches($anchor, $day, $every)) {
                    return false;
                }
                if ((int) $this->nth === -1) {
                    return $day->addWeek()->month !== $day->month;
                }

                return (int) ceil($day->day / 7) === (int) $this->nth;

            case 'monthly_day':
                if (! self::monthMatches($anchor, $day, $every)) {
                    return false;
                }

                return $day->day === min((int) $this->day_of_month, $day->daysInMonth);

            case 'rrule':
                // Per maand uitrekenen en cachen: de generator vraagt dag voor dag.
                $dates = RecurrenceRule::datesBetween((string) $this->rrule, $anchor, $day->startOfMonth(), $day->endOfMonth());

                return isset($dates[$day->toDateString()]);
        }

        return false;
    }

    /** Leesbare omschrijving, bijv. "Om de week op ma, wo" of "Elk kwartaal op de eerste maandag". */
    public function label(): string
    {
        if ($this->recurrence === 'rrule') {
            return 'Eigen patroon: '.RecurrenceRule::describe((string) $this->rrule);
        }

        $days = config('poetsploeg.weekdays_short');
        $every = max(1, (int) $this->repeat_every);
        $weeks = match ($every) {
            1 => 'Elke week',
            2 => 'Om de week',
            default => "Elke {$every} weken",
        };
        $months = match ($every) {
            1 => 'Elke maand',
            2 => 'Om de maand',
            3 => 'Elk kwartaal',
            6 => 'Elk half jaar',
            12 => 'Elk jaar',
            default => "Elke {$every} maanden",
        };
        $nth = [1 => 'eerste', 2 => 'tweede', 3 => 'derde', 4 => 'vierde', -1 => 'laatste'][$this->nth] ?? $this->nth.'e';

        return match ($this->recurrence) {
            'weekly' => $weeks.' op '.collect($this->weekdays ?? [])->sort()->map(fn ($d) => $days[$d] ?? $d)->implode(', '),
            'monthly_nth_weekday' => $months.' op de '.$nth.' '.(config('poetsploeg.weekdays')[$this->weekday] ?? ''),
            'monthly_day' => $months.' op de '.$this->day_of_month.'e',
            default => $this->recurrence,
        };
    }

    /** Beginstand voor de herhalingskiezer in het formulier (resources/js/recurrence.js). */
    public function toEditor(TaskTemplate $template): array
    {
        return [
            'recurrence' => $this->recurrence,
            'every' => max(1, (int) $this->repeat_every),
            'weekdays' => array_map('intval', $this->weekdays ?? []),
            'nth' => (int) ($this->nth ?? 1),
            'weekday' => (int) ($this->weekday ?? 1),
            'dayOfMonth' => (int) ($this->day_of_month ?? 1),
            'rrule' => (string) $this->rrule,
            'startsOn' => ($this->starts_on ?? $template->valid_from)?->toDateString(),
            'id' => $this->id,
            'startTime' => substr($this->startTime($template), 0, 5),
            'endTime' => substr($this->endTime($template), 0, 5),
            'employees' => $this->relationLoaded('employees') ? $this->employees->pluck('id')->map(fn ($id) => (int) $id)->values()->all() : [],
        ];
    }

    /** Aantal kalenderdagen, ongevoelig voor zomer-/wintertijd. */
    private static function daysBetween(CarbonImmutable $a, CarbonImmutable $b): int
    {
        $utc = fn (CarbonImmutable $d) => CarbonImmutable::parse($d->toDateString(), 'UTC');

        return (int) round($utc($a)->diffInDays($utc($b)));
    }

    private static function monthMatches(CarbonImmutable $anchor, CarbonImmutable $day, int $every): bool
    {
        $months = ($day->year * 12 + $day->month) - ($anchor->year * 12 + $anchor->month);

        return $months % $every === 0;
    }
}

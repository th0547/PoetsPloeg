<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class AvailabilityPattern extends Model
{
    public const TYPES = [
        'available' => 'Kan wel',
        'unavailable' => 'Kan niet',
    ];

    /** Herhalingen zoals in het formulier: sleutel => [eenheid, elke N]. */
    public const REPEATS = [
        'w1' => ['week', 1, 'Elke week'],
        'w2' => ['week', 2, 'Om de week'],
        'w3' => ['week', 3, 'Om de 3 weken'],
        'w4' => ['week', 4, 'Om de 4 weken'],
        'd1' => ['day', 1, 'Elke dag'],
        'd2' => ['day', 2, 'Om de dag'],
        'd3' => ['day', 3, 'Elke 3 dagen'],
    ];

    protected $fillable = [
        'employee_id', 'weekday', 'type', 'repeat_unit', 'repeat_every', 'starts_on', 'valid_from', 'valid_until', 'start_time', 'end_time', 'note',
    ];

    protected $attributes = ['type' => 'available', 'repeat_unit' => 'week', 'repeat_every' => 1];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'repeat_every' => 'integer',
            'starts_on' => 'date',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    /** Validatie van de tijdvakken uit het formulier (veld "patterns"). */
    public static function rules(): array
    {
        return [
            'patterns' => ['array', 'max:100'],
            'patterns.*.weekday' => ['required', 'integer', 'between:1,7'],
            'patterns.*.type' => ['required', Rule::in(array_keys(self::TYPES))],
            'patterns.*.repeat' => ['nullable', Rule::in(array_keys(self::REPEATS))],
            'patterns.*.starts_on' => ['nullable', 'date'],
            'patterns.*.valid_from' => ['nullable', 'date'],
            'patterns.*.valid_until' => ['nullable', 'date', function (string $attribute, mixed $value, \Closure $fail) {
                $from = request()->input(str_replace('valid_until', 'valid_from', $attribute));
                if (filled($from) && strtotime((string) $value) < strtotime((string) $from)) {
                    $fail('"Tot en met" ligt vóór "geldig van".');
                }
            }],
            'patterns.*.note' => ['nullable', 'string', 'max:100'],
            'patterns.*.start_time' => ['required', 'date_format:H:i'],
            'patterns.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isUnavailable(): bool
    {
        return $this->type === 'unavailable';
    }

    /** Sleutel voor het formulier, bijv. 'w2' voor om de week. */
    public function repeatKey(): string
    {
        $key = ($this->repeat_unit === 'day' ? 'd' : 'w').max(1, (int) $this->repeat_every);

        return isset(self::REPEATS[$key]) ? $key : 'w1';
    }

    /** Valt deze datum binnen de periode (geldig van / t/m) van dit tijdvak? */
    public function coversDate(\DateTimeInterface $date): bool
    {
        $day = CarbonImmutable::instance($date)->toDateString();

        return ($this->valid_from === null || $this->valid_from->toDateString() <= $day)
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $day);
    }

    /** Geldt dit tijdvak op deze datum? */
    public function occursOn(\DateTimeInterface $date): bool
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        if (! $this->coversDate($date)) {
            return false;
        }
        $every = max(1, (int) $this->repeat_every);
        $anchor = $this->starts_on ? CarbonImmutable::instance($this->starts_on)->startOfDay() : null;

        if ($this->repeat_unit === 'day') {
            if ($every === 1 || ! $anchor) {
                return true;
            }

            return self::floorMod(self::daysBetween($anchor, $date), $every) === 0;
        }

        if ($date->dayOfWeekIso !== (int) $this->weekday) {
            return false;
        }
        if ($every === 1 || ! $anchor) {
            return true;
        }

        return self::floorMod(intdiv(self::daysBetween($anchor->startOfWeek(), $date->startOfWeek()), 7), $every) === 0;
    }

    /** Aantal kalenderdagen, ongevoelig voor zomer-/wintertijd. */
    private static function daysBetween(CarbonImmutable $a, CarbonImmutable $b): int
    {
        $utc = fn (CarbonImmutable $d) => CarbonImmutable::parse($d->toDateString(), 'UTC');

        return (int) round($utc($a)->diffInDays($utc($b), false));
    }

    private static function floorMod(int $a, int $n): int
    {
        return (($a % $n) + $n) % $n;
    }
}

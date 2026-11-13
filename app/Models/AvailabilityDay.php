<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Beschikbaarheid op één datum. Heeft een dag zulke regels, dan gelden die en niet het weekpatroon.
 * 00:00–00:00 is de hele dag.
 */
class AvailabilityDay extends Model
{
    /** Keuzes per dag in het formulier. */
    public const MODES = [
        'pattern' => 'Zoals mijn weekpatroon',
        'unavailable_all' => 'Hele dag niet',
        'available_all' => 'Hele dag wel',
        'slots' => 'Alleen deze tijden',
    ];

    public const MAX_SLOTS = 6;

    protected $fillable = ['employee_id', 'date', 'type', 'start_time', 'end_time', 'note'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Validatie van het veld "days" uit het formulier. */
    public static function rules(): array
    {
        return [
            'days' => ['array', 'max:400'],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct', 'after_or_equal:today', 'before_or_equal:'.today()->addYear()->toDateString()],
            'days.*.mode' => ['required', Rule::in(array_keys(self::MODES))],
            'days.*.note' => ['nullable', 'string', 'max:100'],
            'days.*.slots' => ['array', 'max:'.self::MAX_SLOTS, 'required_if:days.*.mode,slots'],
            'days.*.slots.*.type' => ['required', Rule::in(array_keys(AvailabilityPattern::TYPES))],
            'days.*.slots.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.slots.*.end_time' => ['required', 'date_format:H:i'],
            'days.*.slots.*.note' => ['nullable', 'string', 'max:100'],
        ];
    }

    public static function messages(): array
    {
        return [
            'days.*.date.after_or_equal' => 'Een dag in het verleden kun je niet meer wijzigen.',
            'days.*.date.before_or_equal' => 'Je kunt tot een jaar vooruit een dag invullen.',
            'days.*.date.distinct' => 'Elke datum mag maar één keer voorkomen.',
            'days.*.slots.required_if' => 'Geef bij "Alleen deze tijden" minstens één tijdvak op.',
            'days.*.slots.max' => 'Per dag kun je maximaal '.self::MAX_SLOTS.' tijdvakken opgeven.',
        ];
    }

    /**
     * Van formulier naar rijen om op te slaan. "Zoals mijn weekpatroon" slaan we niet op.
     *
     * @return list<array<string, mixed>>
     */
    public static function rowsFromForm(array $days): array
    {
        $rows = [];
        foreach ($days as $day) {
            $note = filled($day['note'] ?? null) ? $day['note'] : null;
            $slots = match ($day['mode']) {
                'unavailable_all' => [['type' => 'unavailable', 'start_time' => '00:00', 'end_time' => '00:00', 'note' => $note]],
                'available_all' => [['type' => 'available', 'start_time' => '00:00', 'end_time' => '00:00', 'note' => $note]],
                'slots' => $day['slots'] ?? [],
                default => [],
            };
            foreach ($slots as $slot) {
                $rows[] = [
                    'date' => $day['date'],
                    'type' => $slot['type'],
                    'start_time' => $slot['start_time'],
                    'end_time' => $slot['end_time'],
                    'note' => filled($slot['note'] ?? null) ? $slot['note'] : null,
                ];
            }
        }

        return $rows;
    }

    /**
     * Van opgeslagen rijen naar het formulier: per datum een keuze en tijdvakken.
     *
     * @param  Collection<int, self>  $days
     */
    public static function formRows(Collection $days): array
    {
        return $days->groupBy(fn (self $d) => $d->date->toDateString())
            ->map(function (Collection $rows, string $date) {
                $first = $rows->first();
                $mode = $rows->count() === 1 && $first->isWholeDay()
                    ? ($first->type === 'unavailable' ? 'unavailable_all' : 'available_all')
                    : 'slots';

                return [
                    'date' => $date,
                    'mode' => $mode,
                    'note' => $mode === 'slots' ? null : $first->note,
                    'past' => $date < today()->toDateString(),
                    'slots' => $mode === 'slots' ? $rows->sortBy('start_time')->map(fn (self $r) => [
                        'type' => $r->type,
                        'start_time' => substr($r->start_time, 0, 5),
                        'end_time' => substr($r->end_time, 0, 5),
                        'note' => $r->note,
                    ])->values()->all() : [],
                ];
            })->sortKeys()->values()->all();
    }

    /** Vergelijkbare weergave van de regels per datum (om wijzigingen te zien). @return array<string, string> */
    public static function signatures(iterable $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r instanceof self) {
                $r = ['date' => $r->date->toDateString(), 'type' => $r->type, 'start_time' => $r->start_time, 'end_time' => $r->end_time];
            }
            $date = substr((string) $r['date'], 0, 10);
            $out[$date][] = $r['type'].substr($r['start_time'], 0, 5).substr($r['end_time'], 0, 5);
        }

        return array_map(function ($list) {
            sort($list);

            return implode('|', $list);
        }, $out);
    }

    public function isWholeDay(): bool
    {
        return substr($this->start_time, 0, 5) === '00:00' && substr($this->end_time, 0, 5) === '00:00';
    }
}

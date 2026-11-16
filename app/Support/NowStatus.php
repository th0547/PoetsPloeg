<?php

namespace App\Support;

use App\Models\Absence;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Waar is een medewerker nu? Eén definitie voor het rooster, het medewerkerpaneel, de zoekbalk
 * en de pagina Vandaag.
 *
 * - 'absent':  afwezig op dit moment (goedgekeurde afwezigheid)
 * - 'working': bezig met een dienst
 * - 'later':   vandaag nog een dienst te gaan
 * - 'done':    vandaag gewerkt, niets meer gepland
 * - 'free':    vandaag geen diensten
 */
class NowStatus
{
    public const LABELS = [
        'absent' => 'Afwezig',
        'working' => 'Aan het werk',
        'later' => 'Straks',
        'done' => 'Klaar voor vandaag',
        'free' => 'Vandaag vrij',
    ];

    /**
     * @param  Collection<int, int>|array<int, int>  $employeeIds
     * @return array<int, array{state: string, label: string, building: ?string, building_id: ?int, shift_id: ?int, until: ?string, from: ?string, today: list<array>}>
     */
    public static function forEmployees(Collection|array $employeeIds, ?CarbonImmutable $now = null): array
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->unique()->values();
        $now ??= CarbonImmutable::now();
        if ($ids->isEmpty()) {
            return [];
        }
        $dayStart = $now->startOfDay();
        $dayEnd = $dayStart->addDay();

        // Diensten van vandaag, plus een nachtdienst van gisteren die nog loopt.
        $shifts = Shift::query()->active()
            ->overlapping($dayStart, $dayEnd)
            ->whereHas('assignments', fn ($q) => $q->whereIn('employee_id', $ids))
            ->with(['building:id,name', 'assignments:id,shift_id,employee_id'])
            ->orderBy('starts_at')
            ->get();

        $absences = Absence::query()->effective()
            ->whereIn('employee_id', $ids)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->get()
            ->keyBy('employee_id');

        $byEmployee = [];
        foreach ($shifts as $shift) {
            foreach ($shift->assignments as $assignment) {
                $byEmployee[$assignment->employee_id][] = $shift;
            }
        }

        $result = [];
        foreach ($ids as $id) {
            $mine = $byEmployee[$id] ?? [];
            $result[$id] = self::status($mine, $absences->get($id), $now) + [
                // De diensten van vandaag, voor "hoe ziet zijn dag eruit".
                'today' => array_map(fn (Shift $s) => [
                    'shift_id' => $s->id,
                    'start' => $s->starts_at->format('H:i'),
                    'end' => $s->ends_at->format('H:i'),
                    'building' => $s->building->name,
                    'title' => $s->title,
                    'state' => $s->ends_at->lte($now) ? 'done' : ($s->starts_at->lte($now) ? 'now' : 'later'),
                ], $mine),
            ];
        }

        return $result;
    }

    public static function forEmployee(int $employeeId, ?CarbonImmutable $now = null): array
    {
        return self::forEmployees([$employeeId], $now)[$employeeId];
    }

    /**
     * @param  list<Shift>  $shifts  diensten van vandaag, op begintijd
     */
    private static function status(array $shifts, ?Absence $absence, CarbonImmutable $now): array
    {
        $base = ['building' => null, 'building_id' => null, 'shift_id' => null, 'until' => null, 'from' => null];

        if ($absence) {
            $until = $absence->ends_at->isSameDay($now)
                ? ($absence->ends_at->format('H:i') === '23:59' ? 'vandaag' : 'tot '.$absence->ends_at->format('H:i'))
                : 't/m '.$absence->ends_at->translatedFormat('D j M');

            return ['state' => 'absent', 'label' => $absence->typeLabel().' ('.$until.')'] + $base;
        }

        foreach ($shifts as $shift) {
            if ($shift->starts_at->lte($now) && $shift->ends_at->gt($now)) {
                return [
                    'state' => 'working',
                    'label' => 'Nu bij '.$shift->building->name.' tot '.$shift->ends_at->format('H:i'),
                    'building' => $shift->building->name,
                    'building_id' => $shift->building_id,
                    'shift_id' => $shift->id,
                    'until' => $shift->ends_at->format('H:i'),
                    'from' => $shift->starts_at->format('H:i'),
                ];
            }
        }

        foreach ($shifts as $shift) {
            if ($shift->starts_at->gt($now)) {
                return [
                    'state' => 'later',
                    'label' => 'Straks '.$shift->starts_at->format('H:i').' bij '.$shift->building->name,
                    'building' => $shift->building->name,
                    'building_id' => $shift->building_id,
                    'shift_id' => $shift->id,
                    'until' => $shift->ends_at->format('H:i'),
                    'from' => $shift->starts_at->format('H:i'),
                ];
            }
        }

        if ($shifts !== []) {
            return ['state' => 'done', 'label' => self::LABELS['done']] + $base;
        }

        return ['state' => 'free', 'label' => self::LABELS['free']] + $base;
    }
}

<?php

namespace App\Services;

use App\Models\AvailabilityPattern;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Toetst een tijdvak aan het weekpatroon (kan wel / kan niet) van een medewerker.
 */
class AvailabilityCheck
{
    /**
     * - 'blocked': de dienst valt (deels) in een "kan niet"-tijdvak (bijv. school);
     * - 'ok': binnen een "kan wel"-tijdvak, of er zijn alleen "kan niet"-tijdvakken en die raken de dienst niet;
     * - 'fail': er zijn "kan wel"-tijdvakken, maar de dienst valt daar buiten;
     * - 'none': er is geen weekpatroon ingevuld.
     *
     * @param  Collection<int, AvailabilityPattern>  $patterns
     * @return array{0: string, 1: ?string} [status, omschrijving van het blokkerende tijdvak]
     */
    public static function evaluate(Collection $patterns, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        if ($patterns->isEmpty()) {
            return ['none', null];
        }

        $start = CarbonImmutable::instance($start);
        $end = CarbonImmutable::instance($end);
        $startDay = $start->startOfDay();
        $nextDay = $startDay->addDay();
        $startMin = $start->hour * 60 + $start->minute;
        $endMin = $startMin + (int) $start->diffInMinutes($end);

        // Tijdvakken als minuten vanaf middernacht van de begindag; een dienst over middernacht raakt ook de volgende dag.
        $windows = fn (string $type) => $patterns->where('type', $type)
            ->flatMap(function ($p) use ($startDay, $nextDay) {
                $pStart = self::minutes($p->start_time);
                $pEnd = self::minutes($p->end_time);
                if ($pEnd <= $pStart) {
                    $pEnd += 1440; // tot middernacht of later
                }
                $result = [];
                if ($p->occursOn($startDay)) {
                    $result[] = [$pStart, $pEnd, $p];
                }
                if ($p->occursOn($nextDay)) {
                    $result[] = [$pStart + 1440, $pEnd + 1440, $p];
                }

                return $result;
            });

        $blocking = $windows('unavailable')->first(fn ($w) => $w[0] < $endMin && $w[1] > $startMin);
        if ($blocking) {
            $p = $blocking[2];

            return ['blocked', trim(($p->note ?: 'kan niet').' ('.substr($p->start_time, 0, 5).'–'.substr($p->end_time, 0, 5).')')];
        }

        if ($patterns->where('type', 'available')->isEmpty()) {
            return ['ok', null];
        }

        return $windows('available')->contains(fn ($w) => $w[0] <= $startMin && $w[1] >= $endMin) ? ['ok', null] : ['fail', null];
    }

    /** Valt de dienst buiten de beschikbaarheid ("kan niet" of buiten "kan wel")? */
    public static function isOutside(Collection $patterns, \DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        return in_array(self::evaluate($patterns, $start, $end)[0], ['blocked', 'fail'], true);
    }

    private static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}

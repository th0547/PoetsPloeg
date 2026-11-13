<?php

namespace App\Services;

use App\Models\AvailabilityPattern;
use App\Models\Employee;
use App\Support\AvailabilitySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Toetst een tijdvak aan de beschikbaarheid (weekpatroon en losse datums) van een medewerker.
 */
class AvailabilityCheck
{
    /**
     * - 'blocked': de dienst valt (deels) in een "kan niet"-tijdvak (bijv. school);
     * - 'ok': de dienst valt helemaal binnen beschikbare tijd;
     * - 'fail': er zijn "kan wel"-tijdvakken, maar de dienst valt (deels) daarbuiten;
     * - 'none': er is niets ingevuld.
     *
     * @param  AvailabilitySchedule|Employee|Collection<int, AvailabilityPattern>  $availability
     * @return array{0: string, 1: ?string} [status, omschrijving van het blokkerende tijdvak]
     */
    public static function evaluate(AvailabilitySchedule|Employee|Collection $availability, \DateTimeInterface $start, \DateTimeInterface $end): array
    {
        $schedule = self::schedule($availability);
        if ($schedule->isEmpty()) {
            return ['none', null];
        }

        $start = CarbonImmutable::instance($start);
        $end = CarbonImmutable::instance($end);
        $startDay = $start->startOfDay();
        $startMin = $start->hour * 60 + $start->minute;
        $endMin = $startMin + (int) $start->diffInMinutes($end);

        // Alles in minuten vanaf middernacht van de begindag. De vorige dag telt mee voor tijdvakken over
        // middernacht; een dienst over middernacht raakt ook de volgende dag(en).
        $available = [];
        $rest = [];
        for ($offset = -1; $offset * 1440 < $endMin; $offset++) {
            $day = $startDay->addDays($offset);
            $info = $schedule->windowsOn($day);
            $rest[$offset] = $info['rest'];
            foreach ($info['windows'] as $w) {
                $from = $w['from'] + $offset * 1440;
                $to = $w['to'] + $offset * 1440;
                if ($w['type'] === 'unavailable' && $from < $endMin && $to > $startMin) {
                    return ['blocked', trim(($w['note'] ?: 'kan niet').' ('.self::time($w['from']).'–'.self::time($w['to']).')')];
                }
                if ($w['type'] === 'available') {
                    $available[] = [$from, $to];
                }
            }
        }

        // Elk stuk van de dienst moet op een dag met "rest beschikbaar" vallen, of binnen "kan wel".
        for ($offset = 0; $offset * 1440 < $endMin; $offset++) {
            if ($rest[$offset] === 'available') {
                continue;
            }
            $from = max($startMin, $offset * 1440);
            $to = min($endMin, ($offset + 1) * 1440);
            if (! self::covered($available, $from, $to)) {
                return ['fail', null];
            }
        }

        return ['ok', null];
    }

    /** Valt de dienst buiten de beschikbaarheid ("kan niet" of buiten "kan wel")? */
    public static function isOutside(AvailabilitySchedule|Employee|Collection $availability, \DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        return in_array(self::evaluate($availability, $start, $end)[0], ['blocked', 'fail'], true);
    }

    private static function schedule(AvailabilitySchedule|Employee|Collection $availability): AvailabilitySchedule
    {
        return match (true) {
            $availability instanceof AvailabilitySchedule => $availability,
            $availability instanceof Employee => $availability->availabilitySchedule(),
            default => new AvailabilitySchedule($availability, collect()),
        };
    }

    /** Wordt [from, to) helemaal gedekt door de tijdvakken samen? @param list<array{0: int, 1: int}> $intervals */
    private static function covered(array $intervals, int $from, int $to): bool
    {
        usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
        $reach = $from;
        foreach ($intervals as [$s, $e]) {
            if ($s > $reach) {
                break;
            }
            $reach = max($reach, $e);
            if ($reach >= $to) {
                return true;
            }
        }

        return $reach >= $to;
    }

    private static function time(int $minutes): string
    {
        $minutes %= 1440;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}

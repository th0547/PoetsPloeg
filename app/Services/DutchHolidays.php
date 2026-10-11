<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Nederlandse feestdagen, berekend zonder externe bron.
 */
class DutchHolidays
{
    /** @var array<int, array<string, string>> */
    private array $cache = [];

    /**
     * @return array<string, string> datum (Y-m-d) => naam
     */
    public function forYear(int $year): array
    {
        if (isset($this->cache[$year])) {
            return $this->cache[$year];
        }

        $easter = $this->easterSunday($year);

        $kingsDay = CarbonImmutable::create($year, 4, 27);
        if ($kingsDay->isSunday()) {
            $kingsDay = $kingsDay->subDay();
        }

        $days = [
            CarbonImmutable::create($year, 1, 1)->toDateString() => 'Nieuwjaarsdag',
            $easter->subDays(2)->toDateString() => 'Goede Vrijdag',
            $easter->toDateString() => 'Eerste Paasdag',
            $easter->addDay()->toDateString() => 'Tweede Paasdag',
            $kingsDay->toDateString() => 'Koningsdag',
            CarbonImmutable::create($year, 5, 5)->toDateString() => 'Bevrijdingsdag',
            $easter->addDays(39)->toDateString() => 'Hemelvaartsdag',
            $easter->addDays(49)->toDateString() => 'Eerste Pinksterdag',
            $easter->addDays(50)->toDateString() => 'Tweede Pinksterdag',
            CarbonImmutable::create($year, 12, 25)->toDateString() => 'Eerste Kerstdag',
            CarbonImmutable::create($year, 12, 26)->toDateString() => 'Tweede Kerstdag',
        ];
        ksort($days);

        return $this->cache[$year] = $days;
    }

    /**
     * Dagen waarop diensten met "feestdagen overslaan" niet worden ingepland.
     * Goede Vrijdag en Bevrijdingsdag zijn voor de meeste opdrachtgevers gewone werkdagen.
     */
    public function isSkippable(\DateTimeInterface $date): bool
    {
        $name = $this->nameFor($date);

        return $name !== null && ! in_array($name, ['Goede Vrijdag', 'Bevrijdingsdag'], true);
    }

    public function nameFor(\DateTimeInterface $date): ?string
    {
        $d = CarbonImmutable::instance($date);

        return $this->forYear($d->year)[$d->toDateString()] ?? null;
    }

    /**
     * @return array<string, string> alle feestdagen tussen from en to (inclusief)
     */
    public function between(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->startOfDay();
        $result = [];
        for ($y = $from->year; $y <= $to->year; $y++) {
            foreach ($this->forYear($y) as $date => $name) {
                if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                    $result[$date] = $name;
                }
            }
        }

        return $result;
    }

    /** Anonieme Gregoriaanse berekening (Meeus/Jones/Butcher). */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}

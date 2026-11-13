<?php

namespace App\Support;

use App\Models\AvailabilityDay;
use App\Models\AvailabilityPattern;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Wat er op een dag geldt voor de beschikbaarheid van een medewerker: het weekpatroon (eventueel met
 * periode), tenzij de dag eigen regels heeft (AvailabilityDay). Die vervangen het patroon die dag helemaal.
 * Gelijk aan windowsOn() in resources/js/availability.js.
 */
class AvailabilitySchedule
{
    /** @var array<string, Collection<int, AvailabilityDay>> */
    private array $byDate;

    /**
     * @param  Collection<int, AvailabilityPattern>  $patterns
     * @param  Collection<int, AvailabilityDay>  $days
     */
    public function __construct(public readonly Collection $patterns, public readonly Collection $days)
    {
        $this->byDate = $days->groupBy(fn (AvailabilityDay $d) => $d->date->toDateString())->all();
    }

    public static function forEmployee(Employee $employee): self
    {
        $employee->loadMissing(['availabilityPatterns', 'availabilityDays']);

        return new self($employee->availabilityPatterns, $employee->availabilityDays);
    }

    /** Niets ingevuld: geen voorkeur, altijd beschikbaar. */
    public function isEmpty(): bool
    {
        return $this->patterns->isEmpty() && $this->days->isEmpty();
    }

    public function hasDateRules(\DateTimeInterface $day): bool
    {
        return isset($this->byDate[CarbonImmutable::instance($day)->toDateString()]);
    }

    /**
     * Tijdvakken op deze dag, in minuten vanaf middernacht (een eind na middernacht is > 1440),
     * en wat er buiten die tijdvakken geldt.
     *
     * @return array{windows: list<array{type: string, from: int, to: int, note: ?string}>, rest: string, specific: bool}
     */
    public function windowsOn(\DateTimeInterface $day): array
    {
        $day = CarbonImmutable::instance($day)->startOfDay();
        $specific = $this->byDate[$day->toDateString()] ?? null;

        if ($specific) {
            $rules = $specific;
        } else {
            $rules = $this->patterns->filter(fn (AvailabilityPattern $p) => $p->occursOn($day));
        }
        $windows = $rules->map(function ($r) {
            $from = self::minutes($r->start_time);
            $to = self::minutes($r->end_time);

            return ['type' => $r->type, 'from' => $from, 'to' => $to <= $from ? $to + 1440 : $to, 'note' => $r->note];
        })->values()->all();

        // Buiten de tijdvakken: zijn er "kan wel"-regels, dan alleen die tijden; anders beschikbaar.
        // Bij het patroon tellen alleen tijdvakken waarvan de periode deze dag dekt.
        $hasAvailable = $specific
            ? $specific->contains('type', 'available')
            : $this->patterns->contains(fn (AvailabilityPattern $p) => $p->type === 'available' && $p->coversDate($day));

        return ['windows' => $windows, 'rest' => $hasAvailable ? 'unavailable' : 'available', 'specific' => (bool) $specific];
    }

    /** Beschikbare minuten van deze dag (0–1440), zonder afwezigheid. @return array<int, bool> */
    public function freeMinutes(\DateTimeInterface $day): array
    {
        ['windows' => $windows, 'rest' => $rest] = $this->windowsOn($day);
        $free = array_fill(0, 1440, $rest === 'available');
        foreach (['available' => true, 'unavailable' => false] as $type => $value) {
            foreach ($windows as $w) {
                if ($w['type'] !== $type) {
                    continue;
                }
                for ($m = $w['from'], $to = min(1440, $w['to']); $m < $to; $m++) {
                    $free[$m] = $value;
                }
            }
        }

        return $free;
    }

    /** Is dit een dag waarop de medewerker kan werken (voor de schatting van verlofuren)? */
    public function isWorkday(\DateTimeInterface $day): bool
    {
        ['windows' => $windows, 'rest' => $rest] = $this->windowsOn($day);
        if (collect($windows)->contains('type', 'available')) {
            return true;
        }

        return $rest === 'available'
            && ! collect($windows)->contains(fn ($w) => $w['type'] === 'unavailable' && $w['from'] === 0 && $w['to'] >= 1440);
    }

    public static function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }
}

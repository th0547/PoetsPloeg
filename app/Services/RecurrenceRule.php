<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RRule\RRule;

/**
 * Eigen herhalingspatroon als iCalendar-code (RRULE, RFC 5545), bijv. "FREQ=WEEKLY;INTERVAL=2;BYDAY=TH".
 *
 * Beveiliging: alleen een kleine set tekens en bekende onderdelen wordt geaccepteerd, en de code
 * wordt opnieuw opgebouwd uit de geparste onderdelen (normalize). Wat er wordt opgeslagen kan dus
 * nooit HTML- of JavaScript-tekens bevatten. Zie ook TaskTemplateTest.
 */
class RecurrenceRule
{
    public const MAX_LENGTH = 255;

    /** Toegestane onderdelen, in de volgorde waarin normalize() ze opschrijft. */
    public const PARTS = ['FREQ', 'INTERVAL', 'BYMONTH', 'BYWEEKNO', 'BYMONTHDAY', 'BYDAY', 'BYSETPOS', 'WKST', 'COUNT', 'UNTIL'];

    private const FREQS = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    private const DAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /** @var array<string, array<string, true>> */
    private static array $cache = [];

    /**
     * Controleer de code en geef hem genormaliseerd terug (hoofdletters, vaste volgorde, zonder "RRULE:").
     *
     * @throws InvalidArgumentException met een Nederlandse melding die de invoer zelf niet herhaalt
     */
    public static function normalize(string $code): string
    {
        $code = strtoupper(trim($code));
        if (str_starts_with($code, 'RRULE:')) {
            $code = substr($code, 6);
        }
        $code = trim($code, ';');

        if ($code === '') {
            throw new InvalidArgumentException('Vul een code in, bijvoorbeeld FREQ=WEEKLY;INTERVAL=2;BYDAY=TH.');
        }
        if (strlen($code) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('De code is te lang (maximaal '.self::MAX_LENGTH.' tekens).');
        }
        if (! preg_match('/^[A-Z0-9=;,+\-]+$/', $code)) {
            throw new InvalidArgumentException('In de code mogen alleen letters, cijfers en = ; , - staan.');
        }

        $parts = [];
        foreach (explode(';', $code) as $part) {
            $pieces = explode('=', $part);
            if (count($pieces) !== 2 || $pieces[0] === '' || $pieces[1] === '') {
                throw new InvalidArgumentException('Elk onderdeel schrijf je als NAAM=waarde, gescheiden door een puntkomma.');
            }
            [$key, $value] = $pieces;
            if (in_array($key, ['BYHOUR', 'BYMINUTE', 'BYSECOND'], true)) {
                throw new InvalidArgumentException('Uren en minuten kunnen niet in de code; stel de tijden bij het patroon in.');
            }
            if (! in_array($key, self::PARTS, true)) {
                $name = preg_match('/^[A-Z]{1,20}$/', $key) ? $key : 'dit onderdeel';
                throw new InvalidArgumentException("Onbekend onderdeel {$name}. Toegestaan: ".implode(', ', self::PARTS).'.');
            }
            if (isset($parts[$key])) {
                throw new InvalidArgumentException("{$key} staat er twee keer in.");
            }
            $parts[$key] = self::checkValue($key, $value);
        }

        if (! isset($parts['FREQ'])) {
            throw new InvalidArgumentException('FREQ ontbreekt, bijvoorbeeld FREQ=WEEKLY.');
        }
        if (isset($parts['COUNT'], $parts['UNTIL'])) {
            throw new InvalidArgumentException('Gebruik COUNT of UNTIL, niet allebei.');
        }
        if (isset($parts['BYWEEKNO']) && $parts['FREQ'] !== 'YEARLY') {
            throw new InvalidArgumentException('BYWEEKNO kan alleen met FREQ=YEARLY.');
        }

        $normalized = collect(self::PARTS)
            ->filter(fn ($key) => isset($parts[$key]))
            ->map(fn ($key) => $key.'='.$parts[$key])
            ->implode(';');

        // Laatste controle door de bibliotheek die ook het rekenwerk doet.
        try {
            self::rrule($normalized, new \DateTimeImmutable('2026-01-01'));
        } catch (\Throwable) {
            throw new InvalidArgumentException('Deze combinatie van onderdelen klopt niet. Kijk op de hulppagina voor voorbeelden.');
        }

        return $normalized;
    }

    /** Is de code geldig? */
    public static function isValid(string $code): bool
    {
        try {
            self::normalize($code);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Datums (Y-m-d) waarop de code valt tussen from en to (inclusief), geteld vanaf het anker.
     *
     * @return array<string, true>
     */
    public static function datesBetween(string $code, \DateTimeInterface $anchor, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $anchor = CarbonImmutable::instance($anchor)->startOfDay();
        $from = CarbonImmutable::instance($from)->startOfDay();
        $to = CarbonImmutable::instance($to)->endOfDay();
        $key = implode('|', [$code, $anchor->toDateString(), $from->toDateString(), $to->toDateString()]);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (count(self::$cache) > 500) {
            self::$cache = [];
        }

        $dates = [];
        try {
            $rule = self::rrule(self::normalize($code), $anchor);
            // Begrensd: hoogstens één dienst per dag over de gevraagde periode.
            $limit = (int) $from->diffInDays($to) + 2;
            $utc = fn (CarbonImmutable $d, string $time) => new \DateTimeImmutable($d->toDateString().' '.$time, new \DateTimeZone('UTC'));
            foreach ($rule->getOccurrencesBetween($utc($from, '00:00:00'), $utc($to, '23:59:59'), $limit) as $date) {
                $dates[$date->format('Y-m-d')] = true;
            }
        } catch (InvalidArgumentException) {
            // Ongeldige code (zou door de validatie niet in de database moeten komen): niets inplannen.
        }

        return self::$cache[$key] = $dates;
    }

    /**
     * De bibliotheek rekent in UTC op middernacht, zodat er alleen met datums wordt gerekend (geen
     * zomer-/wintertijd). UNTIL=JJJJMMDD wordt daarom het einde van die dag in UTC.
     */
    private static function rrule(string $normalized, \DateTimeInterface $anchor): RRule
    {
        $code = preg_replace('/UNTIL=(\d{8})(?!T)/', 'UNTIL=$1T235959Z', $normalized);
        $start = new \DateTimeImmutable($anchor->format('Y-m-d').' 00:00:00', new \DateTimeZone('UTC'));

        return new RRule($code, $start);
    }

    /** Leesbare omschrijving, bijv. "om de week op donderdag" of "elke maand op de laatste vrijdag". */
    public static function describe(string $code): string
    {
        try {
            $parts = collect(explode(';', self::normalize($code)))
                ->mapWithKeys(fn ($p) => [explode('=', $p)[0] => explode('=', $p)[1]]);
        } catch (InvalidArgumentException) {
            return 'ongeldige code';
        }

        $interval = (int) ($parts['INTERVAL'] ?? 1);
        [$one, $two, $unit] = match ($parts['FREQ']) {
            'DAILY' => ['elke dag', 'om de dag', 'dagen'],
            'WEEKLY' => ['elke week', 'om de week', 'weken'],
            'MONTHLY' => ['elke maand', 'om de maand', 'maanden'],
            default => ['elk jaar', 'om het jaar', 'jaar'],
        };
        $text = match ($interval) {
            1 => $one,
            2 => $two,
            default => "elke {$interval} {$unit}",
        };

        $weekdays = config('poetsploeg.weekdays');
        $ordinal = fn (int $n) => match ($n) {
            1 => 'eerste', 2 => 'tweede', 3 => 'derde', 4 => 'vierde', 5 => 'vijfde',
            -1 => 'laatste', -2 => 'een-na-laatste', default => $n.'e',
        };
        $list = fn (array $items) => count($items) <= 1 ? implode('', $items)
            : implode(', ', array_slice($items, 0, -1)).' en '.end($items);

        if (isset($parts['BYMONTH'])) {
            $months = array_map(fn ($m) => CarbonImmutable::create(2026, (int) $m, 1)->locale('nl')->translatedFormat('F'), explode(',', $parts['BYMONTH']));
            $text .= ' in '.$list($months);
        }
        if (isset($parts['BYWEEKNO'])) {
            $text .= ' in week '.$list(explode(',', $parts['BYWEEKNO']));
        }
        if (isset($parts['BYDAY'])) {
            $days = array_map(function ($token) use ($weekdays, $ordinal) {
                preg_match('/^([+-]?\d{1,2})?([A-Z]{2})$/', $token, $m);
                $name = $weekdays[array_search($m[2], self::DAYS, true) + 1];

                return ($m[1] ?? '') !== '' ? 'de '.$ordinal((int) $m[1]).' '.$name : $name;
            }, explode(',', $parts['BYDAY']));
            $text .= ' op '.$list($days);
        }
        if (isset($parts['BYMONTHDAY'])) {
            $days = array_map(fn ($d) => (int) $d === -1 ? 'de laatste dag' : ((int) $d < 0 ? 'de '.abs((int) $d).'e dag van achteren' : 'de '.(int) $d.'e'), explode(',', $parts['BYMONTHDAY']));
            $text .= ' op '.$list($days);
        }
        if (isset($parts['BYSETPOS'])) {
            $text .= ' (alleen de '.$list(array_map(fn ($n) => $ordinal((int) $n), explode(',', $parts['BYSETPOS']))).')';
        }
        if (isset($parts['COUNT'])) {
            $text .= ', '.(int) $parts['COUNT'].' keer';
        }
        if (isset($parts['UNTIL'])) {
            $text .= ', t/m '.CarbonImmutable::createFromFormat('!Ymd', substr($parts['UNTIL'], 0, 8))->locale('nl')->translatedFormat('j M Y');
        }

        return $text;
    }

    /** Controleer één waarde; geeft de genormaliseerde waarde terug. */
    private static function checkValue(string $key, string $value): string
    {
        $ints = function (string $value, int $min, int $max, bool $negative, string $message): string {
            $out = [];
            foreach (explode(',', $value) as $item) {
                if (! preg_match('/^[+-]?\d{1,3}$/', $item)) {
                    throw new InvalidArgumentException($message);
                }
                $n = (int) $item;
                if ($n === 0 || abs($n) < $min || abs($n) > $max || (! $negative && $n < 0)) {
                    throw new InvalidArgumentException($message);
                }
                $out[] = $n;
            }

            return implode(',', array_unique($out));
        };

        switch ($key) {
            case 'FREQ':
                if (in_array($value, ['HOURLY', 'MINUTELY', 'SECONDLY'], true)) {
                    throw new InvalidArgumentException('Uren en minuten kunnen niet in de code; kies FREQ=DAILY, WEEKLY, MONTHLY of YEARLY.');
                }
                if (! in_array($value, self::FREQS, true)) {
                    throw new InvalidArgumentException('FREQ moet DAILY, WEEKLY, MONTHLY of YEARLY zijn.');
                }

                return $value;
            case 'INTERVAL':
                return $ints($value, 1, 99, false, 'INTERVAL moet een getal van 1 t/m 99 zijn.');
            case 'COUNT':
                return $ints($value, 1, 999, false, 'COUNT moet een getal van 1 t/m 999 zijn.');
            case 'BYMONTH':
                return $ints($value, 1, 12, false, 'BYMONTH: gebruik maandnummers 1 t/m 12, gescheiden door komma\'s.');
            case 'BYMONTHDAY':
                return $ints($value, 1, 31, true, 'BYMONTHDAY: gebruik 1 t/m 31, of -1 voor de laatste dag van de maand.');
            case 'BYWEEKNO':
                return $ints($value, 1, 53, true, 'BYWEEKNO: gebruik weeknummers 1 t/m 53.');
            case 'BYSETPOS':
                return $ints($value, 1, 366, true, 'BYSETPOS: gebruik bijv. 1 (eerste) of -1 (laatste).');
            case 'WKST':
                if (! in_array($value, self::DAYS, true)) {
                    throw new InvalidArgumentException('WKST moet een dag zijn: MO, TU, WE, TH, FR, SA of SU.');
                }

                return $value;
            case 'BYDAY':
                $out = [];
                foreach (explode(',', $value) as $token) {
                    if (! preg_match('/^([+-]?)(\d{1,2})?(MO|TU|WE|TH|FR|SA|SU)$/', $token, $m)) {
                        throw new InvalidArgumentException('BYDAY: gebruik MO, TU, WE, TH, FR, SA of SU, eventueel met een getal ervoor (1MO, -1FR).');
                    }
                    $n = ($m[2] ?? '') === '' ? 0 : (int) $m[2];
                    if ($n > 53 || (($m[1] !== '' || ($m[2] ?? '') !== '') && $n === 0)) {
                        throw new InvalidArgumentException('BYDAY: het getal voor de dag moet tussen 1 en 53 liggen (of -1 voor de laatste).');
                    }
                    $out[] = ($n ? ($m[1] === '-' ? -$n : $n) : '').$m[3];
                }

                return implode(',', array_unique($out));
            case 'UNTIL':
                if (! preg_match('/^(\d{4})(\d{2})(\d{2})(T\d{6}Z?)?$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    throw new InvalidArgumentException('UNTIL schrijf je als JJJJMMDD, bijvoorbeeld 20270630.');
                }

                return $m[1].$m[2].$m[3];
        }

        throw new InvalidArgumentException('Onbekend onderdeel.');
    }
}

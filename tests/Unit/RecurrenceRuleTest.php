<?php

namespace Tests\Unit;

use App\Services\RecurrenceRule;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecurrenceRuleTest extends TestCase
{
    public function test_valid_codes_are_normalized(): void
    {
        $this->assertSame('FREQ=WEEKLY;INTERVAL=2;BYDAY=TH', RecurrenceRule::normalize(' rrule:byday=th;freq=weekly;interval=2; '));
        $this->assertSame('FREQ=MONTHLY;BYDAY=-1FR', RecurrenceRule::normalize('FREQ=MONTHLY;BYDAY=-1FR'));
        $this->assertSame('FREQ=MONTHLY;BYDAY=1MO', RecurrenceRule::normalize('FREQ=MONTHLY;BYDAY=+1MO'));
        $this->assertSame('FREQ=YEARLY;BYMONTH=3,9;BYDAY=1MO', RecurrenceRule::normalize('FREQ=YEARLY;BYDAY=1MO;BYMONTH=3,9,3'));
        $this->assertSame('FREQ=WEEKLY;BYDAY=SA;UNTIL=20270331', RecurrenceRule::normalize('FREQ=WEEKLY;BYDAY=SA;UNTIL=20270331T235959Z'));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidCodes(): array
    {
        return [
            'leeg' => ['', 'Vul een code in'],
            'geen FREQ' => ['INTERVAL=2', 'FREQ ontbreekt'],
            'per uur' => ['FREQ=HOURLY', 'Uren en minuten'],
            'BYHOUR' => ['FREQ=DAILY;BYHOUR=9', 'Uren en minuten'],
            'onbekend' => ['FREQ=DAILY;FOO=1', 'Onbekend onderdeel FOO'],
            'dubbel' => ['FREQ=DAILY;FREQ=WEEKLY', 'twee keer'],
            'interval 0' => ['FREQ=DAILY;INTERVAL=0', 'INTERVAL'],
            'count en until' => ['FREQ=DAILY;COUNT=3;UNTIL=20270101', 'niet allebei'],
            'foute dag' => ['FREQ=WEEKLY;BYDAY=XX', 'BYDAY'],
            'foute datum' => ['FREQ=DAILY;UNTIL=20271340', 'UNTIL'],
            'te lang' => [str_repeat('FREQ=DAILY;', 30), 'te lang'],
            'script' => ['<script>alert(1)</script>', 'alleen letters, cijfers'],
            'quote' => ["FREQ=WEEKLY;BYDAY=TH');alert(1);('", 'alleen letters, cijfers'],
            'attribuut' => ['FREQ=WEEKLY"onmouseover=alert(1)', 'alleen letters, cijfers'],
            'spatie' => ['FREQ=WEEKLY; BYDAY=TH', 'alleen letters, cijfers'],
        ];
    }

    #[DataProvider('invalidCodes')]
    public function test_invalid_codes_are_rejected_with_a_dutch_message(string $code, string $expected): void
    {
        try {
            RecurrenceRule::normalize($code);
            $this->fail('Verwacht een fout voor: '.$code);
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
            // De melding herhaalt nooit de ruwe invoer.
            $this->assertStringNotContainsString('<', $e->getMessage());
            $this->assertStringNotContainsString('alert', $e->getMessage());
        }
    }

    public function test_dates_are_counted_from_the_anchor(): void
    {
        $dates = array_keys(RecurrenceRule::datesBetween(
            'FREQ=WEEKLY;INTERVAL=2;BYDAY=TH',
            CarbonImmutable::parse('2026-11-16'),
            CarbonImmutable::parse('2026-11-01'),
            CarbonImmutable::parse('2026-12-31'),
        ));

        $this->assertSame(['2026-11-19', '2026-12-03', '2026-12-17', '2026-12-31'], $dates);
    }

    public function test_descriptions_are_readable(): void
    {
        $this->assertSame('om de week op donderdag', RecurrenceRule::describe('FREQ=WEEKLY;INTERVAL=2;BYDAY=TH'));
        $this->assertSame('elke maand op de laatste vrijdag', RecurrenceRule::describe('FREQ=MONTHLY;BYDAY=-1FR'));
        $this->assertSame('elk kwartaal op de 15e', str_replace('elke 3 maanden', 'elk kwartaal', RecurrenceRule::describe('FREQ=MONTHLY;INTERVAL=3;BYMONTHDAY=15')));
        $this->assertSame('elke week op dinsdag, 6 keer', RecurrenceRule::describe('FREQ=WEEKLY;BYDAY=TU;COUNT=6'));
        $this->assertSame('ongeldige code', RecurrenceRule::describe('<b>'));
    }
}

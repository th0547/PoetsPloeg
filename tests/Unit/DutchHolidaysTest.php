<?php

namespace Tests\Unit;

use App\Services\DutchHolidays;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DutchHolidaysTest extends TestCase
{
    public function test_calculates_moving_holidays(): void
    {
        $days = (new DutchHolidays)->forYear(2026);

        $this->assertSame('Eerste Paasdag', $days['2026-04-05']);
        $this->assertSame('Tweede Paasdag', $days['2026-04-06']);
        $this->assertSame('Hemelvaartsdag', $days['2026-05-14']);
        $this->assertSame('Tweede Pinksterdag', $days['2026-05-25']);
        $this->assertSame('Koningsdag', $days['2026-04-27']);
    }

    public function test_kings_day_moves_to_saturday_when_on_sunday(): void
    {
        $days = (new DutchHolidays)->forYear(2025);

        $this->assertSame('Koningsdag', $days['2025-04-26']);
        $this->assertSame('Eerste Paasdag', $days['2025-04-20']);
    }

    public function test_good_friday_is_not_skippable(): void
    {
        $holidays = new DutchHolidays;

        $this->assertFalse($holidays->isSkippable(CarbonImmutable::parse('2026-04-03')));
        $this->assertTrue($holidays->isSkippable(CarbonImmutable::parse('2026-12-25')));
        $this->assertFalse($holidays->isSkippable(CarbonImmutable::parse('2026-12-24')));
    }
}

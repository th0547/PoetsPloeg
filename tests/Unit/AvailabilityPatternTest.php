<?php

namespace Tests\Unit;

use App\Models\AvailabilityPattern;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class AvailabilityPatternTest extends TestCase
{
    private function pattern(array $attributes): AvailabilityPattern
    {
        return (new AvailabilityPattern)->forceFill($attributes + ['repeat_unit' => 'week', 'repeat_every' => 1, 'weekday' => 1]);
    }

    private function dates(AvailabilityPattern $p, string $from, int $days): array
    {
        $start = CarbonImmutable::parse($from);

        return collect(range(0, $days - 1))->map(fn ($i) => $start->addDays($i))
            ->filter(fn ($d) => $p->occursOn($d))->map->toDateString()->values()->all();
    }

    public function test_every_week_on_weekday(): void
    {
        $this->assertSame(['2026-10-12', '2026-10-19'], $this->dates($this->pattern(['weekday' => 1]), '2026-10-12', 14));
    }

    public function test_every_three_weeks_counts_from_start_week(): void
    {
        $p = $this->pattern(['weekday' => 3, 'repeat_every' => 3, 'starts_on' => '2026-10-16']); // vrijdag in week 42

        $this->assertSame(['2026-10-14', '2026-11-04'], $this->dates($p, '2026-10-12', 28));
        // Ook terug in de tijd loopt het ritme door.
        $this->assertSame(['2026-09-23'], $this->dates($p, '2026-09-21', 7));
    }

    public function test_every_other_day_across_dst(): void
    {
        $p = $this->pattern(['repeat_unit' => 'day', 'repeat_every' => 2, 'starts_on' => '2026-10-23']);

        $this->assertSame(['2026-10-23', '2026-10-25', '2026-10-27'], $this->dates($p, '2026-10-22', 7));
    }

    public function test_repeat_key(): void
    {
        $this->assertSame('w2', $this->pattern(['repeat_every' => 2])->repeatKey());
        $this->assertSame('d3', $this->pattern(['repeat_unit' => 'day', 'repeat_every' => 3])->repeatKey());
        $this->assertSame('w1', $this->pattern(['repeat_every' => 9])->repeatKey());
    }
}

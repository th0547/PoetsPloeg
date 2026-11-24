<?php

namespace Tests\Feature\Services;

use App\Models\TaskTemplate;
use App\Services\StaffHours;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffHoursTest extends TestCase
{
    use RefreshDatabase;

    private function perWeek(TaskTemplate $template): float
    {
        return app(StaffHours::class)->perWeek($template->fresh(), CarbonImmutable::parse('2026-11-23'));
    }

    public function test_hours_per_week_are_duration_times_staff(): void
    {
        // Ma, wo, vr 45 minuten met 2 personen: 3 × 0,75 × 2 = 4,5 u.
        $template = TaskTemplate::factory()->weekly([1, 3, 5], 1, ['start_time' => '07:00', 'end_time' => '07:45'])
            ->create(['required_staff' => 2, 'valid_from' => '2026-01-05']);
        $this->assertSame(4.5, $this->perWeek($template));

        // Twee patronen met andere tijden: ma, wo, vr 10–12 en di, do 12–13 = 6 + 2 = 8 u.
        $split = TaskTemplate::factory()
            ->weekly([1, 3, 5], 1, ['start_time' => '10:00', 'end_time' => '12:00'])
            ->weekly([2, 4], 1, ['position' => 1, 'start_time' => '12:00', 'end_time' => '13:00'])
            ->create(['valid_from' => '2026-01-05']);
        $this->assertSame(8.0, $this->perWeek($split));
    }

    public function test_irregular_patterns_give_an_average_and_overlapping_patterns_count_once(): void
    {
        // Elke maand op de tweede zaterdag 4 uur met 2 personen: 8 u per maand ≈ 1,8 u per week.
        $monthly = TaskTemplate::factory()->monthlyNth(2, 6, 1, ['start_time' => '09:00', 'end_time' => '13:00'])
            ->create(['required_staff' => 2, 'valid_from' => '2026-01-05']);
        $this->assertSame(1.8, $this->perWeek($monthly));
        $this->assertFalse(StaffHours::isRegular($monthly->fresh()));

        // Twee patronen op dezelfde dag en tijd geven één dienst.
        $double = TaskTemplate::factory()
            ->weekly([1], 1, ['start_time' => '18:00', 'end_time' => '20:00'])
            ->weekly([1, 2], 1, ['position' => 1, 'start_time' => '18:00', 'end_time' => '20:00'])
            ->create(['valid_from' => '2026-01-05']);
        $this->assertSame(4.0, $this->perWeek($double));
    }

    public function test_end_date_does_not_lower_the_rate_and_inactive_counts_zero(): void
    {
        $ending = TaskTemplate::factory()->weekly([1, 2, 3, 4, 5], 1, ['start_time' => '18:00', 'end_time' => '20:00'])
            ->create(['valid_from' => '2026-01-05', 'valid_until' => '2026-12-31']);
        $this->assertSame(10.0, $this->perWeek($ending));

        $ending->update(['active' => false]);
        $this->assertSame(0.0, $this->perWeek($ending));
    }
}

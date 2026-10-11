<?php

namespace Tests\Feature\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Models\TaskTemplate;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function generate(string $from, string $to): int
    {
        return app(ShiftGenerator::class)->generate(CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    public function test_weekly_template_creates_shifts_on_selected_weekdays(): void
    {
        $template = TaskTemplate::factory()->create(['weekdays' => [1, 3, 5]]);

        $this->assertSame(3, $this->generate('2026-10-12', '2026-10-18'));

        $dates = Shift::orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
        $this->assertSame(['2026-10-12', '2026-10-14', '2026-10-16'], $dates);

        $shift = Shift::first();
        $this->assertSame($template->id, $shift->task_template_id);
        $this->assertSame('2026-10-12 18:00', $shift->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-12 20:00', $shift->ends_at->format('Y-m-d H:i'));
    }

    public function test_generation_is_idempotent(): void
    {
        TaskTemplate::factory()->create();

        $this->generate('2026-10-12', '2026-10-25');
        $this->assertSame(0, $this->generate('2026-10-12', '2026-10-25'));
        $this->assertSame(6, Shift::count());
    }

    public function test_every_n_weeks_counts_from_valid_from(): void
    {
        TaskTemplate::factory()->create([
            'weekdays' => [2], 'repeat_every' => 4, 'valid_from' => '2026-10-06', 'name' => 'Glasbewassing',
        ]);

        $this->generate('2026-10-01', '2026-12-31');

        $dates = Shift::orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
        $this->assertSame(['2026-10-06', '2026-11-03', '2026-12-01', '2026-12-29'], $dates);
    }

    public function test_monthly_nth_and_last_weekday(): void
    {
        TaskTemplate::factory()->create([
            'recurrence' => 'monthly_nth_weekday', 'nth' => 1, 'weekday' => 1, 'weekdays' => null,
        ]);
        TaskTemplate::factory()->create([
            'recurrence' => 'monthly_nth_weekday', 'nth' => -1, 'weekday' => 5, 'weekdays' => null,
        ]);

        $this->generate('2026-10-01', '2026-11-30');

        $dates = Shift::orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
        $this->assertSame(['2026-10-05', '2026-10-30', '2026-11-02', '2026-11-27'], $dates);
    }

    public function test_monthly_day_is_clamped_to_end_of_month(): void
    {
        TaskTemplate::factory()->create(['recurrence' => 'monthly_day', 'day_of_month' => 31, 'weekdays' => null]);

        $this->generate('2026-10-01', '2026-11-30');

        $dates = Shift::orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
        $this->assertSame(['2026-10-31', '2026-11-30'], $dates);
    }

    public function test_skips_holidays_when_configured(): void
    {
        TaskTemplate::factory()->create(['weekdays' => [5], 'skip_holidays' => true]);
        TaskTemplate::factory()->create(['weekdays' => [5], 'skip_holidays' => false]);

        $this->generate('2026-12-25', '2026-12-25');

        $this->assertSame(1, Shift::count());
    }

    public function test_respects_validity_period(): void
    {
        TaskTemplate::factory()->create(['weekdays' => [1], 'valid_from' => '2026-10-19', 'valid_until' => '2026-10-26']);

        $this->generate('2026-10-01', '2026-11-30');

        $this->assertSame(2, Shift::count());
    }

    public function test_night_shift_ends_next_day(): void
    {
        TaskTemplate::factory()->create(['weekdays' => [1], 'start_time' => '22:00', 'end_time' => '01:30']);

        $this->generate('2026-10-12', '2026-10-12');

        $this->assertSame('2026-10-13 01:30', Shift::first()->ends_at->format('Y-m-d H:i'));
    }

    public function test_fills_fixed_staff_and_skills_but_skips_absent_employees(): void
    {
        $skill = Skill::factory()->create();
        $template = TaskTemplate::factory()->create(['weekdays' => [1, 3], 'required_staff' => 2]);
        $template->skills()->attach($skill);
        [$a, $b, $c] = Employee::factory()->count(3)->create();
        $template->employees()->attach([$a->id => ['position' => 0], $b->id => ['position' => 1], $c->id => ['position' => 2]]);
        Absence::factory()->create([
            'employee_id' => $a->id, 'starts_at' => '2026-10-14 00:00', 'ends_at' => '2026-10-14 23:59',
        ]);

        $this->generate('2026-10-12', '2026-10-14');

        $monday = Shift::whereDate('date', '2026-10-12')->first();
        $wednesday = Shift::whereDate('date', '2026-10-14')->first();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $monday->assignments->pluck('employee_id')->all());
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $wednesday->assignments->pluck('employee_id')->all());
        $this->assertSame('fixed', $monday->assignments->first()->source);
        $this->assertSame([$skill->id], $monday->skills->pluck('id')->all());
    }

    public function test_regenerate_keeps_modified_shifts(): void
    {
        $this->travelTo('2026-10-10 12:00');
        $template = TaskTemplate::factory()->create(['weekdays' => [1]]);
        app(ShiftGenerator::class)->generateAhead();
        $modified = Shift::orderBy('date')->first();
        $modified->update(['is_modified' => true, 'starts_at' => '2026-10-12 17:00']);

        $template->update(['start_time' => '19:00', 'end_time' => '21:00']);
        app(ShiftGenerator::class)->regenerateTemplate($template);

        $this->assertSame('17:00', $modified->fresh()->starts_at->format('H:i'));
        $this->assertSame('19:00', Shift::whereDate('date', '2026-10-19')->first()->starts_at->format('H:i'));
        $this->assertSame(12, Shift::count());
    }
}

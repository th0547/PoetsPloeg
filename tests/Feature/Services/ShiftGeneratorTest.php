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
        $template = TaskTemplate::factory()->weekly([1, 3, 5])->create();

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

    public function test_generation_is_idempotent_for_a_single_day(): void
    {
        TaskTemplate::factory()->weekly([2])->create();

        $this->assertSame(1, $this->generate('2026-10-13', '2026-10-13'));
        $this->assertSame(0, $this->generate('2026-10-13', '2026-10-13'));
    }

    public function test_every_n_weeks_counts_from_valid_from(): void
    {
        TaskTemplate::factory()->weekly([2], 4)->create([
            'valid_from' => '2026-10-06', 'name' => 'Glasbewassing',
        ]);

        $this->generate('2026-10-01', '2026-12-31');

        $dates = Shift::orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
        $this->assertSame(['2026-10-06', '2026-11-03', '2026-12-01', '2026-12-29'], $dates);
    }

    public function test_monthly_nth_and_last_weekday(): void
    {
        TaskTemplate::factory()->monthlyNth(1, 1)->create();
        TaskTemplate::factory()->monthlyNth(-1, 5)->create();

        $this->generate('2026-10-01', '2026-11-30');

        $dates = Shift::orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
        $this->assertSame(['2026-10-05', '2026-10-30', '2026-11-02', '2026-11-27'], $dates);
    }

    public function test_monthly_day_is_clamped_to_end_of_month(): void
    {
        TaskTemplate::factory()->monthlyDay(31)->create();

        $this->generate('2026-10-01', '2026-11-30');

        $dates = Shift::orderBy('date')->get()->map(fn ($s) => $s->date->toDateString())->all();
        $this->assertSame(['2026-10-31', '2026-11-30'], $dates);
    }

    public function test_skips_holidays_when_configured(): void
    {
        TaskTemplate::factory()->weekly([5])->create(['skip_holidays' => true]);
        TaskTemplate::factory()->weekly([5])->create(['skip_holidays' => false]);

        $this->generate('2026-12-25', '2026-12-25');

        $this->assertSame(1, Shift::count());
    }

    public function test_respects_validity_period(): void
    {
        TaskTemplate::factory()->weekly([1])->create(['valid_from' => '2026-10-19', 'valid_until' => '2026-10-26']);

        $this->generate('2026-10-01', '2026-11-30');

        $this->assertSame(2, Shift::count());
    }

    public function test_night_shift_ends_next_day(): void
    {
        TaskTemplate::factory()->weekly([1])->create(['start_time' => '22:00', 'end_time' => '01:30']);

        $this->generate('2026-10-12', '2026-10-12');

        $this->assertSame('2026-10-13 01:30', Shift::first()->ends_at->format('Y-m-d H:i'));
    }

    public function test_fills_fixed_staff_and_skills_but_skips_absent_employees(): void
    {
        $skill = Skill::factory()->create();
        $template = TaskTemplate::factory()->weekly([1, 3])->create(['required_staff' => 2]);
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
        $template = TaskTemplate::factory()->weekly([1])->create();
        app(ShiftGenerator::class)->generateAhead();
        $modified = Shift::orderBy('date')->first();
        $modified->update(['is_modified' => true, 'starts_at' => '2026-10-12 17:00']);

        $template->update(['start_time' => '19:00', 'end_time' => '21:00']);
        app(ShiftGenerator::class)->regenerateTemplate($template);

        $this->assertSame('17:00', $modified->fresh()->starts_at->format('H:i'));
        $this->assertSame('19:00', Shift::whereDate('date', '2026-10-19')->first()->starts_at->format('H:i'));
        $this->assertSame(12, Shift::count());
    }

    private function dates(): array
    {
        return Shift::orderBy('starts_at')->get()->map(fn ($s) => $s->starts_at->format('Y-m-d H:i'))->all();
    }

    public function test_alternating_weeks_with_two_patterns_and_own_times(): void
    {
        // De ene week donderdag (standaardtijd), de week erop maandag vroeg.
        TaskTemplate::factory()
            ->weekly([4], 2, ['starts_on' => '2026-11-16'])
            ->weekly([1], 2, ['starts_on' => '2026-11-23', 'start_time' => '07:00', 'end_time' => '09:00', 'position' => 1])
            ->create(['valid_from' => '2026-11-13']);

        $this->generate('2026-11-09', '2026-12-13');

        $this->assertSame([
            '2026-11-19 18:00', '2026-11-23 07:00', '2026-12-03 18:00', '2026-12-07 07:00',
        ], $this->dates());
        $this->assertSame('09:00', Shift::whereDate('date', '2026-11-23')->first()->ends_at->format('H:i'));
        $this->assertSame(0, $this->generate('2026-11-09', '2026-12-13'), 'idempotent per patroon');
    }

    public function test_overlapping_patterns_give_one_shift_per_start_time(): void
    {
        TaskTemplate::factory()
            ->weekly([1, 3])
            ->weekly([1], 1, ['position' => 1]) // zelfde dag en tijd: geen tweede dienst
            ->weekly([1], 1, ['position' => 2, 'start_time' => '06:00', 'end_time' => '07:00']) // andere tijd: wel
            ->create();

        $this->generate('2026-10-12', '2026-10-14');

        $this->assertSame(['2026-10-12 06:00', '2026-10-12 18:00', '2026-10-14 18:00'], $this->dates());
    }

    public function test_rrule_patterns(): void
    {
        TaskTemplate::factory()->rrule('FREQ=MONTHLY;BYDAY=-1FR')->create(['valid_from' => '2026-10-01', 'skip_holidays' => false]);
        TaskTemplate::factory()->rrule('FREQ=WEEKLY;INTERVAL=2;BYDAY=TH', ['starts_on' => '2026-11-16'])->create(['valid_from' => '2026-11-01', 'start_time' => '07:00']);
        TaskTemplate::factory()->rrule('FREQ=WEEKLY;BYDAY=TU;COUNT=3', ['starts_on' => '2026-10-01'])->create(['valid_from' => '2026-10-01', 'start_time' => '12:00']);

        $this->generate('2026-10-01', '2026-12-10');

        $this->assertSame([
            '2026-10-06 12:00', '2026-10-13 12:00', '2026-10-20 12:00', // COUNT=3, daarna stoppen
            '2026-10-30 18:00', '2026-11-19 07:00', '2026-11-27 18:00', '2026-12-03 07:00',
        ], $this->dates());
    }

    public function test_rrule_patterns_skip_holidays_too(): void
    {
        TaskTemplate::factory()->rrule('FREQ=YEARLY;BYMONTH=12;BYMONTHDAY=25,28')->create(['valid_from' => '2026-01-01']);

        $this->generate('2026-12-01', '2026-12-31');

        $this->assertSame(['2026-12-28 18:00'], $this->dates());
    }

    public function test_shifts_from_before_patterns_are_not_duplicated(): void
    {
        $template = TaskTemplate::factory()->weekly([1])->create();
        Shift::factory()->at('2026-10-12', '18:00', '20:00')->create([
            'building_id' => $template->building_id, 'task_template_id' => $template->id, 'task_template_pattern_id' => null,
        ]);

        $this->assertSame(0, $this->generate('2026-10-12', '2026-10-12'));
    }
}

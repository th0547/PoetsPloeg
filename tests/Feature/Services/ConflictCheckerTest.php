<?php

namespace Tests\Feature\Services;

use App\Models\Absence;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Services\ConflictChecker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConflictCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function types(Shift $shift, Employee $employee): array
    {
        return collect(app(ConflictChecker::class)->forCandidate($shift, $employee->id)['warnings'])->pluck('type')->sort()->values()->all();
    }

    private function knownEmployee(Building $building, array $attributes = []): Employee
    {
        $employee = Employee::factory()->create($attributes);
        $employee->buildings()->attach($building);

        return $employee;
    }

    public function test_no_warnings_for_a_good_match(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $employee = $this->knownEmployee($shift->building);
        $employee->availabilityPatterns()->create(['weekday' => 1, 'start_time' => '17:00', 'end_time' => '22:00']);

        $this->assertSame([], $this->types($shift, $employee));
    }

    public function test_detects_overlap_absence_and_unknown_building(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $other = Shift::factory()->at('2026-10-12', '19:00', '21:00')->create();
        $employee = Employee::factory()->create();
        $other->assignments()->create(['employee_id' => $employee->id]);
        Absence::factory()->create(['employee_id' => $employee->id]);

        $this->assertSame(['absent', 'building', 'overlap'], $this->types($shift, $employee));
    }

    public function test_cancelled_shifts_do_not_count_as_overlap(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $other = Shift::factory()->at('2026-10-12', '19:00', '21:00')->create(['cancelled_at' => now()]);
        $employee = $this->knownEmployee($shift->building);
        $other->assignments()->create(['employee_id' => $employee->id]);

        $this->assertSame([], $this->types($shift, $employee));
    }

    public function test_detects_availability_and_missing_skill(): void
    {
        $skill = Skill::factory()->create(['name' => 'Glasbewassing']);
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $shift->skills()->attach($skill);
        $employee = $this->knownEmployee($shift->building);
        $employee->availabilityPatterns()->create(['weekday' => 1, 'start_time' => '08:00', 'end_time' => '19:00']);

        $result = app(ConflictChecker::class)->forCandidate($shift, $employee->id);

        $this->assertSame(['availability', 'skill'], collect($result['warnings'])->pluck('type')->sort()->values()->all());
        $this->assertStringContainsString('Glasbewassing', collect($result['warnings'])->firstWhere('type', 'skill')['message']);
    }

    public function test_availability_until_midnight(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '21:00', '23:30')->create();
        $employee = $this->knownEmployee($shift->building);
        $employee->availabilityPatterns()->create(['weekday' => 1, 'start_time' => '18:00', 'end_time' => '00:00']);

        $this->assertSame([], $this->types($shift, $employee));
    }

    public function test_cannot_window_blocks_even_inside_can_window(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '14:00', '16:00')->create();
        $employee = $this->knownEmployee($shift->building);
        $employee->availabilityPatterns()->create(['weekday' => 1, 'type' => 'available', 'start_time' => '06:00', 'end_time' => '22:00']);
        $employee->availabilityPatterns()->create(['weekday' => 1, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);

        $result = app(ConflictChecker::class)->forCandidate($shift, $employee->id);

        $this->assertSame('blocked', $result['checks']['availability']);
        $this->assertSame('Kan niet: School (08:00–15:00)', $result['warnings'][0]['message']);
    }

    public function test_only_cannot_windows_means_available_otherwise(): void
    {
        $evening = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $employee = $this->knownEmployee($evening->building);
        $employee->availabilityPatterns()->create(['weekday' => 1, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00']);

        $this->assertSame([], $this->types($evening, $employee));

        $morning = Shift::factory()->at('2026-10-12', '07:00', '09:00')->create(['building_id' => $evening->building_id]);
        $this->assertSame(['availability'], $this->types($morning, $employee));
    }

    public function test_night_shift_checks_cannot_window_on_next_day(): void
    {
        $shift = Shift::factory()->create([
            'date' => '2026-10-12', 'starts_at' => '2026-10-12 22:00:00', 'ends_at' => '2026-10-13 02:00:00',
        ]);
        $employee = $this->knownEmployee($shift->building);
        $employee->availabilityPatterns()->create(['weekday' => 2, 'type' => 'unavailable', 'start_time' => '00:00', 'end_time' => '06:00']);

        $this->assertSame(['availability'], $this->types($shift, $employee));
    }

    public function test_cannot_every_other_week_only_blocks_in_its_weeks(): void
    {
        $building = Building::factory()->create();
        $employee = $this->knownEmployee($building);
        $employee->availabilityPatterns()->create([
            'weekday' => 1, 'type' => 'unavailable', 'repeat_unit' => 'week', 'repeat_every' => 2,
            'starts_on' => '2026-10-12', 'start_time' => '08:00', 'end_time' => '15:00',
        ]);

        $blocked = Shift::factory()->at('2026-10-26', '09:00', '11:00')->create(['building_id' => $building->id]);
        $free = Shift::factory()->at('2026-10-19', '09:00', '11:00')->create(['building_id' => $building->id]);

        $this->assertSame(['availability'], $this->types($blocked, $employee));
        $this->assertSame([], $this->types($free, $employee));
    }

    public function test_warns_when_contract_hours_are_exceeded(): void
    {
        $building = Building::factory()->create();
        $employee = $this->knownEmployee($building, ['contract_hours_max' => 4]);
        $earlier = Shift::factory()->at('2026-10-13', '08:00', '11:00')->create(['building_id' => $building->id]);
        $earlier->assignments()->create(['employee_id' => $employee->id]);
        // Andere week telt niet mee.
        $nextWeek = Shift::factory()->at('2026-10-19', '08:00', '16:00')->create(['building_id' => $building->id]);
        $nextWeek->assignments()->create(['employee_id' => $employee->id]);

        $shift = Shift::factory()->at('2026-10-15', '18:00', '20:00')->create(['building_id' => $building->id]);

        $result = app(ConflictChecker::class)->forCandidate($shift, $employee->id);
        $this->assertSame(['hours'], collect($result['warnings'])->pluck('type')->all());
        $this->assertSame(3.0, $result['checks']['week_hours']);
        $this->assertSame(5.0, $result['checks']['new_week_hours']);
    }

    public function test_for_shifts_checks_existing_assignments(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $other = Shift::factory()->at('2026-10-12', '19:00', '21:00')->create(['building_id' => $shift->building_id]);
        $employee = $this->knownEmployee($shift->building);
        $shift->assignments()->create(['employee_id' => $employee->id]);
        $other->assignments()->create(['employee_id' => $employee->id]);

        $result = app(ConflictChecker::class)->forShifts(Shift::with(['assignments', 'skills'])->get());

        $this->assertSame('overlap', $result[$shift->id][$employee->id][0]['type']);
        $this->assertSame('overlap', $result[$other->id][$employee->id][0]['type']);
    }

    /** Rustmeldingen voor deze medewerker op deze dienst: [niveau, tekst]. */
    private function rest(Shift $shift, Employee $employee): array
    {
        return collect(app(ConflictChecker::class)->forCandidate($shift, $employee->id)['warnings'])
            ->where('type', 'rest')->map(fn ($w) => [$w['level'], $w['message']])->values()->all();
    }

    private function work(Employee $employee, string $date, string $start, string $end): Shift
    {
        $shift = Shift::factory()->at($date, $start, $end)->create();
        if ($end <= $start) {
            $shift->update(['ends_at' => CarbonImmutable::parse("$date $end")->addDay()]);
        }
        $shift->assignments()->create(['employee_id' => $employee->id]);

        return $shift;
    }

    public function test_split_shift_on_one_day_is_allowed(): void
    {
        $employee = Employee::factory()->create();
        $this->work($employee, '2026-11-16', '06:00', '08:00');
        $evening = Shift::factory()->at('2026-11-16', '17:00', '19:00')->create();
        $this->work($employee, '2026-11-17', '06:00', '08:00');

        // 19:00 tot 06:00 is 11 uur aaneengesloten rust binnen de 24 uur vanaf 06:00.
        $this->assertSame([], $this->rest($evening, $employee));
    }

    public function test_short_night_between_late_and_early_shift_gives_a_warning(): void
    {
        $employee = Employee::factory()->create();
        $this->work($employee, '2026-11-16', '17:00', '23:00');
        // Binnen 24 uur vanaf ma 17:00: rust 23:00–06:00 (7 u) en 14:00–17:00 (3 u).
        $early = Shift::factory()->at('2026-11-17', '06:00', '14:00')->create();

        [[$level, $message]] = $this->rest($early, $employee);
        $this->assertSame('warning', $level);
        $this->assertSame('Te weinig rust: 7 u aaneengesloten rust binnen 24 uur vanaf 16-11 17:00; minimaal 11 u', $message);

        // Telt de wet de rust ná de vroege dienst mee (08:00–17:00 = 9 u), dan is het een ingekorte rust.
        $short = Shift::factory()->at('2026-11-17', '06:00', '08:00')->create();
        $this->assertSame('info', $this->rest($short, $employee)[0][0]);
    }

    public function test_rest_may_be_shortened_to_eight_hours_once_per_seven_days(): void
    {
        $employee = Employee::factory()->create();
        // Ma op di: 22:00 tot 06:00 is 8 uur rust.
        $this->work($employee, '2026-11-16', '14:00', '22:00');
        $tuesday = Shift::factory()->at('2026-11-17', '06:00', '08:00')->create();
        $this->assertSame('info', $this->rest($tuesday, $employee)[0][0]);

        // Een tweede keer in dezelfde 7 dagen (wo op do) is een waarschuwing.
        $tuesday->assignments()->create(['employee_id' => $employee->id]);
        $this->work($employee, '2026-11-18', '14:00', '22:00');
        $thursday = Shift::factory()->at('2026-11-19', '06:00', '08:00')->create();
        $this->assertSame('warning', $this->rest($thursday, $employee)[0][0]);

        // Minder dan 8 uur is altijd een waarschuwing.
        $other = Employee::factory()->create();
        $this->work($other, '2026-11-16', '17:00', '23:30');
        $long = Shift::factory()->at('2026-11-17', '06:00', '14:00')->create();
        $this->assertSame('warning', $this->rest($long, $other)[0][0]);
    }

    public function test_early_morning_shift_is_not_a_night_shift(): void
    {
        $employee = Employee::factory()->create();
        $this->work($employee, '2026-11-18', '07:30', '08:00');
        $next = Shift::factory()->at('2026-11-18', '08:30', '10:30')->create();
        $this->work($employee, '2026-11-17', '05:30', '08:00');

        $this->assertSame([], $this->rest($next, $employee));
        $this->assertFalse(ConflictChecker::isNightShift(new \DateTime('2026-11-18 07:30'), new \DateTime('2026-11-18 08:00')));
        $this->assertFalse(ConflictChecker::isNightShift(new \DateTime('2026-11-18 05:30'), new \DateTime('2026-11-18 08:00')));
        $this->assertFalse(ConflictChecker::isNightShift(new \DateTime('2026-11-18 00:00'), new \DateTime('2026-11-18 01:30')));
        $this->assertTrue(ConflictChecker::isNightShift(new \DateTime('2026-11-17 22:00'), new \DateTime('2026-11-18 04:00')));
        $this->assertTrue(ConflictChecker::isNightShift(new \DateTime('2026-11-18 04:00'), new \DateTime('2026-11-18 08:00')));
    }

    public function test_fourteen_hours_rest_after_a_night_shift(): void
    {
        $employee = Employee::factory()->create();
        $this->work($employee, '2026-11-16', '22:00', '04:00');
        $afternoon = Shift::factory()->at('2026-11-17', '16:00', '18:00')->create();
        $evening = Shift::factory()->at('2026-11-17', '19:00', '21:00')->create();

        $messages = array_column($this->rest($afternoon, $employee), 1);
        $this->assertContains('Rusttijd 12 u na nachtdienst Schoonmaak (17-11 04:00); minimaal 14 u', $messages);
        $this->assertStringNotContainsString('nachtdienst', implode(' ', array_column($this->rest($evening, $employee), 1)));
    }
}

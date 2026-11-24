<?php

namespace Tests\Feature\Services;

use App\Models\Absence;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Services\ConflictChecker;
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

    /** @return list<array{level: string, message: string}> */
    private function restWarnings(Shift $shift, Employee $employee): array
    {
        return collect(app(ConflictChecker::class)->forCandidate($shift, $employee->id)['warnings'])
            ->where('type', 'rest')->map(fn ($w) => ['level' => $w['level'], 'message' => $w['message']])->values()->all();
    }

    private function assign(Employee $employee, string $start, string $end): Shift
    {
        $shift = Shift::factory()->create(['date' => substr($start, 0, 10), 'starts_at' => "$start:00", 'ends_at' => "$end:00"]);
        $shift->assignments()->create(['employee_id' => $employee->id]);

        return $shift;
    }

    public function test_split_shift_on_one_day_is_not_a_rest_violation(): void
    {
        // Issue #67: 06:00–08:00 en 17:00–19:00, de volgende dag weer om 06:00. Van 19:00 tot 06:00 is 11 uur rust.
        $employee = Employee::factory()->create();
        $this->assign($employee, '2026-11-17 06:00', '2026-11-17 08:00');
        $this->assign($employee, '2026-11-18 06:00', '2026-11-18 08:00');
        $evening = Shift::factory()->at('2026-11-17', '17:00', '19:00')->create();

        $this->assertSame([], $this->restWarnings($evening, $employee));
    }

    public function test_consecutive_shifts_on_one_day_are_fine(): void
    {
        // Zoals in het rooster: 07:45–08:45, 08:45–14:45 en 15:00–17:00 op één dag.
        $employee = Employee::factory()->create();
        $this->assign($employee, '2026-11-24 07:45', '2026-11-24 08:45');
        $this->assign($employee, '2026-11-24 15:00', '2026-11-24 17:00');
        $this->assign($employee, '2026-11-25 07:45', '2026-11-25 08:45');
        $middle = Shift::factory()->at('2026-11-24', '08:45', '14:45')->create();

        $this->assertSame([], $this->restWarnings($middle, $employee));
    }

    public function test_warns_when_there_is_no_long_enough_rest_within_24_hours(): void
    {
        // 17:00–23:00 en de volgende ochtend 06:00–10:00: tussen 17:00 en 17:00 is de langste rust 7 uur.
        $employee = Employee::factory()->create();
        $this->assign($employee, '2026-11-16 17:00', '2026-11-16 23:00');
        $morning = Shift::factory()->at('2026-11-17', '06:00', '10:00')->create();

        $this->assertSame([[
            'level' => ConflictChecker::WARNING,
            'message' => 'Minder dan 11 u aaneengesloten rust in de 24 u vanaf 16-11 17:00; langste rust 7 u (16-11 23:00–17-11 06:00)',
        ]], $this->restWarnings($morning, $employee));
    }

    public function test_shortened_rest_is_allowed_once_per_seven_days(): void
    {
        $employee = Employee::factory()->create();
        // Ma 17:00–22:00, di 07:00–12:00: langste rust in de 24 uur vanaf ma 17:00 is 9 uur (22:00–07:00).
        $this->assign($employee, '2026-11-16 17:00', '2026-11-16 22:00');
        $tuesday = Shift::factory()->at('2026-11-17', '07:00', '12:00')->create();

        $this->assertSame([[
            'level' => ConflictChecker::INFO,
            'message' => 'Ingekorte rust 9 u in de 24 u vanaf 16-11 17:00 (16-11 22:00–17-11 07:00); mag één keer per 7 dagen',
        ]], $this->restWarnings($tuesday, $employee));

        // Een tweede keer in dezelfde 7 dagen (do 17:00–22:00, vr 07:00–12:00) is een waarschuwing.
        $tuesday->assignments()->create(['employee_id' => $employee->id]);
        $this->assign($employee, '2026-11-19 17:00', '2026-11-19 22:00');
        $friday = Shift::factory()->at('2026-11-20', '07:00', '12:00')->create();

        $this->assertSame([[
            'level' => ConflictChecker::WARNING,
            'message' => 'Ingekorte rust 9 u in de 24 u vanaf 19-11 17:00 (19-11 22:00–20-11 07:00); ook op 16-11, mag één keer per 7 dagen (minimaal 11 u)',
        ]], $this->restWarnings($friday, $employee));

        // Meer dan 7 dagen later mag het weer.
        $later = Shift::factory()->at('2026-11-24', '07:00', '12:00')->create();
        $this->assign($employee, '2026-11-23 17:00', '2026-11-23 22:00');
        $this->assertSame([ConflictChecker::INFO], array_column($this->restWarnings($later, $employee), 'level'));
    }

    public function test_rest_after_a_night_shift_is_14_hours(): void
    {
        $employee = Employee::factory()->create();
        $this->assign($employee, '2026-11-16 22:00', '2026-11-17 04:00');
        $next = Shift::factory()->at('2026-11-17', '12:00', '14:00')->create();

        $this->assertSame([[
            'level' => ConflictChecker::WARNING,
            'message' => 'Rusttijd 8 u na nachtdienst Schoonmaak (17-11 04:00); minimaal 14 u',
        ]], $this->restWarnings($next, $employee));
        $this->assertSame([], $this->restWarnings(Shift::factory()->at('2026-11-17', '18:00', '20:00')->create(), $employee));
    }

    public function test_early_morning_shifts_are_not_night_shifts(): void
    {
        // Issue #68: een nachtdienst heeft meer dan een uur arbeid tussen 00:00 en 06:00 en eindigt na 02:00.
        $employee = Employee::factory()->create();
        $this->assign($employee, '2026-11-18 07:30', '2026-11-18 08:00');
        $this->assertSame([], $this->restWarnings(Shift::factory()->at('2026-11-18', '08:30', '10:30')->create(), $employee));

        $other = Employee::factory()->create();
        $this->assign($other, '2026-11-18 05:30', '2026-11-18 08:00');
        $this->assertSame([], $this->restWarnings(Shift::factory()->at('2026-11-18', '10:00', '12:00')->create(), $other));

        $third = Employee::factory()->create();
        $this->assign($third, '2026-11-18 01:00', '2026-11-18 02:00');
        $this->assertSame([], $this->restWarnings(Shift::factory()->at('2026-11-18', '08:00', '12:00')->create(), $third));
    }

    public function test_existing_assignments_get_the_same_rest_warnings(): void
    {
        $employee = Employee::factory()->create();
        $split = [$this->assign($employee, '2026-11-17 06:00', '2026-11-17 08:00'), $this->assign($employee, '2026-11-17 17:00', '2026-11-17 19:00')];
        $evening = $this->assign($employee, '2026-11-19 17:00', '2026-11-19 23:00');
        $morning = $this->assign($employee, '2026-11-20 06:00', '2026-11-20 10:00');

        $result = app(ConflictChecker::class)->forShifts(Shift::with(['assignments', 'skills'])->get());
        $rest = fn (Shift $s) => collect($result[$s->id][$employee->id])->where('type', 'rest')->pluck('level')->all();

        $this->assertSame([], $rest($split[0]));
        $this->assertSame([], $rest($split[1]));
        $this->assertSame([ConflictChecker::WARNING], $rest($evening));
        $this->assertSame([ConflictChecker::WARNING], $rest($morning));
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
}

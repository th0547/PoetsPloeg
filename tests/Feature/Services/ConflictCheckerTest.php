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
}

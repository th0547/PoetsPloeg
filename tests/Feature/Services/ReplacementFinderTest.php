<?php

namespace Tests\Feature\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Services\ReplacementFinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplacementFinderTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_candidates_and_excludes_unavailable(): void
    {
        $skill = Skill::factory()->create();
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create(['required_staff' => 2]);
        $shift->skills()->attach($skill);

        $assigned = Employee::factory()->create(['name' => 'Al ingepland']);
        $shift->assignments()->create(['employee_id' => $assigned->id]);

        $best = Employee::factory()->create(['name' => 'Beste']);
        $best->skills()->attach($skill);
        $best->buildings()->attach($shift->building);
        $best->availabilityPatterns()->create(['weekday' => 1, 'start_time' => '17:00', 'end_time' => '22:00']);

        $skillOnly = Employee::factory()->create(['name' => 'Alleen vaardigheid']);
        $skillOnly->skills()->attach($skill);

        $nothing = Employee::factory()->create(['name' => 'Niets']);
        $nothing->availabilityPatterns()->create(['weekday' => 2, 'start_time' => '08:00', 'end_time' => '12:00']);

        $absent = Employee::factory()->create(['name' => 'Afwezig']);
        Absence::factory()->create(['employee_id' => $absent->id]);

        Employee::factory()->create(['name' => 'Inactief', 'active' => false]);

        $result = app(ReplacementFinder::class)->candidates($shift);

        $this->assertSame(['Beste', 'Alleen vaardigheid', 'Niets'], array_column($result['candidates'], 'name'));
        $this->assertSame(['Afwezig'], array_column($result['excluded'], 'name'));
        $this->assertTrue($result['candidates'][0]['reasons'][0]['ok']);
    }

    public function test_cannot_ranks_below_unknown_and_shows_reason(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '09:00', '11:00')->create();
        $student = Employee::factory()->create(['name' => 'Student']);
        $student->availabilityPatterns()->create(['weekday' => 1, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);
        Employee::factory()->create(['name' => 'Zonder patroon']);

        $result = app(ReplacementFinder::class)->candidates($shift);

        $this->assertSame(['Zonder patroon', 'Student'], array_column($result['candidates'], 'name'));
        $this->assertContains('Kan niet: School (08:00–15:00)', array_column($result['candidates'][1]['reasons'], 'text'));
    }

    public function test_prefers_employee_with_fewer_hours_on_equal_score(): void
    {
        $shift = Shift::factory()->at('2026-10-14', '18:00', '20:00')->create();
        $busy = Employee::factory()->create(['name' => 'Druk']);
        $free = Employee::factory()->create(['name' => 'Vrij']);
        $other = Shift::factory()->at('2026-10-12', '08:00', '12:00')->create();
        $other->assignments()->create(['employee_id' => $busy->id]);

        $result = app(ReplacementFinder::class)->candidates($shift);

        $this->assertSame(['Vrij', 'Druk'], array_column($result['candidates'], 'name'));
    }
}

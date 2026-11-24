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

    public function test_split_shift_does_not_make_a_candidate_cautionary(): void
    {
        // Issue #67: 06:00–08:00 al ingepland; 17:00–19:00 erbij is een gebroken dienst en mag.
        $shift = Shift::factory()->at('2026-11-17', '17:00', '19:00')->create();
        $employee = Employee::factory()->create(['name' => 'Gebroken']);
        $morning = Shift::factory()->at('2026-11-17', '06:00', '08:00')->create();
        $morning->assignments()->create(['employee_id' => $employee->id]);

        $result = app(ReplacementFinder::class)->candidates($shift);

        $this->assertSame('best', $result['candidates'][0]['fit']);
        $this->assertSame([], array_values(array_filter($result['candidates'][0]['warnings'], fn ($w) => $w['type'] === 'rest')));
    }

    public function test_caution_always_shows_its_reason(): void
    {
        // Issue #69: alleen een rustwaarschuwing (17:00–23:00 en de volgende ochtend om 06:00) geeft "Let op",
        // en die reden staat er dan ook bij.
        $shift = Shift::factory()->at('2026-11-17', '06:00', '10:00')->create();
        $employee = Employee::factory()->create(['name' => 'Laat']);
        $employee->buildings()->attach($shift->building);
        $evening = Shift::factory()->at('2026-11-16', '17:00', '23:00')->create();
        $evening->assignments()->create(['employee_id' => $employee->id]);

        $candidate = app(ReplacementFinder::class)->candidates($shift)['candidates'][0];

        $this->assertSame('caution', $candidate['fit']);
        $rest = collect($candidate['reasons'])->first(fn ($r) => str_contains($r['text'], 'aaneengesloten rust'));
        $this->assertNotNull($rest);
        $this->assertFalse($rest['ok']);
        $this->assertArrayNotHasKey('soft', $rest);
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

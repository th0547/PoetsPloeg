<?php

namespace Tests\Feature\Services;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 10:00');
    }

    public function test_generator_does_not_assign_fixed_staff_outside_availability(): void
    {
        $template = TaskTemplate::factory()->create(['weekdays' => [1, 3], 'start_time' => '09:00', 'end_time' => '11:00', 'valid_from' => '2026-10-01']);
        $student = Employee::factory()->create();
        $student->availabilityPatterns()->create(['weekday' => 1, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);
        $template->employees()->attach($student);

        app(ShiftGenerator::class)->generate(CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-21'));

        $this->assertSame(0, Shift::whereDate('date', '2026-10-19')->first()->assignments()->count());
        $this->assertSame(1, Shift::whereDate('date', '2026-10-21')->first()->assignments()->count());
    }

    public function test_saving_availability_releases_future_shifts_outside_it(): void
    {
        $this->actingAs(User::factory()->create());
        $building = Building::factory()->create();
        $thomas = Employee::factory()->create(['name' => 'Thomas']);
        $outside = Shift::factory()->at('2026-10-14', '09:00', '11:00')->create(['building_id' => $building->id]);
        $inside = Shift::factory()->at('2026-10-14', '18:00', '20:00')->create(['building_id' => $building->id]);
        $past = Shift::factory()->at('2026-10-12', '09:00', '11:00')->create(['building_id' => $building->id]);
        foreach ([$outside, $inside, $past] as $shift) {
            $shift->assignments()->create(['employee_id' => $thomas->id, 'source' => 'manual']);
        }

        $this->put(route('employees.update', $thomas), [
            'name' => 'Thomas', 'color' => '#123456', 'active' => '1',
            'patterns' => [['weekday' => 3, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '17:00', 'note' => 'Geen zin']],
        ])->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'van 1 dienst gehaald'));

        $this->assertSame(0, $outside->assignments()->count());
        $this->assertSame(1, $inside->assignments()->count());
        $this->assertSame(1, $past->assignments()->count());

        $this->get(route('open-shifts'))->assertSee('09:00–11:00');
    }

    public function test_cleanup_command_releases_existing_conflicts(): void
    {
        $employee = Employee::factory()->create();
        $employee->availabilityPatterns()->create(['weekday' => 3, 'type' => 'available', 'start_time' => '17:00', 'end_time' => '22:00']);
        $shift = Shift::factory()->at('2026-10-14', '09:00', '11:00')->create();
        $shift->assignments()->create(['employee_id' => $employee->id]);

        $this->artisan('poetsploeg:release-unavailable')->expectsOutputToContain('1 toewijzing(en) verwijderd')->assertSuccessful();
        $this->assertSame(0, $shift->assignments()->count());
    }
}

<?php

namespace Tests\Feature\Http;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 10:00'); // dinsdag
        $this->actingAs(User::factory()->create());
    }

    public function test_week_grid_shows_tasks_per_workday(): void
    {
        $building = Building::factory()->create();
        $template = TaskTemplate::factory()->weekly([1, 2, 3, 4, 5])->create(['building_id' => $building->id, 'name' => 'Toilettenronde', 'start_time' => '08:00', 'end_time' => '09:30', 'valid_from' => '2026-10-01']);
        $anna = Employee::factory()->create(['name' => 'Anna Jansen']);
        $template->employees()->attach($anna);

        $response = $this->get(route('buildings.show', $building))->assertOk();

        $response->assertSee('Weekrooster · week 42')
            ->assertSee('Toilettenronde')
            ->assertSee('08:00–09:30')
            ->assertSee('ma. 12 okt.')
            ->assertSee('vr. 16 okt.')
            ->assertDontSee('za. 17 okt.')
            ->assertSee('Anna Jansen');
        $this->assertSame(5, Shift::count());

        $this->get(route('buildings.show', [$building, 'week' => '2026-10-20']))
            ->assertOk()->assertSee('week 43')->assertSee('ma. 19 okt.');
    }

    public function test_one_off_shifts_get_their_own_row_and_weekend_shows_when_needed(): void
    {
        $building = Building::factory()->create();
        Shift::factory()->at('2026-10-17', '09:00', '13:00')->create(['building_id' => $building->id, 'title' => 'Oplevering']);

        $this->get(route('buildings.show', $building))
            ->assertOk()->assertSee('Losse opdrachten')->assertSee('Oplevering')->assertSee('za. 17 okt.')->assertSee('1 open');
    }

    public function test_staffing_table_updates_fixed_staff_and_regenerates_shifts(): void
    {
        $building = Building::factory()->create();
        $a = TaskTemplate::factory()->weekly([1, 2, 3, 4, 5])->create(['building_id' => $building->id, 'valid_from' => '2026-10-01']);
        $b = TaskTemplate::factory()->weekly([1])->create(['building_id' => $building->id, 'valid_from' => '2026-10-01', 'start_time' => '20:00', 'end_time' => '21:00']);
        [$e1, $e2, $e3] = Employee::factory()->count(3)->create();

        $this->get(route('buildings.show', $building))->assertOk()->assertSee('Vaste bezetting');

        $this->put(route('buildings.staffing', $building), [
            'staff' => [$a->id => 3, $b->id => 1],
            'fixed' => [$a->id => [$e1->id, $e2->id, $e3->id], $b->id => [$e2->id]],
        ])->assertRedirect(route('buildings.show', $building))->assertSessionHasNoErrors();

        $this->assertSame(3, $a->fresh()->required_staff);
        $this->assertEqualsCanonicalizing([$e1->id, $e2->id, $e3->id], $a->employees()->pluck('employees.id')->all());
        $this->assertEqualsCanonicalizing([$e1->id, $e2->id, $e3->id], $building->employees()->pluck('employees.id')->all());

        $wednesday = Shift::where('task_template_id', $a->id)->whereDate('date', '2026-10-14')->first();
        $this->assertEqualsCanonicalizing([$e1->id, $e2->id, $e3->id], $wednesday->assignments()->pluck('employee_id')->all());
        $monday = Shift::where('task_template_id', $b->id)->whereDate('date', '2026-10-19')->first();
        $this->assertSame([$e2->id], $monday->assignments()->pluck('employee_id')->all());
    }

    public function test_staffing_keeps_manually_modified_shifts(): void
    {
        $building = Building::factory()->create();
        $template = TaskTemplate::factory()->weekly([3])->create(['building_id' => $building->id, 'valid_from' => '2026-10-01']);
        $employee = Employee::factory()->create();
        $this->get(route('buildings.show', $building));
        $shift = Shift::where('task_template_id', $template->id)->whereDate('date', '2026-10-14')->first();
        $shift->update(['is_modified' => true]);

        $this->put(route('buildings.staffing', $building), ['fixed' => [$template->id => [$employee->id]]]);

        $this->assertSame(0, $shift->assignments()->count());
        $this->assertSame(1, Shift::where('task_template_id', $template->id)->whereDate('date', '2026-10-21')->first()->assignments()->count());
    }

    public function test_staffing_rejects_template_of_other_building(): void
    {
        $building = Building::factory()->create();
        $other = TaskTemplate::factory()->create();
        $employee = Employee::factory()->create();

        $this->put(route('buildings.staffing', $building), ['fixed' => [$other->id => [$employee->id]]])->assertStatus(422);
        $this->assertSame(0, $other->employees()->count());
    }
}

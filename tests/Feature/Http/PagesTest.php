<?php

namespace Tests\Feature\Http;

use App\Models\Absence;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/api/planning?from=2026-10-12&to=2026-10-18&view=employee')->assertRedirect('/login');
    }

    public function test_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_all_pages_render(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'teamleider']));
        $template = TaskTemplate::factory()->create();
        $employee = Employee::factory()->create();
        $absence = Absence::factory()->create(['employee_id' => $employee->id]);
        Skill::factory()->create();

        foreach ([
            route('dashboard'), route('dashboard', ['datum' => '2026-10-12']), route('planning'), route('calendar'),
            route('employees.index'), route('employees.create'), route('employees.edit', $employee),
            route('buildings.index'), route('buildings.create'), route('buildings.show', $template->building),
            route('buildings.edit', $template->building),
            route('buildings.templates.create', $template->building), route('buildings.templates.edit', [$template->building, $template]),
            route('skills.index'), route('users.index'), route('users.create'),
            route('absences.index'), route('absences.create'), route('absences.show', $absence),
            route('shifts.create'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_create_employee_with_skills_buildings_and_availability(): void
    {
        $this->actingAs(User::factory()->create());
        $skill = Skill::factory()->create();
        $building = Building::factory()->create();

        $this->post(route('employees.store'), [
            'name' => 'Fatima Yilmaz',
            'color' => '#123456',
            'contract_hours_min' => 12,
            'contract_hours_max' => 20,
            'active' => '1',
            'skills' => [$skill->id],
            'buildings' => [$building->id],
            'patterns' => [['weekday' => 1, 'start_time' => '17:00', 'end_time' => '22:00']],
        ])->assertRedirect();

        $employee = Employee::firstWhere('name', 'Fatima Yilmaz');
        $this->assertTrue($employee->active);
        $this->assertSame([$skill->id], $employee->skills->pluck('id')->all());
        $this->assertSame([$building->id], $employee->buildings->pluck('id')->all());
        $this->assertSame(1, $employee->availabilityPatterns()->count());
    }

    public function test_creating_a_template_plans_shifts_with_fixed_staff(): void
    {
        $this->travelTo('2026-10-10 10:00');
        $this->actingAs(User::factory()->create());
        $building = Building::factory()->create();
        $employee = Employee::factory()->create();

        $this->post(route('buildings.templates.store', $building), [
            'name' => 'Dagelijkse schoonmaak',
            'start_time' => '18:00',
            'end_time' => '20:00',
            'required_staff' => 1,
            'recurrence' => 'weekly',
            'repeat_every' => 1,
            'weekdays' => [1, 3],
            'valid_from' => '2026-10-10',
            'skip_holidays' => '1',
            'active' => '1',
            'employees' => [$employee->id],
        ])->assertRedirect(route('buildings.show', $building))->assertSessionHasNoErrors();

        $this->assertSame(24, Shift::count());
        $this->assertSame(24, $employee->assignments()->count());
    }

    public function test_registering_absence_frees_shifts(): void
    {
        $this->travelTo('2026-10-10 10:00');
        $this->actingAs(User::factory()->create());
        $employee = Employee::factory()->create();
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $shift->assignments()->create(['employee_id' => $employee->id]);

        $response = $this->post(route('absences.store'), [
            'employee_id' => $employee->id,
            'type' => 'ziek',
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-13',
        ]);

        $absence = Absence::first();
        $response->assertRedirect(route('absences.show', $absence));
        $this->assertSame('2026-10-13 23:59', $absence->ends_at->format('Y-m-d H:i'));
        $this->assertSame(0, $shift->assignments()->count());
        $this->get(route('absences.show', $absence))->assertSee('Zoek vervanger');
    }

    public function test_one_off_shift_can_be_created(): void
    {
        $this->actingAs(User::factory()->create());
        $building = Building::factory()->create();
        $employee = Employee::factory()->create();

        $this->post(route('shifts.store'), [
            'building_id' => $building->id,
            'title' => 'Opleveringsschoonmaak',
            'date' => '2026-11-02',
            'start_time' => '09:00',
            'end_time' => '13:00',
            'required_staff' => 2,
            'employees' => [$employee->id],
        ])->assertRedirect();

        $shift = Shift::firstWhere('title', 'Opleveringsschoonmaak');
        $this->assertNull($shift->task_template_id);
        $this->assertSame(1, $shift->openSlots());
    }
}

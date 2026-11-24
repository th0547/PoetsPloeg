<?php

namespace Tests\Feature\Services;

use App\Models\Absence;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\FixedStaffCheck;
use App\Services\ShiftGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vaste kracht toewijzen: de planner hoort vooraf te zien of iemand de diensten kan draaien
 * (beschikbaarheid, afwezigheid, andere diensten, contracturen).
 */
class FixedStaffCheckTest extends TestCase
{
    use RefreshDatabase;

    private TaskTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        // Maandag 12 oktober 2026; vier weken vooruit = 12 werkdagen ma/wo/vr plus de startdag.
        $this->travelTo('2026-10-12 06:00');
        config(['poetsploeg.generate_weeks_ahead' => 4]);
        $this->template = TaskTemplate::factory()->weekly([1, 3, 5])->create(['start_time' => '09:00', 'end_time' => '11:00']);
        $this->template->load(['patterns', 'skills']);
    }

    private function check(Employee ...$employees): array
    {
        // Eén patroon: de uitkomst van het eerste (en enige) patroon.
        return app(FixedStaffCheck::class)->forTemplate($this->template, collect($employees)->pluck('id'), $this->template->id)[0];
    }

    public function test_free_employee_can_do_all_shifts(): void
    {
        $employee = Employee::factory()->create(['contract_hours_max' => 32]);
        $employee->buildings()->attach($this->template->building_id);

        $result = $this->check($employee)[$employee->id];

        $this->assertSame(FixedStaffCheck::OK, $result['status']);
        $this->assertSame(13, $result['total']);
        $this->assertSame(13, $result['possible']);
        $this->assertSame('Kan alle 13 diensten', $result['summary']);
        $this->assertSame([], $result['issues']);
        $this->assertFalse(FixedStaffCheck::needsConfirmation([$result]));
    }

    public function test_unavailable_on_some_weekdays_is_reported_with_the_reason(): void
    {
        $student = Employee::factory()->create(['name' => 'Student']);
        $student->availabilityPatterns()->create(['weekday' => 1, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);
        $student->availabilityPatterns()->create(['weekday' => 3, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);

        $result = $this->check($student)[$student->id];

        $this->assertSame(FixedStaffCheck::WARNING, $result['status']);
        $this->assertSame(4, $result['possible']); // alleen de vrijdagen
        $this->assertSame('Kan 4 van de 13 diensten', $result['summary']);
        $this->assertSame('Kan niet op ma en wo: School (08:00–15:00)', $result['issues'][0]['text']);
        $this->assertSame(['Nog niet ingewerkt op dit pand'], $result['notes']);
        $this->assertSame(['Student: Kan niet op ma en wo: School (08:00–15:00)'], FixedStaffCheck::messages([$result]));
    }

    public function test_never_available_is_blocked(): void
    {
        $evening = Employee::factory()->create();
        foreach (range(1, 5) as $day) {
            $evening->availabilityPatterns()->create(['weekday' => $day, 'start_time' => '17:00', 'end_time' => '22:00']);
        }

        $result = $this->check($evening)[$evening->id];

        $this->assertSame(FixedStaffCheck::BLOCKED, $result['status']);
        $this->assertSame('Kan geen van de 13 diensten', $result['summary']);
        $this->assertStringContainsString('buiten opgegeven beschikbaarheid', $result['issues'][0]['text']);
    }

    public function test_overlap_with_another_shift_and_absence_are_reported(): void
    {
        $busy = Employee::factory()->create();
        $other = Shift::factory()->at('2026-10-14', '10:00', '12:00')->create(['title' => 'Glasbewassing']);
        $other->assignments()->create(['employee_id' => $busy->id]);
        Absence::factory()->create(['employee_id' => $busy->id, 'type' => 'verlof', 'starts_at' => '2026-10-19 00:00', 'ends_at' => '2026-10-23 23:59']);

        $result = $this->check($busy)[$busy->id];
        $types = array_column($result['issues'], 'type');

        $this->assertSame(FixedStaffCheck::WARNING, $result['status']);
        $this->assertSame(13 - 1 - 3, $result['possible']);
        $this->assertContains('overlap', $types);
        $this->assertContains('absent', $types);
        $overlap = collect($result['issues'])->firstWhere('type', 'overlap');
        $this->assertStringContainsString('Staat op wo al ergens anders', $overlap['text']);
        $this->assertStringContainsString('Glasbewassing', $overlap['text']);
    }

    public function test_own_shifts_of_the_task_do_not_count_as_overlap(): void
    {
        $employee = Employee::factory()->create();
        $this->assignFixed($this->template, $employee);
        app(ShiftGenerator::class)->generateAhead(collect([$this->template]));
        $this->assertGreaterThan(0, $employee->assignments()->count());

        $result = $this->check($employee)[$employee->id];

        $this->assertSame(13, $result['possible']);
        $this->assertNotContains('overlap', array_column($result['issues'], 'type'));
    }

    public function test_contract_hours_and_missing_skill_give_a_warning(): void
    {
        $skill = Skill::factory()->create(['name' => 'Glasbewassing']);
        $this->template->skills()->attach($skill);
        $this->template->load('skills');
        $small = Employee::factory()->create(['contract_hours_min' => 0, 'contract_hours_max' => 4]);

        $result = $this->check($small)[$small->id];
        $texts = array_column($result['issues'], 'text');

        $this->assertSame(FixedStaffCheck::WARNING, $result['status']);
        $this->assertSame('Kan alle 13 diensten, let op', $result['summary']);
        $this->assertContains('Komt op 6 u per week (contract max 4)', $texts);
        $this->assertContains('Mist vaardigheid: Glasbewassing', $texts);
    }

    public function test_generator_leaves_the_slot_open_instead_of_double_booking(): void
    {
        $employee = Employee::factory()->create();
        $this->assignFixed($this->template, $employee);
        $clash = TaskTemplate::factory()->weekly([1])->create(['start_time' => '10:00', 'end_time' => '12:00', 'name' => 'Andere taak']);
        $this->assignFixed($clash, $employee);

        app(ShiftGenerator::class)->generate(now()->startOfDay(), now()->addDays(6));

        $monday = Shift::whereDate('date', '2026-10-12')->with('assignments')->get();
        $this->assertCount(2, $monday);
        // Eén van beide heeft de vaste kracht, de andere staat open.
        $this->assertSame(1, $monday->sum(fn (Shift $s) => $s->assignments->count()));
        $this->assertSame(1, $monday->sum(fn (Shift $s) => $s->openSlots()));
    }

    public function test_api_checks_a_task_that_is_not_saved_yet(): void
    {
        $this->actingAs(User::factory()->create());
        $building = Building::factory()->create();
        $student = Employee::factory()->create(['name' => 'Student']);
        $student->availabilityPatterns()->create(['weekday' => 2, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);
        $free = Employee::factory()->create(['name' => 'Vrij']);

        $data = $this->postJson('/api/staffing/check', [
            'building_id' => $building->id,
            'valid_from' => '2026-10-12',
            'skip_holidays' => true,
            'patterns' => [
                ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [2, 4], 'start_time' => '09:00', 'end_time' => '10:00'],
                ['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1], 'start_time' => '16:00', 'end_time' => '17:00'],
            ],
        ])->assertOk()->json();

        // Per patroon: op di/do kan de student alleen op donderdag, op maandagmiddag wel.
        $this->assertSame(8, $data['patterns'][0]['total']);
        $this->assertSame('warning', $data['patterns'][0]['employees'][$student->id]['status']);
        $this->assertSame(4, $data['patterns'][0]['employees'][$student->id]['possible']);
        $this->assertSame('ok', $data['patterns'][0]['employees'][$free->id]['status']);
        $this->assertSame(5, $data['patterns'][1]['total']);
        $this->assertSame('ok', $data['patterns'][1]['employees'][$student->id]['status']);

        // Zonder patronen: de bestaande taken van het pand (bezettingstabel), per patroon.
        $template = TaskTemplate::factory()->weekly([2])->create(['building_id' => $building->id, 'start_time' => '09:00', 'end_time' => '10:00']);
        $matrix = $this->postJson('/api/staffing/check', ['building_id' => $building->id])->assertOk()->json();
        $this->assertSame('blocked', $matrix['patterns'][$template->patterns->first()->id][$student->id]['status']);
    }

    public function test_saving_fixed_staff_with_a_problem_asks_for_confirmation(): void
    {
        $this->actingAs(User::factory()->create());
        $building = $this->template->building;
        $student = Employee::factory()->create(['name' => 'Student']);
        $student->availabilityPatterns()->create(['weekday' => 1, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);

        // Bezettingstabel op de pandpagina
        $payload = ['fixed' => [$this->template->patterns->first()->id => [$student->id]], 'staff' => [$this->template->id => 1]];
        $this->put(route('buildings.staffing', $building), $payload)
            ->assertRedirect(route('buildings.show', $building).'#bezetting')
            ->assertSessionHasErrors('staffing');
        $this->assertSame([], $this->fixedIds($this->template));

        $this->put(route('buildings.staffing', $building), $payload + ['confirm_staffing' => 1])
            ->assertRedirect(route('buildings.show', $building))
            ->assertSessionHas('status', fn ($message) => str_contains($message, 'Student staat op 8 van de 13 diensten'));
        $this->assertSame([$student->id], $this->fixedIds($this->template));
        // Op de vijf maandagen is de student niet ingepland: die plekken staan open.
        $this->assertSame(5, Shift::where('task_template_id', $this->template->id)->get()->sum(fn (Shift $s) => $s->openSlots()));

        // Taakformulier
        $form = [
            'name' => 'Nieuwe taak', 'required_staff' => 1,
            'valid_from' => '2026-10-12', 'skip_holidays' => '1', 'active' => '1',
            'patterns' => [['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1], 'start_time' => '09:00', 'end_time' => '10:00', 'employees' => [$student->id]]],
        ];
        $this->post(route('buildings.templates.store', $building), $form)->assertSessionHasErrors('staffing');
        $this->assertSame(0, TaskTemplate::where('name', 'Nieuwe taak')->count());

        $this->post(route('buildings.templates.store', $building), $form + ['confirm_staffing' => 1])
            ->assertRedirect(route('buildings.show', $building))->assertSessionHasNoErrors();
        $this->assertSame(1, TaskTemplate::where('name', 'Nieuwe taak')->count());
    }

    public function test_open_shift_explains_why_the_fixed_worker_is_missing(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = Employee::factory()->create(['name' => 'Henk']);
        $this->assignFixed($this->template, $employee);
        Absence::factory()->create(['employee_id' => $employee->id, 'type' => 'ziek', 'starts_at' => '2026-10-12 00:00', 'ends_at' => '2026-10-12 23:59']);
        app(ShiftGenerator::class)->generate(now()->startOfDay(), now()->startOfDay());
        $shift = Shift::where('task_template_id', $this->template->id)->firstOrFail();

        $missing = $this->getJson("/api/shifts/{$shift->id}")->assertOk()->json('fixed_missing');

        $this->assertSame('Henk', $missing[0]['name']);
        $this->assertStringStartsWith('Afwezig (ziek', $missing[0]['reason']);
        $this->get(route('open-shifts'))->assertOk()->assertSee('Vaste kracht')->assertSee('Henk');
    }
}

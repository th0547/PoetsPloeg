<?php

namespace Tests\Feature\Http;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\TaskTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_board_generates_shifts_and_returns_structured_data(): void
    {
        $template = TaskTemplate::factory()->weekly([1, 3, 5])->create(['required_staff' => 2, 'start_time' => '18:00', 'end_time' => '20:30']);
        $employee = Employee::factory()->create(['name' => 'Anna Jansen', 'contract_hours_max' => 20]);
        $template->employees()->attach($employee);

        $data = $this->getJson('/api/planning?from=2026-10-12&to=2026-10-18')->assertOk()->json();

        $this->assertSame('2026-10-12', $data['from']);
        $this->assertCount(7, $data['days']);
        $this->assertSame(['id' => $employee->id, 'name' => 'Anna Jansen', 'initials' => 'AJ', 'contract_max' => 20],
            collect($data['employees'][0])->only(['id', 'name', 'initials', 'contract_max'])->all());
        $this->assertSame($template->building_id, $data['buildings'][0]['id']);
        $this->assertNotEmpty($data['buildings'][0]['short']);

        $shifts = collect($data['shifts']);
        $this->assertCount(3, $shifts);
        $first = $shifts->first();
        // Dag en tijden in Nederlandse tijd, als minuten sinds middernacht.
        $this->assertSame(['2026-10-12', '18:00', '20:30', 1080, 1230], [$first['day'], $first['start'], $first['end'], $first['start_min'], $first['end_min']]);
        $this->assertSame(1, $first['open']);
        $this->assertSame($employee->id, $first['assignments'][0]['employee_id']);
    }

    public function test_board_contains_absences_and_availability_hints(): void
    {
        $employee = Employee::factory()->create();
        $employee->availabilityPatterns()->create(['weekday' => 1, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);
        Absence::factory()->create([
            'employee_id' => $employee->id, 'type' => 'ziek', 'starts_at' => '2026-10-13 00:00', 'ends_at' => '2026-10-14 23:59',
        ]);

        $data = $this->getJson('/api/planning?from=2026-10-12&to=2026-10-18')->assertOk()->json();

        $absence = $data['absences'][0];
        $this->assertSame([$employee->id, '2026-10-13', '2026-10-14', 0, 1440], [$absence['employee_id'], $absence['from_day'], $absence['to_day'], $absence['from_min'], $absence['to_min']]);
        $monday = $data['unavailable'][$employee->id]['2026-10-12'];
        $this->assertSame('kan niet 08:00–15:00 · School', $monday['hint']);
        $this->assertSame([['from' => 480, 'to' => 900, 'note' => 'School']], $monday['blocks']);

        // Bij een maand laten we de beschikbaarheid weg: te veel gegevens voor te weinig ruimte.
        $month = $this->getJson('/api/planning?from=2026-10-01&to=2026-10-31')->assertOk()->json();
        $this->assertSame([], $month['unavailable']);
    }

    public function test_old_calendar_link_redirects_to_the_planning(): void
    {
        $this->get('/kalender')->assertRedirect('/planning?view=calendar');
    }

    public function test_move_with_local_times_changes_the_shift(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();

        $this->postJson('/api/planning/move', ['shift_id' => $shift->id, 'shift_days' => 1, 'start_time' => '07:15', 'end_time' => '09:45'])
            ->assertOk()->assertJson(['ok' => true]);

        $shift->refresh();
        $this->assertSame('2026-10-13 07:15', $shift->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-13 09:45', $shift->ends_at->format('Y-m-d H:i'));
    }

    public function test_move_to_other_employee_requires_confirmation_on_conflict(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $other = Shift::factory()->at('2026-10-12', '19:00', '21:00')->create();
        [$a, $b] = Employee::factory()->count(2)->create();
        $shift->assignments()->create(['employee_id' => $a->id]);
        $other->assignments()->create(['employee_id' => $b->id]);

        $payload = [
            'shift_id' => $shift->id,
            'from_employee_id' => $a->id,
            'to_employee_id' => $b->id,
            'starts_at' => '2026-10-12T16:00:00Z', // 18:00 Amsterdam (zomertijd)
            'ends_at' => '2026-10-12T18:00:00Z',
        ];

        $this->postJson('/api/planning/move', $payload)
            ->assertOk()->assertJson(['needs_confirmation' => true])
            ->assertJsonPath('warnings.0.type', 'overlap');
        $this->assertSame([$a->id], $shift->assignments()->pluck('employee_id')->all());

        $this->postJson('/api/planning/move', $payload + ['confirm' => true])->assertOk()->assertJson(['ok' => true]);
        $this->assertSame([$b->id], $shift->assignments()->pluck('employee_id')->all());
        $this->assertSame('replacement', $shift->assignments()->first()->source);
        $this->assertTrue($shift->fresh()->is_modified);
    }

    public function test_move_changes_time(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();

        $this->postJson('/api/planning/move', [
            'shift_id' => $shift->id,
            'starts_at' => '2026-10-12T17:00:00Z',
            'ends_at' => '2026-10-12T19:00:00Z',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('19:00', $shift->fresh()->starts_at->format('H:i'));
    }

    public function test_move_by_whole_days_keeps_local_times_across_dst(): void
    {
        // 25 oktober 2026 gaat de wintertijd in.
        $shift = Shift::factory()->at('2026-10-23', '18:00', '20:00')->create();

        $this->postJson('/api/planning/move', ['shift_id' => $shift->id, 'shift_days' => 3])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame('2026-10-26 18:00', $shift->fresh()->starts_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-26 20:00', $shift->fresh()->ends_at->format('Y-m-d H:i'));
    }

    public function test_open_slot_drop_rejected_when_shift_is_full(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        [$a, $b] = Employee::factory()->count(2)->create();
        $shift->assignments()->create(['employee_id' => $a->id]);

        $this->postJson('/api/planning/move', [
            'shift_id' => $shift->id,
            'to_employee_id' => $b->id,
            'starts_at' => '2026-10-12T16:00:00Z',
            'ends_at' => '2026-10-12T18:00:00Z',
        ])->assertStatus(422);
    }

    public function test_shift_detail_candidates_assign_and_unassign(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create(['required_staff' => 2]);
        [$a, $b] = Employee::factory()->count(2)->create();
        $b->buildings()->attach($shift->building);

        $this->getJson("/api/shifts/{$shift->id}")->assertOk()->assertJsonPath('open_slots', 2);

        $candidates = $this->getJson("/api/shifts/{$shift->id}/candidates")->assertOk()->json('candidates');
        $this->assertSame($b->id, $candidates[0]['id']);

        $this->postJson("/api/shifts/{$shift->id}/assignments", ['employee_id' => $b->id, 'source' => 'replacement'])
            ->assertOk()->assertJsonPath('open_slots', 1)->assertJsonPath('assignments.0.name', $b->name);

        $this->postJson("/api/shifts/{$shift->id}/assignments", ['employee_id' => $b->id])->assertStatus(422);

        $this->deleteJson("/api/shifts/{$shift->id}/assignments/{$b->id}")->assertOk()->assertJsonPath('open_slots', 2);
    }

    public function test_update_and_cancel_shift(): void
    {
        $template = TaskTemplate::factory()->create();
        $shift = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create([
            'task_template_id' => $template->id, 'building_id' => $template->building_id,
        ]);

        $this->patchJson("/api/shifts/{$shift->id}", [
            'title' => 'Extra grondig', 'date' => '2026-10-13', 'start_time' => '17:00', 'end_time' => '19:30',
            'required_staff' => 2, 'notes' => 'Ook ramen binnen',
        ])->assertOk()->assertJsonPath('title', 'Extra grondig')->assertJsonPath('date', '2026-10-13');

        $fresh = $shift->fresh();
        $this->assertSame('2026-10-12', $fresh->date->toDateString(), 'herhalingsdatum blijft staan');
        $this->assertSame('2026-10-13 17:00', $fresh->starts_at->format('Y-m-d H:i'));

        $this->deleteJson("/api/shifts/{$shift->id}")->assertOk();
        $this->assertNotNull($shift->fresh()->cancelled_at);
    }

    public function test_full_shift_accepts_extra_staff_above_required(): void
    {
        $this->actingAs(User::factory()->create());
        $shift = Shift::factory()->at('2026-12-01', '18:00', '20:00')->create(['required_staff' => 1]);
        [$anna, $bram] = Employee::factory()->count(2)->create();
        $shift->assignments()->create(['employee_id' => $anna->id]);

        $this->getJson("/api/shifts/{$shift->id}")->assertJsonPath('open_slots', 0)->assertJsonPath('extra_staff', 0);
        $this->postJson("/api/shifts/{$shift->id}/assignments", ['employee_id' => $bram->id, 'confirm' => true])
            ->assertOk()->assertJsonPath('open_slots', 0)->assertJsonPath('extra_staff', 1);
        $this->assertSame(2, $shift->assignments()->count());
    }
}

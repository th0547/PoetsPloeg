<?php

namespace Tests\Feature\Services;

use App\Models\Absence;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PolicyVersion;
use App\Models\Shift;
use App\Models\TaskTemplate;
use App\Models\User;
use App\Services\LeaveHours;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveHoursTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 10:00'); // vrijdag
        $this->anna = Employee::factory()->create(['contract_hours_min' => 24, 'contract_hours_max' => 24]);
    }

    private function estimate(string $from, string $to): array
    {
        return app(LeaveHours::class)->estimate($this->anna, CarbonImmutable::parse($from), CarbonImmutable::parse($to));
    }

    private function employeeUser(): User
    {
        return User::factory()->create(['role' => 'medewerker', 'employee_id' => $this->anna->id]);
    }

    public function test_hours_come_from_planned_shifts_first(): void
    {
        $shift = Shift::factory()->at('2026-11-17', '08:00', '12:30')->create();
        $shift->assignments()->create(['employee_id' => $this->anna->id]);

        $whole = $this->estimate('2026-11-17 00:00', '2026-11-17 23:59');
        $this->assertSame(4.5, $whole['hours']);
        $this->assertSame('dienst', $whole['source']);
        $this->assertSame(1, $whole['days']);

        // Alleen de ochtend vrij: alleen de overlap telt.
        $this->assertSame(2.0, $this->estimate('2026-11-17 08:00', '2026-11-17 10:00')['hours']);
    }

    public function test_then_fixed_tasks_then_contract_hours(): void
    {
        $template = TaskTemplate::factory()->weekly([2])->create(['start_time' => '18:00', 'end_time' => '21:00', 'valid_from' => '2026-11-01']);
        $template->employees()->attach($this->anna->id, ['position' => 0]);

        $this->assertSame(['hours' => 3.0, 'source' => 'vaste bezetting'], array_intersect_key($this->estimate('2026-11-17 00:00', '2026-11-17 23:59'), ['hours' => 0, 'source' => 0]));

        // Woensdag: geen dienst en geen vaste taak → 24 u / 5 werkdagen.
        $this->assertSame(4.8, $this->estimate('2026-11-18 00:00', '2026-11-18 23:59')['hours']);
        // Met een weekpatroon (ma, wo, vr "kan wel") → 24 / 3 = 8 u, en dinsdag-zonder-taak telt niet.
        foreach ([1, 3, 5] as $day) {
            $this->anna->availabilityPatterns()->create(['weekday' => $day, 'type' => 'available', 'start_time' => '06:00', 'end_time' => '22:00']);
        }
        $this->anna->unsetRelation('availabilityPatterns');
        $this->assertSame(8.0, $this->estimate('2026-11-18 00:00', '2026-11-18 23:59')['hours']);
        $this->assertSame(0.0, $this->estimate('2026-11-19 00:00', '2026-11-19 23:59')['hours']);
        // Zaterdag en zondag: geen werkdag.
        $this->assertSame(0.0, $this->estimate('2026-11-21 00:00', '2026-11-22 23:59')['hours']);
    }

    public function test_hours_are_stored_when_absence_is_created_before_shifts_are_freed(): void
    {
        $shift = Shift::factory()->at('2026-11-17', '08:00', '12:00')->create();
        $shift->assignments()->create(['employee_id' => $this->anna->id]);
        $this->actingAs(User::factory()->create());

        $this->post(route('absences.store'), ['employee_id' => $this->anna->id, 'type' => 'verlof', 'days' => [['date' => '2026-11-17']]]);

        $absence = Absence::sole();
        $this->assertSame(4.0, $absence->hours);
        $this->assertSame('dienst', $absence->hours_source);
        $this->assertSame(0, $shift->assignments()->count(), 'medewerker is van de dienst gehaald');
    }

    public function test_not_available_only_on_days_without_shift(): void
    {
        $shift = Shift::factory()->at('2026-11-17', '08:00', '12:00')->create();
        $shift->assignments()->create(['employee_id' => $this->anna->id]);
        $this->actingAs($this->employeeUser());

        $this->get(route('my.absences.create'))->assertOk()->assertSee('Niet beschikbaar')->assertSee('2026-11-17');

        $this->post(route('my.absences.store'), ['type' => 'niet_beschikbaar', 'days' => [['date' => '2026-11-17']]])
            ->assertSessionHasErrors('type');
        $this->assertStringContainsString('17 nov', session('errors')->first('type'));

        $this->post(route('my.absences.store'), ['type' => 'niet_beschikbaar', 'days' => [['date' => '2026-11-18']]])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($s) => str_contains($s, 'niet beschikbaar'));
        $absence = Absence::sole();
        $this->assertSame('approved', $absence->status);
        $this->assertSame(0.0, $absence->hours);
    }

    public function test_zero_hours_suggests_not_available(): void
    {
        $this->actingAs($this->employeeUser());

        // Zaterdag: Anna werkt normaal niet.
        $this->post(route('my.absences.store'), ['type' => 'verlof', 'days' => [['date' => '2026-11-21']]])
            ->assertSessionHasErrors('confirm_zero');
        $this->assertSame(0, Absence::count());

        $this->post(route('my.absences.store'), ['type' => 'verlof', 'days' => [['date' => '2026-11-21']], 'confirm_zero' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, Absence::sole()->hours);
    }

    public function test_sickness_during_vacation_moves_the_hours(): void
    {
        $this->actingAs(User::factory()->create());
        $vacation = Absence::create(['employee_id' => $this->anna->id, 'type' => 'verlof', 'starts_at' => '2026-11-16 00:00', 'ends_at' => '2026-11-20 23:59']);
        $this->assertSame(24.0, $vacation->hours); // 5 werkdagen × 4,8

        $this->post(route('absences.store'), ['employee_id' => $this->anna->id, 'type' => 'ziek', 'days' => [['date' => '2026-11-18'], ['date' => '2026-11-19']]])
            ->assertSessionHas('status', fn ($s) => str_contains($s, '9,6 uur vakantie in deze periode telt nu als ziekte'));

        $vacation->refresh();
        $this->assertSame(14.4, $vacation->hours);
        $this->assertSame(3, $vacation->days);
    }

    public function test_planner_can_correct_the_hours(): void
    {
        $this->actingAs(User::factory()->create());
        $absence = Absence::create(['employee_id' => $this->anna->id, 'type' => 'verlof', 'starts_at' => '2026-11-18 00:00', 'ends_at' => '2026-11-18 23:59']);

        $this->get(route('absences.show', $absence))->assertOk()->assertSee('4,8 u')->assertSee('contracturen');
        $this->patch(route('absences.hours', $absence), ['hours' => '6', 'days' => '1'])->assertSessionHasNoErrors();

        $absence->refresh();
        $this->assertSame(6.0, $absence->hours);
        $this->assertSame('handmatig', $absence->hours_source);
        $this->assertSame(1, AuditLog::where('action', 'absence.hours')->count());
    }

    public function test_backfill_command_estimates_missing_hours(): void
    {
        $absence = Absence::create(['employee_id' => $this->anna->id, 'type' => 'verlof', 'starts_at' => '2026-11-18 00:00', 'ends_at' => '2026-11-18 23:59', 'hours' => 0]);
        Absence::whereKey($absence->id)->update(['hours' => null, 'hours_source' => null]);

        $this->artisan('poetsploeg:leave-hours')->assertSuccessful();

        $absence->refresh();
        $this->assertSame(4.8, $absence->hours);
        $this->assertSame('schatting', $absence->hours_source);
    }

    public function test_leave_type_rules_from_the_policy_apply(): void
    {
        PolicyVersion::create(['valid_from' => '2026-11-01', 'settings' => [], 'leave_types' => ['verlof' => ['min_days_ahead' => 14, 'note_required' => true]]]);
        Policy::forget();
        $this->actingAs($this->employeeUser());

        $this->post(route('my.absences.store'), ['type' => 'verlof', 'days' => [['date' => '2026-11-18']], 'note' => 'x'])->assertSessionHasErrors('days');
        $this->post(route('my.absences.store'), ['type' => 'verlof', 'days' => [['date' => '2026-12-01']]])->assertSessionHasErrors('note');
        $this->post(route('my.absences.store'), ['type' => 'verlof', 'days' => [['date' => '2026-12-01']], 'note' => 'Vakantie'])->assertSessionHasNoErrors();
    }
}

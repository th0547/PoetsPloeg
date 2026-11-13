<?php

namespace Tests\Feature\Http;

use App\Models\AvailabilityDay;
use App\Models\AvailabilityWeek;
use App\Models\Building;
use App\Models\Employee;
use App\Models\PolicyVersion;
use App\Models\Shift;
use App\Models\User;
use App\Services\AvailabilityCheck;
use App\Services\ConflictChecker;
use App\Services\WeekAvailability;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Beschikbaarheid per datum en weekpatroon met periode (wisselend schoolrooster). */
class AvailabilityDaysTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anna;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-16 09:00'); // maandag
        $this->anna = Employee::factory()->create(['name' => 'Anna', 'contract_hours_min' => 12]);
        $this->user = User::factory()->create(['role' => 'medewerker', 'employee_id' => $this->anna->id]);
    }

    private function pattern(array $attributes): void
    {
        $this->anna->availabilityPatterns()->create($attributes + ['type' => 'available']);
        $this->anna->unsetRelation('availabilityPatterns');
    }

    private function day(string $date, string $type, string $from, string $to, ?string $note = null): void
    {
        $this->anna->availabilityDays()->create(['date' => $date, 'type' => $type, 'start_time' => $from, 'end_time' => $to, 'note' => $note]);
        $this->anna->unsetRelation('availabilityDays');
    }

    private function check(string $date, string $from, string $to): string
    {
        return AvailabilityCheck::evaluate($this->anna->fresh(), CarbonImmutable::parse("{$date} {$from}"), CarbonImmutable::parse("{$date} {$to}"))[0];
    }

    public function test_pattern_with_period_only_counts_within_the_period(): void
    {
        // Schoolblok 2: dinsdag school 08:00–15:00, van 1 t/m 18 december.
        $this->pattern(['weekday' => 2, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'valid_from' => '2026-12-01', 'valid_until' => '2026-12-18']);

        $this->assertSame('ok', $this->check('2026-11-24', '09:00', '11:00'), 'vóór de periode');
        $this->assertSame('blocked', $this->check('2026-12-08', '09:00', '11:00'), 'in de periode');
        $this->assertSame('ok', $this->check('2026-12-22', '09:00', '11:00'), 'na de periode');
    }

    public function test_available_pattern_with_period_does_not_block_other_weeks(): void
    {
        // Alleen in december "kan wel" op maandagavond; daarbuiten geen voorkeur.
        $this->pattern(['weekday' => 1, 'start_time' => '17:00', 'end_time' => '22:00', 'valid_from' => '2026-12-01']);

        $this->assertSame('ok', $this->check('2026-11-23', '09:00', '11:00'));
        $this->assertSame('fail', $this->check('2026-12-07', '09:00', '11:00'));
        $this->assertSame('ok', $this->check('2026-12-07', '18:00', '20:00'));
    }

    public function test_a_date_replaces_the_pattern_that_day(): void
    {
        $this->pattern(['weekday' => 2, 'type' => 'unavailable', 'start_time' => '08:00', 'end_time' => '15:00', 'note' => 'School']);
        // Dinsdag 24 november geen school, alleen 09:00–13:00 kunnen.
        $this->day('2026-11-24', 'available', '09:00', '13:00');

        $this->assertSame('ok', $this->check('2026-11-24', '09:00', '12:00'));
        $this->assertSame('fail', $this->check('2026-11-24', '14:00', '16:00'), 'buiten de tijden van die dag');
        $this->assertSame('blocked', $this->check('2026-12-01', '09:00', '12:00'), 'andere dinsdag: patroon');
    }

    public function test_whole_day_not_and_reason_in_conflicts(): void
    {
        $this->day('2026-11-25', 'unavailable', '00:00', '00:00', 'Tentamen');
        $this->assertSame('blocked', $this->check('2026-11-25', '18:00', '20:00'));
        $this->assertSame('none', AvailabilityCheck::evaluate(Employee::factory()->create(), now(), now()->addHour())[0]);

        $shift = Shift::factory()->at('2026-11-25', '18:00', '20:00')->create(['building_id' => Building::factory()->create()->id]);
        $warnings = collect(app(ConflictChecker::class)->forCandidate($shift, $this->anna->id)['warnings']);
        $this->assertTrue($warnings->contains(fn ($w) => str_contains(json_encode($w), 'Tentamen (00:00')), json_encode($warnings));
    }

    public function test_night_shift_into_a_day_that_is_blocked(): void
    {
        $this->day('2026-11-27', 'unavailable', '00:00', '06:00');
        $this->assertSame('blocked', AvailabilityCheck::evaluate($this->anna->fresh(), CarbonImmutable::parse('2026-11-26 22:00'), CarbonImmutable::parse('2026-11-27 02:00'))[0]);
    }

    public function test_week_hours_use_date_rules(): void
    {
        $week = CarbonImmutable::parse('2026-12-21');
        $this->pattern(['weekday' => 1, 'start_time' => '08:00', 'end_time' => '16:00']);
        $this->pattern(['weekday' => 2, 'start_time' => '08:00', 'end_time' => '16:00']);
        $this->assertSame(16.0, app(WeekAvailability::class)->hours($this->anna, $week));

        $this->day('2026-12-22', 'available', '12:00', '14:00'); // dinsdag korter
        $this->day('2026-12-23', 'available', '09:00', '11:00'); // woensdag extra
        $this->anna->refresh();
        $this->assertSame(12.0, app(WeekAvailability::class)->hours($this->anna, $week));
    }

    private function form(array $days, array $patterns = []): array
    {
        return ['days_form' => '1', 'patterns' => $patterns, 'days' => $days];
    }

    public function test_employee_saves_dates_past_stays_future_is_replaced(): void
    {
        $this->day('2026-11-10', 'unavailable', '00:00', '00:00'); // verleden
        $this->day('2026-11-30', 'unavailable', '00:00', '00:00'); // wordt vervangen

        $this->actingAs($this->user)->put(route('my.availability.update'), $this->form([
            ['date' => '2026-11-24', 'mode' => 'slots', 'slots' => [
                ['type' => 'available', 'start_time' => '13:00', 'end_time' => '17:00', 'note' => ''],
                ['type' => 'unavailable', 'start_time' => '14:00', 'end_time' => '15:00', 'note' => 'Tandarts'],
            ]],
            ['date' => '2026-11-25', 'mode' => 'unavailable_all', 'note' => 'Tentamen'],
            ['date' => '2026-11-26', 'mode' => 'pattern'],
        ]))->assertSessionHasNoErrors()->assertRedirect(route('my.availability'));

        $dates = AvailabilityDay::orderBy('date')->orderBy('start_time')->get()->map(fn ($d) => $d->date->toDateString().' '.$d->type.' '.substr($d->start_time, 0, 5));
        $this->assertSame([
            '2026-11-10 unavailable 00:00',
            '2026-11-24 available 13:00',
            '2026-11-24 unavailable 14:00',
            '2026-11-25 unavailable 00:00',
        ], $dates->all());
        $this->assertSame('Tentamen', AvailabilityDay::whereDate('date', '2026-11-25')->value('note'));

        // Het formulier toont het weer zo.
        $rows = collect($this->anna->fresh()->availabilityDayRows())->keyBy('date');
        $this->assertSame('slots', $rows['2026-11-24']['mode']);
        $this->assertSame('unavailable_all', $rows['2026-11-25']['mode']);
        $this->assertTrue($rows['2026-11-10']['past']);
        $this->get(route('my.availability'))->assertOk()->assertSee('Afwijkende dagen');
    }

    public function test_without_days_form_dates_are_kept(): void
    {
        $this->day('2026-11-30', 'unavailable', '00:00', '00:00');
        $this->actingAs($this->user)->put(route('my.availability.update'), ['patterns' => []])->assertSessionHasNoErrors();
        $this->assertSame(1, AvailabilityDay::count());
    }

    public function test_validation(): void
    {
        $this->actingAs($this->user);
        $this->put(route('my.availability.update'), $this->form([['date' => '2026-11-10', 'mode' => 'unavailable_all']]))
            ->assertSessionHasErrors('days.0.date');
        $this->put(route('my.availability.update'), $this->form([['date' => '2028-01-10', 'mode' => 'unavailable_all']]))
            ->assertSessionHasErrors('days.0.date');
        $this->put(route('my.availability.update'), $this->form([['date' => '2026-11-24', 'mode' => 'slots', 'slots' => []]]))
            ->assertSessionHasErrors('days.0.slots');
        $slot = ['type' => 'available', 'start_time' => '09:00', 'end_time' => '10:00'];
        $this->put(route('my.availability.update'), $this->form([['date' => '2026-11-24', 'mode' => 'slots', 'slots' => array_fill(0, 7, $slot)]]))
            ->assertSessionHasErrors('days.0.slots');
        $this->put(route('my.availability.update'), $this->form([], [
            ['weekday' => 1, 'type' => 'available', 'start_time' => '09:00', 'end_time' => '10:00', 'valid_from' => '2026-12-10', 'valid_until' => '2026-12-01'],
        ]))->assertSessionHasErrors('patterns.0.valid_until');
        $this->put(route('my.availability.update'), $this->form([], [
            ['weekday' => 1, 'type' => 'available', 'start_time' => '09:00', 'end_time' => '10:00', 'valid_until' => '2026-12-01'],
        ]))->assertSessionHasNoErrors();
        $this->assertSame('2026-12-01', $this->anna->availabilityPatterns()->first()->valid_until->toDateString());
        $this->assertSame(0, AvailabilityDay::count());
    }

    public function test_confirmed_weeks_follow_the_policy(): void
    {
        $this->actingAs($this->user);
        $this->post(route('my.availability.confirm'), ['week' => '2026-12-21', 'explanation' => 'ok'])->assertSessionHasNoErrors();

        // Standaard: wijzigen mag, de planner ziet het.
        $this->put(route('my.availability.update'), $this->form([['date' => '2026-12-22', 'mode' => 'unavailable_all']]))->assertSessionHasNoErrors();
        $this->assertTrue(AvailabilityWeek::sole()->changed_after_confirm);

        // Vergrendeld: een dag in de bevestigde week niet meer zelf wijzigen; andere weken wel.
        PolicyVersion::create(['valid_from' => '2026-11-01', 'settings' => ['availability.after_confirm' => 'locked'], 'leave_types' => []]);
        Policy::forget();
        $keep = ['date' => '2026-12-22', 'mode' => 'unavailable_all'];
        $this->put(route('my.availability.update'), $this->form([$keep, ['date' => '2026-12-23', 'mode' => 'unavailable_all']]))
            ->assertSessionHasErrors('patterns');
        $this->assertSame(1, AvailabilityDay::count(), 'teruggedraaid');
        $this->put(route('my.availability.update'), $this->form([$keep, ['date' => '2027-01-05', 'mode' => 'unavailable_all']]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2, AvailabilityDay::count());
    }

    public function test_planner_edits_dates_of_an_employee(): void
    {
        $planner = User::factory()->create(['role' => 'planner']);
        $this->actingAs($planner)->put(route('employees.update', $this->anna), [
            'name' => 'Anna', 'email' => $this->user->email, 'color' => '#123456', 'active' => '1', 'days_form' => '1',
            'days' => [['date' => '2026-11-24', 'mode' => 'available_all', 'note' => 'Vrij van school']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('Vrij van school', AvailabilityDay::sole()->note);
        $this->get(route('employees.edit', $this->anna))->assertOk()->assertSee('Vrij van school');

        // Een medewerker kan dat niet bij een ander.
        $this->actingAs($this->user)->put(route('employees.update', Employee::factory()->create()), ['name' => 'X'])->assertForbidden();
    }
}

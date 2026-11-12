<?php

namespace Tests\Feature\Http;

use App\Models\Absence;
use App\Models\AvailabilityWeek;
use App\Models\Employee;
use App\Models\PolicyVersion;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ConfirmAvailability;
use App\Services\WeekAvailability;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AvailabilityConfirmTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anna;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo('2026-11-16 09:00'); // maandag 09:00 = standaard mailmoment
        $this->anna = Employee::factory()->create(['name' => 'Anna', 'contract_hours_min' => 24, 'contract_hours_max' => 24]);
        $this->user = User::factory()->create(['role' => 'medewerker', 'employee_id' => $this->anna->id]);
    }

    private function target(): CarbonImmutable
    {
        return WeekAvailability::targetWeek(); // 5 weken vooruit: maandag 21 december
    }

    private function pattern(int $weekday, string $type, string $from, string $to): void
    {
        $this->anna->availabilityPatterns()->create(['weekday' => $weekday, 'type' => $type, 'start_time' => $from, 'end_time' => $to]);
        $this->anna->unsetRelation('availabilityPatterns');
    }

    public function test_available_hours_follow_pattern_and_absence(): void
    {
        $hours = app(WeekAvailability::class);
        $this->assertSame(168.0, $hours->hours($this->anna, $this->target()), 'zonder patroon: alles beschikbaar');

        $this->pattern(1, 'available', '08:00', '16:00');
        $this->pattern(3, 'available', '08:00', '16:00');
        $this->pattern(3, 'unavailable', '12:00', '13:00');
        $this->assertSame(15.0, $hours->hours($this->anna, $this->target()));

        Absence::create(['employee_id' => $this->anna->id, 'type' => 'niet_beschikbaar', 'starts_at' => '2026-12-21 00:00', 'ends_at' => '2026-12-21 23:59']);
        $this->assertSame(7.0, $hours->hours($this->anna, $this->target()));
    }

    public function test_weekly_mail_only_until_confirmed_with_one_reminder(): void
    {
        Employee::factory()->create(); // zonder inlog: geen mail

        $this->artisan('poetsploeg:availability-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->user, ConfirmAvailability::class, 1);
        Notification::assertSentTo($this->user, ConfirmAvailability::class, fn ($n) => $n->weekStart->isSameDay($this->target()) && ! $n->reminder);

        // Zelfde uur nogmaals: niet opnieuw.
        $this->artisan('poetsploeg:availability-reminders');
        Notification::assertSentToTimes($this->user, ConfirmAvailability::class, 1);

        // Na 3 dagen: één herinnering.
        $this->travel(3)->days();
        $this->artisan('poetsploeg:availability-reminders');
        $this->artisan('poetsploeg:availability-reminders');
        Notification::assertSentToTimes($this->user, ConfirmAvailability::class, 2);
        Notification::assertSentTo($this->user, ConfirmAvailability::class, fn ($n) => $n->reminder);
    }

    public function test_no_mail_outside_the_moment_or_when_confirmed(): void
    {
        $this->travelTo('2026-11-16 10:00');
        $this->artisan('poetsploeg:availability-reminders');
        Notification::assertNothingSent();

        AvailabilityWeek::create(['employee_id' => $this->anna->id, 'week_start' => '2026-12-21', 'confirmed_at' => now()]);
        $this->artisan('poetsploeg:availability-reminders', ['--force' => true]);
        Notification::assertNothingSent();
    }

    public function test_employee_confirms_a_week_and_needs_explanation_below_contract(): void
    {
        $this->pattern(1, 'available', '08:00', '12:00'); // 4 uur per week, contract 24
        $this->actingAs($this->user);

        $this->get(route('my.availability'))->assertOk()->assertSee('Beschikbaarheid bevestigen')
            ->assertSee('Week 52')->assertSee('minder beschikbaar dan je contracturen');

        $this->post(route('my.availability.confirm'), ['week' => '2026-12-21'])->assertSessionHasErrors('explanation');
        $this->post(route('my.availability.confirm'), ['week' => '2026-12-21', 'explanation' => 'Studie, afgesproken', 'other_employer' => 'di 08–12'])
            ->assertSessionHasNoErrors();

        $week = AvailabilityWeek::sole();
        $this->assertTrue($week->isConfirmed());
        $this->assertSame(4.0, $week->available_hours);
        $this->assertSame('Studie, afgesproken', $week->explanation);

        // Weken die te ver weg liggen kun je nog niet bevestigen.
        $this->post(route('my.availability.confirm'), ['week' => '2027-01-04'])->assertSessionHasErrors('week');
    }

    public function test_changes_after_confirming_follow_the_policy(): void
    {
        $this->actingAs($this->user);
        $this->post(route('my.availability.confirm'), ['week' => '2026-12-21']);
        $patterns = ['patterns' => [['weekday' => 1, 'type' => 'unavailable', 'repeat' => 'w1', 'start_time' => '00:00', 'end_time' => '23:59']]];

        // Standaard: wijzigen mag, de planner ziet het.
        $this->put(route('my.availability.update'), $patterns)->assertSessionHasNoErrors();
        $this->assertTrue(AvailabilityWeek::sole()->changed_after_confirm);

        // Vergrendeld: niet meer zelf wijzigen.
        PolicyVersion::create(['valid_from' => '2026-11-01', 'settings' => ['availability.after_confirm' => 'locked'], 'leave_types' => []]);
        Policy::forget();
        $this->put(route('my.availability.update'), ['patterns' => []])->assertSessionHasErrors('patterns');
        $this->post(route('my.absences.store'), ['type' => 'niet_beschikbaar', 'days' => [['date' => '2026-12-22']]])->assertSessionHasErrors('days');
    }

    public function test_target_week_is_always_before_the_roster_deadline(): void
    {
        PolicyVersion::create(['valid_from' => '2026-11-01', 'settings' => ['roster.notice_weeks' => 6], 'leave_types' => []]);
        Policy::forget();

        $this->assertSame('2027-01-04', WeekAvailability::targetWeek()->toDateString()); // 16 nov + (6 + 1) weken
    }

    public function test_planner_overview_shows_status_and_signals(): void
    {
        $bram = Employee::factory()->create(['name' => 'Bram']); // geen inlog
        $cees = Employee::factory()->create(['name' => 'Cees', 'contract_hours_min' => 10]);
        // Cees werkte vorig kwartaal (jul–sep) gemiddeld 20 u per week.
        foreach (range(0, 12) as $i) {
            $day = CarbonImmutable::parse('2026-07-06')->addWeeks($i)->toDateString();
            Shift::factory()->at($day, '06:00', '16:00')->create()->assignments()->create(['employee_id' => $cees->id]);
            Shift::factory()->at(CarbonImmutable::parse($day)->addDay()->toDateString(), '06:00', '16:00')->create()->assignments()->create(['employee_id' => $cees->id]);
        }
        AvailabilityWeek::create(['employee_id' => $this->anna->id, 'week_start' => '2026-12-21', 'confirmed_at' => now(), 'available_hours' => 30, 'explanation' => 'Alles goed']);
        $this->actingAs(User::factory()->create());

        $this->get(route('availability.overview'))->assertOk()
            ->assertSee('Week 52')->assertSee('bevestigd')->assertSee('geen inlog')
            ->assertSee('Alles goed')
            ->assertSee('Cees')->assertSee('gemiddeld 20 u per week, contract 10 u (+100%)')
            ->assertSee('ingepland 0 u'); // week 48: rooster al bekend (4 weken), Anna staat nergens op
        $this->actingAs($this->user)->get(route('availability.overview'))->assertForbidden();
    }
}

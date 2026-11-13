<?php

namespace Tests\Feature\Http;

use App\Models\Absence;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PolicyVersion;
use App\Models\Shift;
use App\Models\User;
use App\Services\ConflictChecker;
use App\Support\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 10:00');
    }

    /** Formulierdata met alle huidige waarden (zoals de pagina die verstuurt). */
    private function form(array $settings = [], array $leaveTypes = [], array $extra = []): array
    {
        $policy = Policy::current();
        $data = ['valid_from' => '2026-11-13', 'settings' => [], 'leave_types' => []];
        foreach (Policy::groups() as $group => $definition) {
            foreach ($definition['items'] as $item => $setting) {
                $value = $settings["{$group}.{$item}"] ?? $policy->get("{$group}.{$item}");
                $data['settings'][$group][$item] = is_bool($value) ? ($value ? '1' : '0') : $value;
            }
        }
        foreach ($policy->leaveTypes() as $key => $type) {
            $row = array_intersect_key($type, Policy::LEAVE_FIELDS);
            $row = array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $row);
            $data['leave_types'][$key] = array_merge($row, $leaveTypes[$key] ?? []);
        }
        foreach ($leaveTypes as $key => $row) {
            if (! isset($data['leave_types'][$key])) {
                $data['leave_types'][$key] = $row;
            }
        }

        return array_merge($data, $extra);
    }

    public function test_defaults_follow_the_cao_without_any_version(): void
    {
        $policy = Policy::current();

        $this->assertNull($policy->version);
        $this->assertSame(4, $policy->get('roster.notice_weeks'));
        $this->assertSame(7.69, $policy->get('vacation.accrual_statutory_percent'));
        $this->assertSame(85, $policy->get('sickness.pay_under_2_years'));
        $this->assertFalse($policy->leaveType('verhuisdag')['active']);
        $this->assertSame('Vakantie (snipperuren)', $policy->leaveTypeName('verlof'));
        $this->assertContains('ziek', $policy->directTypes());
        $this->assertArrayNotHasKey('ziek', $policy->requestableTypes());
        $this->assertArrayHasKey('verjaardag', $policy->requestableTypes());

        $this->actingAs(User::factory()->create())->get(route('policy.index'))->assertOk()
            ->assertSee('Alles staat op de cao-standaard')->assertSee('Rooster bekendmaken vooraf');
    }

    public function test_saving_stores_only_deviations_and_logs_it(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'teamleider']));

        $this->put(route('policy.update'), $this->form(['roster.notice_weeks' => 6], ['verhuisdag' => ['active' => '1']]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $version = PolicyVersion::sole();
        $this->assertSame(['roster.notice_weeks' => 6], $version->settings);
        $this->assertSame(['verhuisdag' => ['active' => true]], $version->leave_types);
        $this->assertSame(6, Policy::current()->get('roster.notice_weeks'));
        $this->assertTrue(Policy::current()->isDeviating('roster.notice_weeks'));
        $this->assertFalse(Policy::current()->isUnfavorable('roster.notice_weeks'), 'langer vooraf is gunstiger');

        // Nogmaals opslaan met dezelfde ingangsdatum werkt die versie bij.
        $this->put(route('policy.update'), $this->form(['roster.notice_weeks' => 5]))->assertSessionHasNoErrors();
        $this->assertSame(1, PolicyVersion::count());
        $this->assertSame(5, Policy::current()->get('roster.notice_weeks'));

        $log = AuditLog::firstWhere('action', 'policy.updated');
        $this->assertContains('Rooster bekendmaken vooraf', $log->meta['gewijzigd']);
        $this->get(route('policy.index'))->assertSee('afwijkend');
    }

    public function test_unfavorable_deviation_needs_a_note(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put(route('policy.update'), $this->form(['vacation.accrual_extra_percent' => 1]))->assertSessionHasErrors('note');
        $this->put(route('policy.update'), $this->form([], ['huwelijk' => ['paid' => '50']]))->assertSessionHasErrors('note');
        $this->assertSame(0, PolicyVersion::count());

        $this->put(route('policy.update'), $this->form(['vacation.accrual_extra_percent' => 1], [], ['note' => 'Afspraak met de OR']))
            ->assertSessionHasNoErrors();
        $this->assertTrue(Policy::current()->isUnfavorable('vacation.accrual_extra_percent'));
    }

    public function test_versions_apply_from_their_date(): void
    {
        $this->actingAs(User::factory()->create());
        $this->put(route('policy.update'), $this->form(['roster.notice_weeks' => 6], [], ['valid_from' => '2026-12-01']))->assertSessionHasNoErrors();

        $this->assertSame(4, Policy::at('2026-11-30')->get('roster.notice_weeks'));
        $this->assertSame(6, Policy::at('2026-12-01')->get('roster.notice_weeks'));
        $this->put(route('policy.update'), $this->form([], [], ['valid_from' => '2026-11-01']))->assertSessionHasErrors('valid_from');
        $this->get(route('policy.index'))->assertSee('Nieuwe versie gaat in op 1 december 2026');
    }

    public function test_custom_leave_type_can_be_added_and_used(): void
    {
        $this->actingAs(User::factory()->create());
        $new = Policy::emptyLeaveType();
        $new = array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, array_intersect_key($new, Policy::LEAVE_FIELDS));
        $new['name'] = 'Studieverlof';
        $new['self_request'] = '1';

        $this->put(route('policy.update'), $this->form([], ['nieuw1' => $new]))->assertSessionHasNoErrors();

        $type = Policy::current()->leaveType('studieverlof');
        $this->assertTrue($type['custom']);
        $this->assertArrayHasKey('studieverlof', Policy::current()->requestableTypes());

        $employee = Employee::factory()->create();
        $this->post(route('absences.store'), ['employee_id' => $employee->id, 'type' => 'studieverlof', 'days' => [['date' => '2026-11-20']]])
            ->assertSessionHasNoErrors();
        $this->assertSame('Studieverlof', Absence::sole()->typeLabel());
    }

    public function test_only_allowed_roles_may_edit(): void
    {
        PolicyVersion::create(['valid_from' => '2026-11-01', 'settings' => ['privacy.edit_roles' => 'teamleaders'], 'leave_types' => []]);
        Policy::forget();

        $this->actingAs(User::factory()->create(['role' => 'planner']));
        $this->get(route('policy.index'))->assertOk()->assertSee('Je kunt het beleid bekijken maar niet wijzigen');
        $this->put(route('policy.update'), $this->form())->assertForbidden();

        $this->actingAs(User::factory()->create(['role' => 'teamleider']));
        $this->put(route('policy.update'), $this->form([], [], ['note' => 'x']))->assertSessionHasNoErrors();
    }

    public function test_employees_read_the_policy_without_internal_parts(): void
    {
        PolicyVersion::create([
            'valid_from' => '2026-11-01', 'settings' => ['roster.notice_weeks' => 6], 'leave_types' => [],
            'employee_notes' => ['roster.notice_weeks' => 'Zo weet je het ruim op tijd.'],
        ]);
        PolicyVersion::create(['valid_from' => '2026-12-01', 'settings' => ['roster.notice_weeks' => 6, 'vacation.holiday_allowance_percent' => 9], 'leave_types' => []]);
        Policy::forget();
        $user = User::factory()->create(['role' => 'medewerker', 'employee_id' => Employee::factory()->create()->id]);

        $this->actingAs($user)->get(route('my.policy'))->assertOk()
            ->assertSee('Je rooster is uiterlijk 6 weken van tevoren bekend.')
            ->assertSee('bedrijfsregeling')->assertSee('Zo weet je het ruim op tijd.')
            ->assertSee('Vanaf 1 december 2026 verandert')
            ->assertSee('Verjaardagsverlof')
            ->assertDontSee('Signaal: ingepland onder contracturen')
            ->assertDontSee('Wie mag het beleid wijzigen');
        $this->get(route('policy.index'))->assertForbidden();
        $this->put(route('policy.update'), [])->assertForbidden();

        PolicyVersion::whereDate('valid_from', '2026-11-01')->update(['settings' => json_encode(['privacy.visible_to_employees' => false])]);
        Policy::forget();
        $this->get(route('my.policy'))->assertNotFound();
    }

    public function test_planners_can_preview_the_employee_view(): void
    {
        $this->actingAs(User::factory()->create())->get(route('policy.preview'))->assertOk()
            ->assertSee('Zo zien medewerkers het')->assertSee('Je rooster is uiterlijk 4 weken van tevoren bekend.');
    }

    public function test_planning_warns_about_rest_time_and_long_shifts(): void
    {
        $employee = Employee::factory()->create();
        $evening = Shift::factory()->at('2026-11-16', '17:00', '22:00')->create();
        $evening->assignments()->create(['employee_id' => $employee->id]);
        $morning = Shift::factory()->at('2026-11-17', '06:00', '10:00')->create();
        $long = Shift::factory()->at('2026-11-19', '06:00', '19:00')->create();

        $checker = app(ConflictChecker::class);
        $rest = collect($checker->forCandidate($morning, $employee->id)['warnings'])->firstWhere('type', 'rest');
        $this->assertStringContainsString('Rusttijd 8 u', $rest['message']);
        $this->assertStringContainsString('minimaal 11 u', $rest['message']);
        $this->assertContains('Dienst langer dan 12 uur (Arbeidstijdenwet)', array_column($checker->forCandidate($long, $employee->id)['warnings'], 'message'));

        // Uit te zetten via het beleid.
        PolicyVersion::create(['valid_from' => '2026-11-01', 'settings' => ['roster.warn_in_planning' => false], 'leave_types' => []]);
        Policy::forget();
        $this->assertNotContains('rest', array_column(app(ConflictChecker::class)->forCandidate($morning, $employee->id)['warnings'], 'type'));
    }

    public function test_split_shift_on_one_day_is_no_rest_time_violation(): void
    {
        $employee = Employee::factory()->create();
        Shift::factory()->at('2026-11-18', '07:30', '08:00')->create()->assignments()->create(['employee_id' => $employee->id]);
        Shift::factory()->at('2026-11-19', '07:30', '08:00')->create()->assignments()->create(['employee_id' => $employee->id]);
        $afternoon = Shift::factory()->at('2026-11-18', '13:00', '15:00')->create();

        // 07:30–08:00 is geen nachtdienst en tussen 15:00 en 07:30 zit 16,5 u rust aan één stuk.
        $this->assertNotContains('rest', array_column(app(ConflictChecker::class)->forCandidate($afternoon, $employee->id)['warnings'], 'type'));
    }

    public function test_daily_rest_is_measured_within_24_hours_from_a_shift_start(): void
    {
        $employee = Employee::factory()->create();
        Shift::factory()->at('2026-11-18', '08:00', '12:00')->create()->assignments()->create(['employee_id' => $employee->id]);
        Shift::factory()->at('2026-11-19', '06:00', '10:00')->create()->assignments()->create(['employee_id' => $employee->id]);
        $evening = Shift::factory()->at('2026-11-18', '20:00', '22:00')->create();

        // Binnen 24 u vanaf 18-11 08:00 is de langste rust 8 u (12–20 en 22–06).
        $rest = collect(app(ConflictChecker::class)->forCandidate($evening, $employee->id)['warnings'])->firstWhere('type', 'rest');
        $this->assertNotNull($rest);
        $this->assertStringContainsString('Rusttijd 8 u binnen 24 u', $rest['message']);
        $this->assertStringContainsString('18-11 08:00', $rest['message']);
    }

    public function test_night_rest_only_applies_after_a_real_night_shift(): void
    {
        $employee = Employee::factory()->create();
        Shift::factory()->at('2026-11-18', '22:00', '23:59')->create(['ends_at' => '2026-11-19 06:00:00'])->assignments()->create(['employee_id' => $employee->id]);
        $early = Shift::factory()->at('2026-11-18', '05:00', '08:00')->create();
        $afterNight = Shift::factory()->at('2026-11-19', '18:00', '20:00')->create();
        $checker = app(ConflictChecker::class);

        // 22:00–06:00 is een nachtdienst: 12 u rust is te weinig, minimaal 14 u.
        $rest = collect($checker->forCandidate($afterNight, $employee->id)['warnings'])->firstWhere('type', 'rest');
        $this->assertStringContainsString('na nachtdienst', $rest['message']);
        $this->assertStringContainsString('minimaal 14 u', $rest['message']);

        // 05:00–08:00 heeft maar een uur tussen 00:00 en 06:00: geen nachtdienst, dus de 14 u geldt niet.
        $messages = array_column($checker->forCandidate($early, $employee->id)['warnings'], 'message');
        $this->assertEmpty(array_filter($messages, fn ($m) => str_contains($m, 'minimaal 14 u')));
    }
}

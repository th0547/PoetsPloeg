<?php

namespace Tests\Feature\Http;

use App\Models\Absence;
use App\Models\AvailabilityPattern;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\EmployeeInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmployeeAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-14 10:00'); // woensdag
        Notification::fake();
    }

    private function teamleader(): User
    {
        return User::factory()->create(['role' => 'teamleider']);
    }

    private function employeeData(array $extra = []): array
    {
        return $extra + ['name' => 'Anna de Vries', 'email' => 'anna@example.com', 'color' => '#336699', 'active' => '1'];
    }

    /** Een medewerker met een account waarvan het wachtwoord al is ingesteld. */
    private function employeeWithLogin(array $attributes = []): User
    {
        $employee = Employee::factory()->create($attributes);

        return User::factory()->create(['role' => 'medewerker', 'employee_id' => $employee->id, 'email' => $employee->email ?? fake()->safeEmail()]);
    }

    private function invitationToken(User $user): string
    {
        $token = null;
        Notification::assertSentTo($user, EmployeeInvitation::class, function (EmployeeInvitation $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        return $token;
    }

    public function test_teamleader_creates_an_employee_with_an_invitation(): void
    {
        $this->actingAs($this->teamleader())
            ->post(route('employees.store'), $this->employeeData(['invite' => '1']))
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'uitnodiging gestuurd naar anna@example.com'));

        $employee = Employee::firstWhere('email', 'anna@example.com');
        $user = $employee->user;
        $this->assertNotNull($user);
        $this->assertSame('medewerker', $user->role);
        $this->assertSame('Anna de Vries', $user->name);
        $this->assertTrue($user->invitationPending());
        $this->assertFalse($user->canPlan());

        $mail = null;
        Notification::assertSentTo($user, EmployeeInvitation::class, function (EmployeeInvitation $n) use ($user, &$mail) {
            $mail = $n->toMail($user);

            return true;
        });
        $this->assertStringContainsString('/uitnodiging/', $mail->actionUrl);
        $this->assertSame('Wachtwoord instellen', $mail->actionText);
    }

    public function test_without_the_invite_box_no_account_is_made(): void
    {
        $this->actingAs($this->teamleader())->post(route('employees.store'), $this->employeeData());

        $this->assertSame(0, User::where('role', 'medewerker')->count());
        Notification::assertNothingSent();
    }

    public function test_an_invitation_needs_a_unique_email_address(): void
    {
        $leader = $this->teamleader();
        $this->actingAs($leader)
            ->post(route('employees.store'), $this->employeeData(['invite' => '1', 'email' => '']))
            ->assertSessionHasErrors('email');
        $this->post(route('employees.store'), $this->employeeData(['invite' => '1', 'email' => $leader->email]))
            ->assertSessionHasErrors(['email' => 'Dit e-mailadres is al in gebruik door een ander account.']);

        $this->assertSame(0, Employee::count());
    }

    public function test_employee_sets_a_password_with_the_invitation_and_lands_on_own_planning(): void
    {
        $this->actingAs($this->teamleader())->post(route('employees.store'), $this->employeeData(['invite' => '1']));
        $user = User::firstWhere('email', 'anna@example.com');
        $token = $this->invitationToken($user);
        auth()->logout();

        $this->get(route('invitation.show', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Wachtwoord instellen');

        $this->post(route('invitation.store'), [
            'token' => $token, 'email' => $user->email, 'password' => 'nieuw-wachtwoord', 'password_confirmation' => 'nieuw-wachtwoord',
        ])->assertRedirect(route('my.planning'));

        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->fresh()->invitationPending());

        // De link werkt maar één keer.
        auth()->logout();
        $this->post(route('invitation.store'), [
            'token' => $token, 'email' => $user->email, 'password' => 'ander-wachtwoord', 'password_confirmation' => 'ander-wachtwoord',
        ])->assertSessionHasErrors('email');

        $this->post('/login', ['email' => $user->email, 'password' => 'nieuw-wachtwoord'])->assertRedirect(route('my.planning'));
    }

    public function test_an_invitation_is_valid_for_a_week(): void
    {
        $this->actingAs($this->teamleader())->post(route('employees.store'), $this->employeeData(['invite' => '1']));
        $user = User::firstWhere('email', 'anna@example.com');
        $token = $this->invitationToken($user);
        auth()->logout();

        $this->travel(8)->days();
        $this->post(route('invitation.store'), [
            'token' => $token, 'email' => $user->email, 'password' => 'nieuw-wachtwoord', 'password_confirmation' => 'nieuw-wachtwoord',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_resend_and_revoke_an_invitation(): void
    {
        $this->actingAs($this->teamleader())->post(route('employees.store'), $this->employeeData(['invite' => '1']));
        $employee = Employee::first();

        $this->post(route('employees.invitation', $employee))->assertRedirect(route('employees.edit', $employee));
        Notification::assertSentToTimes($employee->user, EmployeeInvitation::class, 2);

        $this->get(route('employees.edit', $employee))->assertOk()->assertSee('Uitgenodigd');

        $this->delete(route('employees.account.destroy', $employee))->assertRedirect(route('employees.edit', $employee));
        $this->assertNull($employee->fresh()->user);
        $this->assertNotNull($employee->fresh());
    }

    public function test_changing_the_email_address_moves_the_account_and_resends_a_pending_invitation(): void
    {
        $this->actingAs($this->teamleader())->post(route('employees.store'), $this->employeeData(['invite' => '1']));
        $employee = Employee::first();

        $this->put(route('employees.update', $employee), $this->employeeData(['name' => 'Anna Jansen', 'email' => 'anna.jansen@example.com']))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'opnieuw verstuurd naar anna.jansen@example.com'));

        $user = $employee->fresh()->user;
        $this->assertSame('anna.jansen@example.com', $user->email);
        $this->assertSame('Anna Jansen', $user->name);
        Notification::assertSentToTimes($user, EmployeeInvitation::class, 2);

        // Met een account kan het e-mailadres niet leeg.
        $this->put(route('employees.update', $employee), $this->employeeData(['email' => '']))->assertSessionHasErrors('email');
    }

    public function test_deleting_an_employee_also_deletes_the_login(): void
    {
        $user = $this->employeeWithLogin();

        $this->actingAs($this->teamleader())->delete(route('employees.destroy', $user->employee_id));

        $this->assertModelMissing($user);
    }

    public function test_inactive_employees_cannot_log_in_and_are_logged_out(): void
    {
        $user = $this->employeeWithLogin(['active' => false]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $user->employee->update(['active' => true]);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('my.planning'));
        $user->employee->update(['active' => false]);
        $this->app['auth']->forgetGuards(); // zoals bij een nieuw verzoek: gebruiker opnieuw laden
        $this->get(route('my.planning'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_employee_sees_only_own_shifts(): void
    {
        $user = $this->employeeWithLogin(['name' => 'Anna']);
        $colleague = Employee::factory()->create(['name' => 'Bram']);
        $other = Employee::factory()->create(['name' => 'Cees']);

        $mine = Shift::factory()->at('2026-10-15', '08:00', '10:00')->create(['title' => 'Kantoor schoonmaken']);
        $mine->assignments()->create(['employee_id' => $user->employee_id]);
        $mine->assignments()->create(['employee_id' => $colleague->id]);
        $notMine = Shift::factory()->at('2026-10-15', '12:00', '14:00')->create(['title' => 'Trappenhuis']);
        $notMine->assignments()->create(['employee_id' => $other->id]);

        $this->actingAs($user)->get(route('my.planning'))->assertOk()
            ->assertSee('Kantoor schoonmaken')
            ->assertSee('Met Bram')
            ->assertDontSee('Trappenhuis')
            ->assertDontSee('Cees');
    }

    public function test_planners_have_no_employee_pages(): void
    {
        $this->actingAs($this->teamleader())->get(route('my.planning'))->assertForbidden();
    }

    public function test_employee_requests_leave_for_themselves(): void
    {
        $user = $this->employeeWithLogin();
        $other = Employee::factory()->create();
        $shift = Shift::factory()->at('2026-10-20', '08:00', '10:00')->create();
        $shift->assignments()->create(['employee_id' => $user->employee_id]);

        $this->actingAs($user)->get(route('my.absences.create'))->assertOk()
            ->assertSee('Verlof aanvragen')->assertDontSee('<option value="ziek"', false);

        $this->post(route('my.absences.store'), [
            'employee_id' => $other->id, // wordt genegeerd
            'type' => 'verlof',
            'days' => [['date' => '2026-10-20', 'start_time' => '00:00', 'end_time' => '23:59']],
            'note' => 'Bruiloft',
        ])->assertRedirect(route('my.absences'));

        $absence = Absence::sole();
        $this->assertSame($user->employee_id, $absence->employee_id);
        $this->assertTrue($absence->isPending());
        // Blijft ingepland tot er een vervanger is.
        $this->assertTrue($shift->assignments()->where('employee_id', $user->employee_id)->exists());

        $this->get(route('my.absences'))->assertOk()->assertSee('Bruiloft')->assertSee('In afwachting van vervanger');
    }

    public function test_leave_without_shifts_is_approved_at_once(): void
    {
        $user = $this->employeeWithLogin();

        $this->actingAs($user)->post(route('my.absences.store'), [
            'type' => 'verlof', 'days' => [['date' => '2026-10-21']],
        ])->assertSessionHas('status', fn ($s) => str_contains($s, 'direct goedgekeurd'));

        $this->assertSame('approved', Absence::sole()->status);
    }

    public function test_employee_cannot_report_sick_or_request_past_days(): void
    {
        $user = $this->employeeWithLogin();
        $this->actingAs($user);

        $this->post(route('my.absences.store'), ['type' => 'ziek', 'days' => [['date' => '2026-10-20']]])->assertSessionHasErrors('type');
        $this->post(route('my.absences.store'), ['type' => 'verlof', 'days' => [['date' => '2026-10-13']]])->assertSessionHasErrors('days.0.date');
        $this->assertSame(0, Absence::count());
    }

    public function test_employee_withdraws_only_own_pending_requests(): void
    {
        $user = $this->employeeWithLogin();
        $pending = Absence::factory()->create(['employee_id' => $user->employee_id, 'status' => 'pending']);
        $approved = Absence::factory()->create(['employee_id' => $user->employee_id, 'status' => 'approved']);
        $someoneElse = Absence::factory()->create(['status' => 'pending']);
        $this->actingAs($user);

        $this->delete(route('my.absences.destroy', $someoneElse))->assertNotFound();
        $this->delete(route('my.absences.destroy', $approved))->assertStatus(422);
        $this->delete(route('my.absences.destroy', $pending))->assertRedirect(route('my.absences'));

        $this->assertSoftDeleted($pending);
        $this->assertNotSoftDeleted($approved);
        $this->assertNotSoftDeleted($someoneElse);
    }

    public function test_employee_passes_on_availability_without_being_removed_from_shifts(): void
    {
        $user = $this->employeeWithLogin();
        $shift = Shift::factory()->at('2026-10-16', '08:00', '10:00')->create(); // vrijdag
        $shift->assignments()->create(['employee_id' => $user->employee_id]);

        $this->actingAs($user)->get(route('my.availability'))->assertOk()->assertSee('Mijn beschikbaarheid');
        $this->put(route('my.availability.update'), ['patterns' => [
            ['weekday' => 5, 'type' => 'unavailable', 'repeat' => 'w1', 'start_time' => '00:00', 'end_time' => '23:59', 'note' => 'school'],
        ]])->assertRedirect(route('my.availability'));

        $pattern = AvailabilityPattern::sole();
        $this->assertSame($user->employee_id, $pattern->employee_id);
        $this->assertSame('school', $pattern->note);
        $this->assertTrue($shift->assignments()->where('employee_id', $user->employee_id)->exists());
    }

    public function test_employee_accounts_are_managed_on_the_employee_page(): void
    {
        $employeeUser = $this->employeeWithLogin(['name' => 'Anna']);
        $this->actingAs($this->teamleader());

        $this->get(route('users.index'))->assertOk()->assertDontSee($employeeUser->email);
        $this->get(route('users.edit', $employeeUser))->assertRedirect(route('employees.edit', $employeeUser->employee_id));
        $this->put(route('users.update', $employeeUser), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'planner'])
            ->assertRedirect(route('employees.edit', $employeeUser->employee_id));
        $this->assertSame('medewerker', $employeeUser->fresh()->role);
        $this->post(route('users.store'), ['name' => 'X', 'email' => 'x@example.com', 'role' => 'medewerker', 'password' => 'geheim-wachtwoord', 'password_confirmation' => 'geheim-wachtwoord'])
            ->assertSessionHasErrors('role');
    }

    public function test_a_failing_mail_server_does_not_break_creating_the_employee(): void
    {
        // Echte (onbereikbare) SMTP-server in plaats van de fake.
        Notification::swap(new ChannelManager(app()));
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $this->actingAs($this->teamleader())
            ->post(route('employees.store'), $this->employeeData(['invite' => '1']))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'kon niet worden verstuurd'));

        $employee = Employee::sole();
        $this->assertTrue($employee->user->invitationPending());

        $this->post(route('employees.invitation', $employee))->assertSessionHasErrors('invite');
    }

    public function test_test_mail_command(): void
    {
        $this->artisan('poetsploeg:test-mail', ['adres' => 'geen-adres'])->assertFailed();
        $this->artisan('poetsploeg:test-mail', ['adres' => 'test@example.com'])
            ->expectsOutputToContain('er is niets echt verstuurd')
            ->assertSuccessful();
    }
}

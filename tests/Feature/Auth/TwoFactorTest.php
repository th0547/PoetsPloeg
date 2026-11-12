<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\TwoFactorChanged;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $g2fa;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->g2fa = new Google2FA;
    }

    /** Account met 2FA aan; geeft het geheim terug. */
    private function withTwoFactor(User $user): string
    {
        $secret = $this->g2fa->generateSecretKey(32);
        app(TwoFactor::class)->enable($user, $secret);

        return $secret;
    }

    private function code(string $secret, int $offsetSteps = 0): string
    {
        return $this->g2fa->oathTotp($secret, $this->g2fa->getTimestamp() + $offsetSteps);
    }

    public function test_login_with_two_factor_needs_the_code(): void
    {
        $user = User::factory()->create(['email' => 'p@example.com']);
        $secret = $this->withTwoFactor($user);

        $this->post('/login', ['email' => 'p@example.com', 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->get(route('two-factor.challenge'))->assertOk()->assertSee('Vul de code van 6 cijfers');

        $this->post(route('two-factor.challenge'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.challenge'), ['code' => $this->code($secret)])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['auth.2fa_failed', 'auth.login'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = User::factory()->create();
        $secret = $this->withTwoFactor($user);
        $code = $this->code($secret);

        $this->assertTrue(app(TwoFactor::class)->verify($user, $code));
        $this->assertFalse(app(TwoFactor::class)->verify($user->fresh(), $code));
    }

    public function test_recovery_codes_work_once(): void
    {
        $user = User::factory()->create(['email' => 'p@example.com']);
        $this->withTwoFactor($user);
        $codes = app(TwoFactor::class)->newRecoveryCodes($user);

        $this->post('/login', ['email' => 'p@example.com', 'password' => 'password']);
        $this->post(route('two-factor.challenge'), ['recovery_code' => $codes[0]])->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', fn ($s) => str_contains($s, 'nog 7 over'));
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->post('/login', ['email' => 'p@example.com', 'password' => 'password']);
        $this->post(route('two-factor.challenge'), ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_challenge_is_throttled_and_expires(): void
    {
        $user = User::factory()->create(['email' => 'p@example.com']);
        $secret = $this->withTwoFactor($user);
        $this->post('/login', ['email' => 'p@example.com', 'password' => 'password']);

        foreach (range(1, 5) as $i) {
            $this->post(route('two-factor.challenge'), ['code' => '000000']);
        }
        $this->post(route('two-factor.challenge'), ['code' => $this->code($secret)])->assertSessionHasErrors('code');
        $this->assertStringContainsString('Te veel pogingen', session('errors')->first('code'));
        $this->assertGuest();

        $this->travel(6)->minutes();
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
    }

    public function test_planners_must_set_up_two_factor_and_can_do_so(): void
    {
        config(['poetsploeg.two_factor_required' => true]);
        $planner = User::factory()->create();
        $this->actingAs($planner)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('dashboard'))->assertRedirect(route('two-factor.show'));
        $this->getJson('/api/planning')->assertForbidden();

        $this->get(route('two-factor.show'))->assertOk()->assertSee('Verplicht voor jouw rol')->assertSee('data:image/svg+xml;base64,', false);
        $secret = session('two_factor.setup_secret');

        $this->post(route('two-factor.confirm'), ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post(route('two-factor.confirm'), ['code' => $this->code($secret)])
            ->assertRedirect(route('two-factor.show'))->assertSessionHas('two_factor.recovery_codes', fn ($c) => count($c) === 8);

        $this->assertTrue($planner->fresh()->hasTwoFactor());
        $this->get(route('dashboard'))->assertOk();
        Notification::assertSentTo($planner, TwoFactorChanged::class);

        // Verplicht: uitzetten kan niet.
        $this->delete(route('two-factor.destroy'))->assertSessionHasErrors('two_factor');
        $this->assertTrue($planner->fresh()->hasTwoFactor());
    }

    public function test_setup_asks_for_the_password_first(): void
    {
        config(['poetsploeg.two_factor_required' => true]);
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertRedirect(route('two-factor.show'));
        $this->get(route('two-factor.show'))->assertRedirect(route('password.confirm'));
        // Bevestigen moet ook lukken zolang 2FA nog niet is ingesteld.
        $this->post('/confirm-password', ['password' => 'password'])->assertRedirect(route('two-factor.show'));
        $this->get(route('two-factor.show'))->assertOk();
    }

    public function test_employees_may_choose_and_turn_it_off(): void
    {
        config(['poetsploeg.two_factor_required' => true]);
        $user = User::factory()->create(['role' => 'medewerker', 'employee_id' => Employee::factory()->create()->id]);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        $this->get(route('my.planning'))->assertOk();
        $this->withTwoFactor($user);
        $this->delete(route('two-factor.destroy'))->assertRedirect(route('two-factor.show'));
        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_teamleader_can_reset_someone_elses_two_factor(): void
    {
        $leader = User::factory()->create(['role' => 'teamleider']);
        $planner = User::factory()->create(['name' => 'Piet']);
        $this->withTwoFactor($planner);

        $this->actingAs(User::factory()->create(['role' => 'planner']))
            ->post(route('users.two-factor.reset', $planner))->assertForbidden();

        $this->actingAs($leader)->get(route('users.index'))->assertSee('2FA resetten');
        $this->post(route('users.two-factor.reset', $planner))->assertRedirect(route('users.index'));
        $this->assertFalse($planner->fresh()->hasTwoFactor());
        $this->assertSame(1, AuditLog::where('action', 'twofactor.reset')->count());
        Notification::assertSentTo($planner, TwoFactorChanged::class, fn ($n) => $n->by === $leader->name);

        $this->withTwoFactor($leader);
        $this->post(route('users.two-factor.reset', $leader))->assertStatus(422);
    }

    public function test_reset_command(): void
    {
        $user = User::factory()->create(['email' => 'p@example.com']);
        $this->withTwoFactor($user);

        $this->artisan('poetsploeg:reset-2fa', ['email' => 'p@example.com'])->assertSuccessful();
        $this->assertFalse($user->fresh()->hasTwoFactor());
        $this->artisan('poetsploeg:reset-2fa', ['email' => 'nee@example.com'])->assertFailed();
    }

    public function test_secret_is_stored_encrypted_and_hidden(): void
    {
        $user = User::factory()->create();
        $secret = $this->withTwoFactor($user);

        $raw = \DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw);
        $this->assertArrayNotHasKey('two_factor_secret', $user->fresh()->toArray());
    }
}

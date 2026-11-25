<?php

namespace Tests\Feature\Api\V1;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private Google2FA $g2fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->g2fa = new Google2FA;
    }

    private function withTwoFactor(User $user): string
    {
        $secret = $this->g2fa->generateSecretKey(32);
        app(TwoFactor::class)->enable($user, $secret);

        return $secret;
    }

    private function code(string $secret): string
    {
        return $this->g2fa->oathTotp($secret, $this->g2fa->getTimestamp());
    }

    private function login(array $overrides = [])
    {
        return $this->postJson('/api/v1/auth/login', $overrides + [
            'email' => 'p@example.com', 'password' => 'password', 'device_name' => 'iPhone van Petra',
        ]);
    }

    public function test_login_with_two_factor_gives_a_token_after_the_code(): void
    {
        $user = User::factory()->create(['email' => 'p@example.com', 'name' => 'Petra']);
        $secret = $this->withTwoFactor($user);

        $challenge = $this->login()->assertOk()->assertJson(['two_factor' => true])->json('challenge');
        $this->assertSame(0, $user->tokens()->count());

        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])
            ->assertStatus(422)->assertJsonValidationErrors('code');

        $response = $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertOk()
            ->assertJsonPath('user.name', 'Petra')
            ->assertJsonPath('user.role', 'planner');
        $token = $response->json('token');
        $this->assertNotEmpty($token);
        $this->assertNotNull($response->json('expires_at'));
        $this->assertSame('iPhone van Petra', $user->tokens()->first()->name);

        // De challenge werkt maar één keer.
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonValidationErrors('challenge');

        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.email', 'p@example.com');
        $this->assertSame(['auth.2fa_failed', 'auth.app_login'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_recovery_code_works_and_reports_codes_left(): void
    {
        $user = User::factory()->create(['email' => 'p@example.com']);
        $this->withTwoFactor($user);
        $codes = app(TwoFactor::class)->newRecoveryCodes($user);

        $challenge = $this->login()->json('challenge');
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'recovery_code' => $codes[0]])
            ->assertOk()->assertJsonPath('recovery_codes_left', 7);
    }

    public function test_wrong_password_is_rejected_and_rate_limited(): void
    {
        User::factory()->create(['email' => 'p@example.com']);

        $this->login(['password' => 'fout'])->assertStatus(422)->assertJsonValidationErrors('email');
        foreach (range(1, 4) as $i) {
            $this->login(['password' => 'fout']);
        }
        $this->login()->assertStatus(422)->assertJsonValidationErrors('email');
        $this->assertTrue(RateLimiter::tooManyAttempts('p@example.com|127.0.0.1', 5));
    }

    public function test_too_many_wrong_codes_are_blocked(): void
    {
        $user = User::factory()->create(['email' => 'p@example.com']);
        $secret = $this->withTwoFactor($user);
        $challenge = $this->login()->json('challenge');

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => $this->code($secret)])
            ->assertStatus(422)->assertJsonPath('errors.code.0', fn ($m) => str_contains($m, 'Te veel pogingen'));
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_employee_accounts_cannot_use_the_app(): void
    {
        $employee = Employee::factory()->create();
        User::factory()->create(['email' => 'p@example.com', 'role' => 'medewerker', 'employee_id' => $employee->id]);

        $this->login()->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'alleen voor planners'));
    }

    public function test_planner_without_required_two_factor_must_set_it_up_first(): void
    {
        config(['poetsploeg.two_factor_required' => true]);
        User::factory()->create(['email' => 'p@example.com']);

        $this->login()->assertForbidden()->assertJsonPath('message', fn ($m) => str_contains($m, 'tweestapsverificatie'));
    }

    public function test_without_two_factor_when_not_required_the_token_comes_directly(): void
    {
        User::factory()->create(['email' => 'p@example.com']);

        $this->login()->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_logout_revokes_the_token(): void
    {
        User::factory()->create(['email' => 'p@example.com']);
        $token = $this->login()->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_tokens_are_revoked_when_password_role_or_two_factor_changes(): void
    {
        $user = User::factory()->create();
        $user->createToken('a', ['plan']);
        $user->update(['password' => 'nieuw-wachtwoord-123']);
        $this->assertSame(0, $user->tokens()->count());

        $user->createToken('b', ['plan']);
        $user->update(['role' => 'teamleider']);
        $this->assertSame(0, $user->tokens()->count());

        $this->withTwoFactor($user);
        $user->createToken('c', ['plan']);
        app(TwoFactor::class)->disable($user);
        $this->assertSame(0, $user->tokens()->count());

        $user->createToken('d', ['plan']);
        $user->update(['name' => 'Andere naam']);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_api_needs_a_token_and_ignores_the_website_session(): void
    {
        $this->getJson('/api/v1/today')->assertUnauthorized();

        $this->actingAs(User::factory()->create(), 'web');
        $this->getJson('/api/v1/today')->assertUnauthorized();
    }

    public function test_token_without_plan_ability_is_refused(): void
    {
        $token = User::factory()->create()->createToken('x', [])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/today')->assertForbidden();
    }
}

<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    private function employeeUser(): User
    {
        $employee = Employee::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);

        return User::factory()->create(['role' => 'medewerker', 'employee_id' => $employee->id, 'name' => 'Anna', 'email' => 'anna@example.com']);
    }

    public function test_employees_cannot_change_name_or_email_themselves(): void
    {
        $user = $this->employeeUser();
        $this->actingAs($user);

        $this->get('/profile')->assertOk()
            ->assertSee('Vraag je teamleider om het aan te passen')
            ->assertDontSee('name="email"', false)
            ->assertDontSee('Delete Account');

        $this->patch('/profile', ['name' => 'Iemand anders', 'email' => 'ander@example.com'])->assertForbidden();

        $user->refresh();
        $this->assertSame('Anna', $user->name);
        $this->assertSame('anna@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->invitationPending());
    }

    public function test_employees_cannot_delete_their_own_account(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertForbidden();

        $this->assertNotNull($user->fresh());
    }

    public function test_employees_can_still_change_their_password(): void
    {
        $user = $this->employeeUser();

        $this->actingAs($user)->from('/profile')->put('/password', [
            'current_password' => 'password',
            'password' => 'nieuw-wachtwoord',
            'password_confirmation' => 'nieuw-wachtwoord',
        ])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $this->assertTrue(Hash::check('nieuw-wachtwoord', $user->fresh()->password));
    }
}

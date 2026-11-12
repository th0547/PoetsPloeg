<?php

namespace Tests\Feature\Http;

use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_personal_data_is_logged_once_per_ten_minutes(): void
    {
        $planner = User::factory()->create(['name' => 'Piet Planner']);
        $employee = Employee::factory()->create(['name' => 'Anna']);
        $this->actingAs($planner);

        $this->get(route('employees.edit', $employee))->assertOk();
        $this->get(route('employees.edit', $employee))->assertOk();

        $log = AuditLog::sole();
        $this->assertSame('pii.view', $log->action);
        $this->assertSame($planner->id, $log->user_id);
        $this->assertSame('Piet Planner', $log->user_name);
        $this->assertSame('Employee', $log->subject_type);
        $this->assertSame($employee->id, $log->subject_id);
        $this->assertStringContainsString('Anna', $log->description);

        $this->travel(11)->minutes();
        $this->get(route('employees.edit', $employee));
        $this->assertSame(2, AuditLog::count());
    }

    public function test_shift_panel_and_building_page_log_contact_details(): void
    {
        $this->actingAs(User::factory()->create());
        $shift = Shift::factory()->create();

        $this->getJson("/api/shifts/{$shift->id}")->assertOk();
        $this->get(route('buildings.show', $shift->building))->assertOk();

        $this->assertEqualsCanonicalizing(['Shift', 'Building'], AuditLog::pluck('subject_type')->all());
    }

    public function test_login_failed_login_and_logout_are_logged(): void
    {
        $user = User::factory()->create(['email' => 'p@example.com']);

        $this->post('/login', ['email' => 'p@example.com', 'password' => 'fout']);
        $this->post('/login', ['email' => 'p@example.com', 'password' => 'password']);
        $this->post('/logout');

        $this->assertSame(['auth.failed', 'auth.login', 'auth.logout'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame(['email' => 'p@example.com'], AuditLog::firstWhere('action', 'auth.failed')->meta);
        $this->assertSame($user->id, AuditLog::firstWhere('action', 'auth.login')->user_id);
    }

    public function test_user_management_and_employee_accounts_are_logged(): void
    {
        Notification::fake();
        $this->actingAs(User::factory()->create(['role' => 'teamleider']));

        $this->post(route('users.store'), ['name' => 'Nieuw', 'email' => 'n@example.com', 'role' => 'planner', 'password' => 'geheim-wachtwoord', 'password_confirmation' => 'geheim-wachtwoord']);
        $user = User::firstWhere('email', 'n@example.com');
        $this->put(route('users.update', $user), ['name' => 'Nieuw', 'email' => 'n@example.com', 'role' => 'teamleider']);
        $this->delete(route('users.destroy', $user));

        $this->post(route('employees.store'), ['name' => 'Anna', 'email' => 'anna@example.com', 'color' => '#336699', 'active' => '1', 'invite' => '1']);
        $employee = Employee::firstWhere('name', 'Anna');
        $this->delete(route('employees.account.destroy', $employee));
        $this->delete(route('employees.destroy', $employee));

        $actions = AuditLog::where('action', '!=', 'pii.view')->orderBy('id')->pluck('action')->all();
        $this->assertSame(['user.created', 'user.updated', 'user.deleted', 'account.invited', 'account.revoked', 'employee.deleted'], $actions);
        $this->assertSame(['velden' => ['role']], AuditLog::firstWhere('action', 'user.updated')->meta);
    }

    public function test_only_teamleaders_see_the_log(): void
    {
        AuditLog::create(['action' => 'auth.failed', 'description' => 'Inloggen mislukt', 'meta' => ['email' => 'x@example.com']]);

        $this->actingAs(User::factory()->create(['role' => 'planner']))->get(route('audit-log'))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'teamleider']))->get(route('audit-log'))
            ->assertOk()->assertSee('Inloggen mislukt')->assertSee('x@example.com');
        $this->get(route('audit-log', ['actie' => 'pii.view']))->assertOk()->assertSee('Geen regels gevonden');
    }

    public function test_old_entries_are_pruned(): void
    {
        $old = AuditLog::create(['action' => 'auth.login', 'description' => 'oud']);
        $old->forceFill(['created_at' => now()->subMonths(13)])->save();
        AuditLog::create(['action' => 'auth.login', 'description' => 'nieuw']);

        $this->artisan('model:prune', ['--model' => [AuditLog::class]])->assertSuccessful();

        $this->assertSame(['nieuw'], AuditLog::pluck('description')->all());
    }

    public function test_planners_without_contact_rights_do_not_create_view_logs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'medewerker', 'employee_id' => Employee::factory()->create()->id]));
        $this->get(route('buildings.show', Building::factory()->create()))->assertForbidden();

        $this->assertSame(0, AuditLog::where('action', 'pii.view')->count());
    }
}

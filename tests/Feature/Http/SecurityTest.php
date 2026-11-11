<?php

namespace Tests\Feature\Http;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    /** Routes die zonder planner-rechten bereikbaar mogen zijn. */
    private const PUBLIC_OR_PERSONAL = [
        'health', 'security-txt', 'login', 'logout', 'password.request', 'password.email', 'password.reset',
        'password.store', 'invitation.show', 'invitation.store', 'password.confirm', 'password.update', 'verification.notice', 'verification.verify',
        'verification.send', 'profile.edit', 'profile.update', 'profile.destroy', 'storage.local', 'storage.local.upload',
    ];

    public function test_every_route_requires_planner_rights_unless_explicitly_allowed(): void
    {
        // Voorbereiding op een medewerker-login: een nieuwe route is standaard alleen voor planners.
        $open = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => $route->getName() && ! str_starts_with($route->uri(), '_') && $route->uri() !== 'up')
            ->reject(fn ($route) => in_array('planner', $route->gatherMiddleware(), true))
            // Het medewerkersdeel (/mijn) is alleen voor medewerker-accounts.
            ->reject(fn ($route) => str_starts_with($route->getName(), 'my.') && in_array('employee', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->getName())
            ->diff(self::PUBLIC_OR_PERSONAL)
            ->values()
            ->all();

        $this->assertSame([], $open, 'Deze routes zijn bereikbaar zonder planner-rechten: '.implode(', ', $open));
    }

    public function test_accounts_without_planner_rights_get_nothing(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'medewerker', 'employee_id' => Employee::factory()->create()->id]));
        $shift = Shift::factory()->create();

        $this->assertFalse(Gate::allows('plan'));
        $this->assertFalse(Gate::allows('view-contact-details'));
        $this->get(route('dashboard'))->assertRedirect(route('my.planning'));
        $this->get(route('planning'))->assertForbidden();
        $this->get(route('employees.index'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
        $this->getJson("/api/shifts/{$shift->id}")->assertForbidden();
        $this->getJson("/api/shifts/{$shift->id}/candidates")->assertForbidden();
    }

    public function test_planners_see_contact_details(): void
    {
        $this->actingAs(User::factory()->create());
        $shift = Shift::factory()->create();
        $shift->building->update(['access_instructions' => 'Sleutel in kluis 12']);
        $employee = Employee::factory()->create(['phone' => '0612345678']);
        $shift->assignments()->create(['employee_id' => $employee->id]);

        $this->getJson("/api/shifts/{$shift->id}")->assertOk()
            ->assertJsonPath('building.access', 'Sleutel in kluis 12')
            ->assertJsonPath('assignments.0.phone', '0612345678');
    }

    public function test_pages_send_a_content_security_policy(): void
    {
        $this->actingAs(User::factory()->create());

        $csp = $this->get(route('dashboard'))->assertOk()->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("base-uri 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'unsafe-eval'(;|$)/", $csp);
        $this->get('/login')->assertHeader('Content-Security-Policy');
    }

    public function test_content_security_policy_can_be_report_only_or_off(): void
    {
        config(['poetsploeg.csp' => 'report-only']);
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy')->assertHeader('Content-Security-Policy-Report-Only');

        config(['poetsploeg.csp' => 'uit']);
        $this->get('/login')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_views_have_no_inline_event_handlers(): void
    {
        // Inline onclick/onsubmit zou door de CSP worden geblokkeerd; gebruik data-attributen (resources/js/actions.js).
        $files = collect(glob(resource_path('views/**/*.blade.php')) ?: [])
            ->merge(glob(resource_path('views/*/*/*.blade.php')) ?: [])
            ->merge(glob(resource_path('views/*.blade.php')) ?: []);
        $offenders = $files->unique()->filter(fn ($f) => preg_match('/\son[a-z]+="/', file_get_contents($f)))->values()->all();

        $this->assertNotEmpty($files);
        $this->assertSame([], $offenders);
    }

    public function test_security_txt(): void
    {
        $this->travelTo('2026-11-11 12:00');
        config(['poetsploeg.security_contact' => 'mailto:security@example.org', 'app.url' => 'https://example.org']);

        $this->get('/.well-known/security.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertSeeInOrder([
                'Contact: mailto:security@example.org',
                'Expires: 2027-05-01T00:00:00Z',
                'Preferred-Languages: nl, en',
                'Canonical: https://example.org/.well-known/security.txt',
            ]);

        config(['poetsploeg.security_contact' => null]);
        $this->get('/.well-known/security.txt')->assertNotFound();
    }
}

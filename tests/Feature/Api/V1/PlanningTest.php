<?php

namespace Tests\Feature\Api\V1;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanningTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00'));
        $this->token = User::factory()->create()->createToken('test', ['plan'])->plainTextToken;
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->withToken($this->token)->json($method, '/api/v1/'.$uri, $data);
    }

    public function test_today_splits_the_day_and_lists_what_needs_action(): void
    {
        $anna = Employee::factory()->create(['name' => 'Anna Jansen']);
        $now = Shift::factory()->at('2026-10-12', '11:00', '13:00')->create(['title' => 'Kantoor']);
        $now->assignments()->create(['employee_id' => $anna->id, 'source' => 'manual']);
        Shift::factory()->at('2026-10-12', '18:00', '20:00')->create(['title' => 'Avond']);
        Shift::factory()->at('2026-10-12', '07:00', '09:00')->create(['title' => 'Vroeg']);
        $bob = Employee::factory()->create(['name' => 'Bob']);
        Absence::factory()->create(['employee_id' => $bob->id, 'type' => 'verlof', 'status' => 'pending',
            'starts_at' => '2026-10-20 00:00', 'ends_at' => '2026-10-20 23:59']);

        $data = $this->api('GET', 'today')->assertOk()->json();

        $this->assertSame('2026-10-12', $data['date']);
        $this->assertTrue($data['is_today']);
        $this->assertSame(['Kantoor'], array_column($data['current'], 'title'));
        $this->assertSame(['Avond'], array_column($data['later'], 'title'));
        $this->assertSame(['Vroeg'], array_column($data['finished'], 'title'));
        $this->assertSame('Anna Jansen', $data['current'][0]['assignments'][0]['employee']['name']);
        $this->assertSame('AJ', $data['current'][0]['assignments'][0]['employee']['initials']);
        $this->assertSame(1, $data['counts']['working_now']);
        $this->assertSame(1, $data['counts']['pending_requests']);
        $this->assertSame('Bob', $data['pending_requests'][0]['employee']['name']);
        $this->assertContains('Avond', array_column($data['open_shifts'], 'title'));

        $other = $this->api('GET', 'today?date=2026-10-13')->assertOk()->json();
        $this->assertFalse($other['is_today']);
        $this->api('GET', 'today?date=morgen')->assertStatus(422);
    }

    public function test_open_shifts_lists_understaffed_shifts(): void
    {
        $full = Shift::factory()->at('2026-10-13', '08:00', '10:00')->create(['title' => 'Vol']);
        $full->assignments()->create(['employee_id' => Employee::factory()->create()->id, 'source' => 'manual']);
        Shift::factory()->at('2026-10-14', '08:00', '10:00')->create(['title' => 'Open', 'required_staff' => 2]);
        Shift::factory()->at('2026-11-30', '08:00', '10:00')->create(['title' => 'Ver weg']);

        $data = $this->api('GET', 'open-shifts')->assertOk()->json();

        $this->assertSame(['Open'], array_column($data['shifts'], 'title'));
        $this->assertSame(2, $data['slot_count']);
        $this->assertSame(2, $data['shifts'][0]['open_slots']);
        $this->api('GET', 'open-shifts?weeks=3')->assertStatus(422);
    }

    public function test_board_and_shift_endpoints_work_with_a_token(): void
    {
        $shift = Shift::factory()->at('2026-10-13', '08:00', '10:00')->create();
        $anna = Employee::factory()->create(['name' => 'Anna']);

        $this->api('GET', 'planning?from=2026-10-12&to=2026-10-18')->assertOk()->assertJsonCount(7, 'days');
        $this->api('GET', "shifts/{$shift->id}")->assertOk()->assertJsonPath('id', $shift->id);
        $this->api('GET', "shifts/{$shift->id}/candidates")->assertOk();

        $this->api('POST', "shifts/{$shift->id}/assignments", ['employee_id' => $anna->id, 'confirm' => true])
            ->assertOk()->assertJsonPath('assignments.0.name', 'Anna');
        $this->api('DELETE', "shifts/{$shift->id}/assignments/{$anna->id}")->assertOk()->assertJsonCount(0, 'assignments');

        $this->api('GET', 'search?q=Ann')->assertOk()->assertJsonPath('employees.0.name', 'Anna');
        $this->api('GET', "employees/{$anna->id}")->assertOk();
    }

    public function test_not_found_is_json(): void
    {
        $this->api('GET', 'shifts/999')->assertNotFound()->assertJsonStructure(['message']);
    }
}

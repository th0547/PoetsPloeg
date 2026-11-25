<?php

namespace Tests\Feature\Api\V1;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbsenceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anna;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-10 10:00');
        $this->token = User::factory()->create()->createToken('test', ['plan'])->plainTextToken;
        $this->anna = Employee::factory()->create(['name' => 'Anna']);
    }

    private function api(string $method, string $uri, array $data = [])
    {
        return $this->withToken($this->token)->json($method, '/api/v1/'.$uri, $data);
    }

    public function test_reporting_sick_frees_the_shifts_and_shows_them_for_replacement(): void
    {
        $shift = Shift::factory()->at('2026-10-12', '08:00', '10:00')->create(['title' => 'Kantoor']);
        $shift->assignments()->create(['employee_id' => $this->anna->id]);

        $response = $this->api('POST', 'absences', [
            'employee_id' => $this->anna->id, 'type' => 'ziek', 'days' => [['date' => '2026-10-12']],
        ])->assertCreated()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Anna is van 1 dienst(en) gehaald'))
            ->assertJsonPath('absences.0.employee.name', 'Anna')
            ->assertJsonPath('absences.0.status', 'approved');
        $this->assertSame(0, $shift->assignments()->count());

        $id = $response->json('absences.0.id');
        $this->api('GET', "absences/{$id}")->assertOk()
            ->assertJsonPath('linked', true)
            ->assertJsonPath('shifts.0.state', 'open')
            ->assertJsonPath('shifts.0.shift.title', 'Kantoor')
            ->assertJsonPath('shifts.0.shift.open_slots', 1);
    }

    public function test_leave_request_is_approved_once_every_shift_is_replaced(): void
    {
        $shift = Shift::factory()->at('2026-10-14', '08:00', '10:00')->create();
        $shift->assignments()->create(['employee_id' => $this->anna->id]);
        $bob = Employee::factory()->create(['name' => 'Bob']);

        $id = $this->api('POST', 'absences', [
            'employee_id' => $this->anna->id, 'type' => 'verlof', 'as_request' => true, 'days' => [['date' => '2026-10-14']],
        ])->assertCreated()->assertJsonPath('absences.0.status', 'pending')->json('absences.0.id');

        $this->api('GET', "absences/{$id}")->assertJsonPath('shifts.0.state', 'todo');
        $this->api('GET', 'absences?status=pending')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.shift_count', 1);

        $this->api('POST', "shifts/{$shift->id}/assignments", [
            'employee_id' => $bob->id, 'replace_employee_id' => $this->anna->id, 'confirm' => true,
        ])->assertOk()->assertJsonPath('approved_requests.0', 'Anna');

        $this->assertSame('approved', Absence::find($id)->status);
    }

    public function test_reject_and_withdraw(): void
    {
        $request = Absence::factory()->create(['employee_id' => $this->anna->id, 'status' => 'pending']);
        $approved = Absence::factory()->create(['employee_id' => $this->anna->id]);

        $this->api('POST', "absences/{$approved->id}/reject")->assertStatus(422);
        $this->api('POST', "absences/{$request->id}/reject")->assertOk()->assertJsonPath('absence.status', 'rejected');

        $this->api('DELETE', "absences/{$approved->id}")->assertOk();
        $this->assertSoftDeleted($approved);
    }

    public function test_validation_errors_are_json(): void
    {
        $this->api('POST', 'absences', ['employee_id' => $this->anna->id, 'type' => 'ziek', 'days' => []])
            ->assertStatus(422)->assertJsonValidationErrors('days');
        $this->api('POST', 'absences', ['employee_id' => $this->anna->id, 'type' => 'bestaat-niet', 'days' => [['date' => '2026-10-12']]])
            ->assertStatus(422)->assertJsonValidationErrors('type');
    }

    public function test_leave_types_and_employees_for_the_form(): void
    {
        Employee::factory()->create(['name' => 'Oud', 'active' => false]);

        $types = collect($this->api('GET', 'leave-types')->assertOk()->json('leave_types'))->keyBy('key');
        $this->assertTrue($types['verlof']['can_be_request']);
        $this->assertFalse($types['ziek']['can_be_request']);

        $this->api('GET', 'employees')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Anna');
    }
}

<?php

namespace Tests\Feature\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\ConflictChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anna;

    private Shift $first;

    private Shift $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 10:00');
        $this->actingAs(User::factory()->create());

        $this->anna = Employee::factory()->create(['name' => 'Anna']);
        $this->first = Shift::factory()->at('2026-10-20', '18:00', '20:00')->create();
        $this->second = Shift::factory()->at('2026-10-21', '18:00', '20:00')->create();
        foreach ([$this->first, $this->second] as $shift) {
            $shift->assignments()->create(['employee_id' => $this->anna->id, 'source' => 'fixed']);
        }
    }

    private function submit(string $type = 'verlof'): Absence
    {
        $this->post(route('absences.store'), [
            'employee_id' => $this->anna->id, 'type' => $type, 'as_request' => '1',
            'days' => [['date' => '2026-10-20'], ['date' => '2026-10-21']],
        ])->assertRedirect()->assertSessionHas('status', fn ($s) => str_contains($s, 'Verlofverzoek ingediend'));

        return Absence::first();
    }

    public function test_request_keeps_employee_scheduled_until_all_shifts_are_replaced(): void
    {
        $absence = $this->submit();
        $this->assertSame('pending', $absence->status);
        $this->assertSame(2, $absence->shifts()->count());
        $this->assertSame(1, $this->first->assignments()->where('employee_id', $this->anna->id)->count());

        [$bram, $cees] = Employee::factory()->count(2)->create();

        $this->postJson("/api/shifts/{$this->first->id}/assignments", [
            'employee_id' => $bram->id, 'replace_employee_id' => $this->anna->id, 'confirm' => true,
        ])->assertOk()->assertJsonPath('approved_requests', []);
        $this->assertSame([$bram->id], $this->first->assignments()->pluck('employee_id')->all());
        $this->assertSame('replacement', $this->first->assignments()->first()->source);
        $this->assertSame('pending', $absence->fresh()->status);

        $this->get(route('absences.show', $absence))->assertOk()->assertSee('✓ Vervangen')->assertSee('Zoek vervanger');

        $this->postJson("/api/shifts/{$this->second->id}/assignments", [
            'employee_id' => $cees->id, 'replace_employee_id' => $this->anna->id, 'confirm' => true,
        ])->assertOk()->assertJsonPath('approved_requests', ['Anna']);

        $absence->refresh();
        $this->assertSame('approved', $absence->status);
        $this->assertSame('verlof', $absence->type);
        $this->assertNotNull($absence->decided_at);
    }

    public function test_removing_without_replacement_does_not_approve(): void
    {
        $absence = $this->submit();

        $this->deleteJson("/api/shifts/{$this->first->id}/assignments/{$this->anna->id}")->assertOk();
        $this->deleteJson("/api/shifts/{$this->second->id}/assignments/{$this->anna->id}")->assertOk();

        $this->assertSame('pending', $absence->fresh()->status);
        $this->get(route('absences.show', $absence))->assertSee('Plek invullen');
    }

    public function test_pending_request_does_not_block_and_shows_info(): void
    {
        $absence = $this->submit();
        $other = Shift::factory()->at('2026-10-20', '08:00', '10:00')->create();

        $warnings = app(ConflictChecker::class)->forCandidate($other, $this->anna->id)['warnings'];
        $types = array_column($warnings, 'type');

        $this->assertNotContains('absent', $types);
        $this->assertContains('request', $types);

        $this->post(route('absences.reject', $absence))->assertRedirect();
        $this->assertSame('rejected', $absence->fresh()->status);
        $this->assertSame(1, $this->first->assignments()->where('employee_id', $this->anna->id)->count());
        $this->assertNotContains('request', array_column(app(ConflictChecker::class)->forCandidate($other, $this->anna->id)['warnings'], 'type'));
    }

    public function test_request_without_shifts_is_approved_immediately(): void
    {
        $this->post(route('absences.store'), [
            'employee_id' => $this->anna->id, 'type' => 'verlof', 'as_request' => '1',
            'days' => [['date' => '2026-11-02'], ['date' => '2026-11-03']],
        ])->assertSessionHas('status', fn ($s) => str_contains($s, 'direct goedgekeurd'));

        $this->assertSame('approved', Absence::first()->status);
    }

    public function test_request_keeps_its_leave_type_when_approved(): void
    {
        $absence = $this->submit('verhuisdag');
        $this->assertSame('verhuisdag', $absence->type);
        $this->get(route('absences.show', $absence))->assertOk()->assertSee('Verzoek verhuisdag');

        [$bram, $cees] = Employee::factory()->count(2)->create();
        foreach ([[$this->first, $bram], [$this->second, $cees]] as [$shift, $replacement]) {
            $this->postJson("/api/shifts/{$shift->id}/assignments", [
                'employee_id' => $replacement->id, 'replace_employee_id' => $this->anna->id, 'confirm' => true,
            ])->assertOk();
        }

        $absence->refresh();
        $this->assertSame('approved', $absence->status);
        $this->assertSame('verhuisdag', $absence->type);
    }

    public function test_sickness_is_never_a_request(): void
    {
        $this->post(route('absences.store'), [
            'employee_id' => $this->anna->id, 'type' => 'ziek', 'as_request' => '1',
            'days' => [['date' => '2026-10-20']],
        ])->assertRedirect();

        $this->assertSame('approved', Absence::first()->status);
        $this->assertSame(0, $this->first->assignments()->count());
    }

    public function test_withdrawn_request_no_longer_counts(): void
    {
        $absence = $this->submit();
        $other = Shift::factory()->at('2026-10-20', '08:00', '10:00')->create();

        $this->delete(route('absences.destroy', $absence))->assertRedirect();

        $this->assertSoftDeleted($absence);
        $this->assertNotContains('request', array_column(app(ConflictChecker::class)->forCandidate($other, $this->anna->id)['warnings'], 'type'));
    }
}

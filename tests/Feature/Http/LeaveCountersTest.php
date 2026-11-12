<?php

namespace Tests\Feature\Http;

use App\Models\Absence;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PolicyVersion;
use App\Models\Shift;
use App\Models\User;
use App\Models\VacationBalance;
use App\Services\LeaveCounters;
use App\Support\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeaveCountersTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-13 10:00');
        $this->anna = Employee::factory()->create(['name' => 'Anna', 'contract_hours_min' => 24, 'contract_hours_max' => 24]);
    }

    private function absence(string $type, float $hours, int $days, array $extra = []): Absence
    {
        return Absence::create($extra + [
            'employee_id' => $this->anna->id, 'type' => $type, 'starts_at' => '2026-03-02 00:00', 'ends_at' => '2026-03-02 23:59',
            'hours' => $hours, 'days' => $days, 'hours_source' => 'dienst',
        ]);
    }

    public function test_counters_per_type_with_pending_apart_and_care_grouped(): void
    {
        $this->absence('verlof', 16, 2);
        $this->absence('verlof', 8, 1, ['status' => 'pending']);
        $this->absence('ziek', 9, 2);
        $this->absence('huisarts', 1.5, 1);
        $this->absence('tandarts', 1, 1);
        $this->absence('verlof', 8, 1, ['status' => 'rejected']);
        $this->absence('niet_beschikbaar', 0, 0);
        $this->absence('verlof', 99, 9, ['starts_at' => '2025-12-30 00:00', 'ends_at' => '2025-12-30 23:59']);

        $c = app(LeaveCounters::class)->counters($this->anna, 2026);

        $this->assertSame(16.0, $c['Vakantie (snipperuren)']['approved_hours']);
        $this->assertSame(2, $c['Vakantie (snipperuren)']['approved_days']);
        $this->assertSame(8.0, $c['Vakantie (snipperuren)']['pending_hours']);
        $this->assertSame(9.0, $c['Ziek']['approved_hours']);
        $this->assertSame(2.5, $c[LeaveCounters::CARE_GROUP]['approved_hours']);
        $this->assertArrayNotHasKey('Huisarts', $c->all());
        $this->assertArrayNotHasKey('Niet beschikbaar', $c->all());

        // Zonder groeperen: de soorten apart.
        PolicyVersion::create(['valid_from' => '2026-01-01', 'settings' => ['privacy.group_care_types' => false], 'leave_types' => []]);
        Policy::forget();
        $this->assertArrayHasKey('Huisarts', app(LeaveCounters::class)->counters($this->anna, 2026)->all());
    }

    public function test_indicative_vacation_balance(): void
    {
        VacationBalance::create(['employee_id' => $this->anna->id, 'year' => 2026, 'opening_hours' => 20]);
        $this->absence('verlof', 16, 2);
        // Een week met 30 uur gewerkt: 6 uur boven het contract.
        foreach (['2026-03-09', '2026-03-10', '2026-03-11', '2026-03-12', '2026-03-13'] as $day) {
            Shift::factory()->at($day, '08:00', '14:00')->create()->assignments()->create(['employee_id' => $this->anna->id]);
        }

        $b = app(LeaveCounters::class)->balance($this->anna, 2026);

        $this->assertSame(10.0, $b['percent']);
        $this->assertSame(124.8, $b['contract_accrual']); // 24 × 52 × 10%
        $this->assertSame(6.0, $b['extra_hours']);
        $this->assertSame(0.6, $b['extra_accrual']);
        $this->assertSame(16.0, $b['taken']);
        $this->assertSame(129.4, $b['balance']); // 20 + 124,8 + 0,6 − 16
    }

    public function test_planner_sees_counters_and_sets_opening_balance(): void
    {
        $this->absence('verlof', 16, 2);
        $this->actingAs(User::factory()->create());

        $this->get(route('employees.edit', [$this->anna, 'jaar' => 2026]))->assertOk()
            ->assertSee('Verloftellers')->assertSee('Vakantiesaldo 2026')->assertSee('nog niet ingevuld');
        $this->put(route('employees.balance', $this->anna), ['year' => 2026, 'opening_hours' => 35.5])
            ->assertRedirect(route('employees.edit', [$this->anna, 'jaar' => 2026]));

        $this->assertSame(35.5, VacationBalance::sole()->opening_hours);
        $this->assertSame(1, AuditLog::where('action', 'balance.updated')->count());
    }

    public function test_employee_sees_own_counters_and_filtered_history(): void
    {
        $this->absence('verlof', 16, 2);
        $this->absence('verlof', 8, 1, ['status' => 'rejected', 'note' => 'Afgewezen-aanvraag']);
        $withdrawn = $this->absence('verlof', 4, 1, ['note' => 'Ingetrokken-aanvraag']);
        $withdrawn->delete();
        $other = Employee::factory()->create();
        Absence::create(['employee_id' => $other->id, 'type' => 'ziek', 'starts_at' => '2026-03-03 00:00', 'ends_at' => '2026-03-03 23:59', 'hours' => 77, 'days' => 1, 'note' => 'Van-een-ander']);
        $user = User::factory()->create(['role' => 'medewerker', 'employee_id' => $this->anna->id]);

        $this->actingAs($user)->get(route('my.absences', ['jaar' => 2026]))->assertOk()
            ->assertSee('Mijn tellers')->assertSee('16 u')->assertSee('Vakantiesaldo 2026')
            ->assertSee('Afgewezen-aanvraag')->assertSee('Ingetrokken-aanvraag')->assertDontSee('Van-een-ander')->assertDontSee('77 u');
        $this->get(route('my.absences', ['status' => 'rejected']))->assertSee('Afgewezen-aanvraag')->assertDontSee('Ingetrokken-aanvraag');
        $this->get(route('my.absences', ['status' => 'ingetrokken']))->assertSee('Ingetrokken-aanvraag')->assertDontSee('Afgewezen-aanvraag');

        PolicyVersion::create(['valid_from' => '2026-01-01', 'settings' => ['vacation.show_balance_employees' => false], 'leave_types' => []]);
        Policy::forget();
        $this->get(route('my.absences'))->assertDontSee('Vakantiesaldo');
    }

    public function test_csv_export_for_payroll(): void
    {
        $this->absence('verlof', 16, 2);
        Employee::factory()->create(['name' => '=HYPERLINK("x")']);
        $this->actingAs(User::factory()->create());

        $response = $this->get(route('absences.export', ['jaar' => 2026]))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="verlof-2026.csv"');
        $csv = $response->getContent();
        $this->assertStringContainsString('Anna;"Vakantie (snipperuren)";Vakantie;16;2;0', $csv);
        $this->assertSame(1, AuditLog::where('action', 'absences.exported')->count());

        $this->actingAs(User::factory()->create(['role' => 'medewerker', 'employee_id' => $this->anna->id]))
            ->get(route('absences.export'))->assertForbidden();
    }
}

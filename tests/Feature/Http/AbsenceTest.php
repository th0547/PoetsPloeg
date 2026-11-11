<?php

namespace Tests\Feature\Http;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\ConflictChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbsenceTest extends TestCase
{
    use RefreshDatabase;

    private Employee $anna;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-10 10:00');
        $this->actingAs(User::factory()->create());
        $this->anna = Employee::factory()->create(['name' => 'Anna']);
    }

    private function register(array $days, array $extra = [])
    {
        return $this->post(route('absences.store'), $extra + [
            'employee_id' => $this->anna->id,
            'type' => 'ziek',
            'days' => $days,
        ]);
    }

    public function test_separate_days_become_separate_periods_with_their_own_times(): void
    {
        $response = $this->register([
            ['date' => '2026-10-16', 'start_time' => '08:00', 'end_time' => '12:00'],
            ['date' => '2026-10-12', 'start_time' => '00:00', 'end_time' => '23:59'],
            ['date' => '2026-10-13', 'start_time' => '00:00', 'end_time' => '13:00'],
            ['date' => '2026-10-14', 'start_time' => '00:00', 'end_time' => '23:59'],
        ], ['type' => 'huisarts', 'note' => 'Controle']);

        $response->assertRedirect(route('absences.index', ['medewerker' => $this->anna->id, 'periode' => 'alles']))
            ->assertSessionHas('status', fn ($s) => str_contains($s, '3 losse periodes'));

        $periods = Absence::orderBy('starts_at')->get()
            ->map(fn (Absence $a) => $a->starts_at->format('d H:i').' - '.$a->ends_at->format('d H:i'))->all();
        // Maandag sluit aan op dinsdag (tot 13:00); woensdag begint daarna opnieuw; vrijdag staat los.
        $this->assertSame(['12 00:00 - 13 13:00', '14 00:00 - 14 23:59', '16 08:00 - 16 12:00'], $periods);
        $this->assertSame(['huisarts'], Absence::distinct()->pluck('type')->all());
        $this->assertSame(['Controle'], Absence::distinct()->pluck('note')->all());
    }

    public function test_each_period_frees_only_its_own_shifts(): void
    {
        $morning = Shift::factory()->at('2026-10-12', '08:00', '10:00')->create();
        $evening = Shift::factory()->at('2026-10-12', '18:00', '20:00')->create();
        $between = Shift::factory()->at('2026-10-13', '18:00', '20:00')->create();
        $later = Shift::factory()->at('2026-10-14', '18:00', '20:00')->create();
        foreach ([$morning, $evening, $between, $later] as $shift) {
            $shift->assignments()->create(['employee_id' => $this->anna->id]);
        }

        $this->register([
            ['date' => '2026-10-12', 'start_time' => '07:00', 'end_time' => '12:00'],
            ['date' => '2026-10-14'],
        ])->assertSessionHas('status', fn ($s) => str_contains($s, 'van 2 dienst(en) gehaald'));

        $this->assertSame(0, $morning->assignments()->count());
        $this->assertSame(1, $evening->assignments()->count());
        $this->assertSame(1, $between->assignments()->count());
        $this->assertSame(0, $later->assignments()->count());
    }

    public function test_days_are_required_and_end_must_be_after_start(): void
    {
        $this->register([])->assertSessionHasErrors('days');
        $this->register([['date' => '2026-10-12', 'start_time' => '14:00', 'end_time' => '09:00']])
            ->assertSessionHasErrors('days.0.end_time');
        $this->register([['date' => '2026-10-12'], ['date' => '2026-10-12']])->assertSessionHasErrors('days.0.date');
        $this->register([['date' => '2026-10-12']], ['type' => 'verzoek'])->assertSessionHasErrors('type');

        $this->assertSame(0, Absence::count());
    }

    public function test_withdrawn_absence_is_kept_in_history_but_no_longer_counts(): void
    {
        $absence = Absence::factory()->create(['employee_id' => $this->anna->id, 'type' => 'verhuisdag']);
        $shift = Shift::factory()->at('2026-10-13', '18:00', '20:00')->create();
        $absent = fn () => in_array('absent', array_column(app(ConflictChecker::class)->forCandidate($shift, $this->anna->id)['warnings'], 'type'), true);
        $this->assertTrue($absent());

        $this->delete(route('absences.destroy', $absence))
            ->assertRedirect(route('absences.index', ['medewerker' => $this->anna->id, 'status' => 'ingetrokken', 'periode' => 'alles']));

        $this->assertSoftDeleted($absence);
        $this->assertFalse($absent());
        $this->get(route('absences.index', ['periode' => 'alles']))->assertOk()->assertDontSee('Verhuisdag</span>', false);
        $this->get(route('absences.index', ['status' => 'ingetrokken', 'periode' => 'alles']))
            ->assertOk()->assertSee('Verhuisdag</span>', false)->assertSee('ingetrokken op');
        $this->get(route('absences.show', $absence))->assertNotFound();
    }

    public function test_history_can_be_filtered_by_employee_type_and_period(): void
    {
        $bram = Employee::factory()->create(['name' => 'Bram', 'active' => false]);
        Absence::factory()->create(['employee_id' => $this->anna->id, 'type' => 'huisarts', 'starts_at' => '2024-03-04 09:00', 'ends_at' => '2024-03-04 11:00']);
        Absence::factory()->create(['employee_id' => $this->anna->id, 'type' => 'ziek', 'starts_at' => '2026-10-20 00:00', 'ends_at' => '2026-10-21 23:59']);
        Absence::factory()->create(['employee_id' => $bram->id, 'type' => 'tandarts', 'starts_at' => '2025-01-06 09:00', 'ends_at' => '2025-01-06 10:00']);

        $chip = fn (string $label) => $label.'</span>';

        // Standaard: alleen huidig en gepland.
        $this->get(route('absences.index'))->assertOk()
            ->assertSee($chip('Ziek'), false)->assertDontSee($chip('Huisarts'), false)->assertDontSee($chip('Tandarts'), false);
        $this->get(route('absences.index', ['periode' => 'verleden']))
            ->assertSee($chip('Huisarts'), false)->assertSee($chip('Tandarts'), false)->assertDontSee($chip('Ziek'), false);
        $this->get(route('absences.index', ['verleden' => 1]))->assertSee($chip('Huisarts'), false);
        $this->get(route('absences.index', ['periode' => 'alles', 'medewerker' => $this->anna->id]))
            ->assertSee($chip('Huisarts'), false)->assertSee($chip('Ziek'), false)->assertDontSee($chip('Tandarts'), false);
        $this->get(route('absences.index', ['periode' => 'alles', 'soort' => 'tandarts']))
            ->assertSee($chip('Tandarts'), false)->assertDontSee($chip('Huisarts'), false)->assertSee('Bram (inactief)');
    }

    public function test_employee_page_shows_the_complete_history(): void
    {
        Absence::factory()->create(['employee_id' => $this->anna->id, 'type' => 'fysiotherapie', 'starts_at' => '2023-05-08 09:00', 'ends_at' => '2023-05-08 10:30']);
        Absence::factory()->create(['employee_id' => $this->anna->id, 'type' => 'verlof', 'starts_at' => '2026-12-21 00:00', 'ends_at' => '2026-12-31 23:59']);

        $this->get(route('employees.edit', $this->anna))->assertOk()
            ->assertSeeInOrder(['Nu en gepland', 'Verlof', '21 dec. 2026', 'Geschiedenis (1)', 'Fysiotherapie', '8 mei 2023', '09:00–10:30']);
    }
}

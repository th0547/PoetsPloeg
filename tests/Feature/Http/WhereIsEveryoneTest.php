<?php

namespace Tests\Feature\Http;

use App\Models\Absence;
use App\Models\AuditLog;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\ReplacementFinder;
use App\Support\NowStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Waar is iemand nu en hoe ziet zijn dag eruit": de status per medewerker, het medewerkerpaneel,
 * de zoekbalk, de pagina Vandaag en het vervangscherm.
 */
class WhereIsEveryoneTest extends TestCase
{
    use RefreshDatabase;

    private Building $office;

    private Employee $anna;

    private Employee $bram;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-12 10:00'); // maandag
        $this->actingAs(User::factory()->create(['role' => 'teamleider']));

        $this->office = Building::factory()->create(['name' => 'Kantoor De Linde', 'city' => 'Zwolle']);
        $this->anna = Employee::factory()->create(['name' => 'Anna Jansen', 'phone' => '0611111111', 'contract_hours_max' => 20]);
        $this->bram = Employee::factory()->create(['name' => 'Bram de Boer']);
    }

    private function shift(string $start, string $end, ?Employee $employee = null, string $date = '2026-10-12'): Shift
    {
        $shift = Shift::factory()->at($date, $start, $end)->create(['building_id' => $this->office->id, 'title' => 'Dagelijkse schoonmaak']);
        if ($employee) {
            $shift->assignments()->create(['employee_id' => $employee->id]);
        }

        return $shift;
    }

    public function test_status_tells_where_someone_is_now(): void
    {
        $this->shift('09:00', '11:00', $this->anna);
        $this->shift('14:00', '16:00', $this->bram);
        $done = Employee::factory()->create();
        $this->shift('06:00', '08:00', $done);
        $free = Employee::factory()->create();
        $sick = Employee::factory()->create();
        Absence::factory()->create(['employee_id' => $sick->id, 'type' => 'ziek', 'starts_at' => '2026-10-12 00:00', 'ends_at' => '2026-10-14 23:59']);

        $status = NowStatus::forEmployees([$this->anna->id, $this->bram->id, $done->id, $free->id, $sick->id]);

        $this->assertSame(['working', 'Nu bij Kantoor De Linde tot 11:00'], [$status[$this->anna->id]['state'], $status[$this->anna->id]['label']]);
        $this->assertSame(['later', 'Straks 14:00 bij Kantoor De Linde'], [$status[$this->bram->id]['state'], $status[$this->bram->id]['label']]);
        $this->assertSame('done', $status[$done->id]['state']);
        $this->assertSame('free', $status[$free->id]['state']);
        $this->assertSame('absent', $status[$sick->id]['state']);
        $this->assertStringContainsString('t/m', $status[$sick->id]['label']);
        $this->assertSame('09:00', $status[$this->anna->id]['today'][0]['start']);
    }

    public function test_employee_panel_shows_day_week_and_contact_and_logs_the_view(): void
    {
        $this->shift('09:00', '11:00', $this->anna);
        $shared = $this->shift('18:00', '20:00', $this->anna, '2026-10-14');
        $shared->assignments()->create(['employee_id' => $this->bram->id]);

        $data = $this->getJson("/api/employees/{$this->anna->id}")->assertOk()->json();

        $this->assertSame('AJ', $data['initials']);
        $this->assertSame('working', $data['status']['state']);
        $this->assertSame('0611111111', $data['phone']);
        $this->assertSame(4.0, (float) $data['week']['hours']);
        $this->assertSame(42, $data['week']['number']);
        $this->assertCount(7, $data['days']);
        $this->assertTrue($data['days'][0]['today']);
        $this->assertSame('now', $data['days'][0]['shifts'][0]['state']);
        $this->assertSame(['Bram de Boer'], $data['days'][2]['shifts'][0]['colleagues']);
        $this->assertTrue(AuditLog::where('subject_type', 'Employee')->where('subject_id', $this->anna->id)->exists());

        // Een andere week bekijken.
        $next = $this->getJson("/api/employees/{$this->anna->id}?datum=2026-10-19")->assertOk()->json();
        $this->assertSame(43, $next['week']['number']);
        $this->assertFalse($next['week']['current']);

        $this->get(route('employees.show', $this->anna))->assertOk()
            ->assertSee('Anna Jansen')->assertSee('Nu bij Kantoor De Linde tot 11:00')->assertSee('Week 42');
    }

    public function test_search_finds_employees_with_status_and_buildings_with_who_is_there(): void
    {
        $this->shift('09:00', '11:00', $this->anna);
        $this->shift('14:00', '16:00', $this->bram);

        $byName = $this->getJson('/api/search?q=ann')->assertOk()->json();
        $this->assertSame(['Anna Jansen'], array_column($byName['employees'], 'name'));
        $this->assertSame('Nu bij Kantoor De Linde tot 11:00', $byName['employees'][0]['label']);
        $this->assertArrayNotHasKey('phone', $byName['employees'][0]);

        $byBuilding = $this->getJson('/api/search?q=linde')->assertOk()->json();
        $this->assertSame('Kantoor De Linde', $byBuilding['buildings'][0]['name']);
        $this->assertSame('Nu aanwezig: Anna Jansen', $byBuilding['buildings'][0]['label']);

        // Zonder zoekterm: wie vandaag werkt, wie nu bezig is bovenaan.
        $today = $this->getJson('/api/search')->assertOk()->json();
        $this->assertSame(['Anna Jansen', 'Bram de Boer'], array_column($today['employees'], 'name'));
        $this->assertSame([], $today['buildings']);

        // Jokertekens in de zoekterm zoeken letterlijk.
        $this->assertSame([], $this->getJson('/api/search?q=%25')->assertOk()->json('employees'));
    }

    public function test_today_page_splits_the_day_into_now_later_and_done(): void
    {
        $this->shift('06:00', '08:00', $this->bram);
        $this->shift('09:00', '11:00', $this->anna);
        $this->shift('14:00', '16:00');

        $this->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Nu aan het werk', '09:00–11:00', 'Anna Jansen', 'Straks', '14:00–16:00', '1 open', 'Afgerond', '06:00–08:00'])
            ->assertSee('Open plekken (14 dagen)');

        // Een andere dag is één lijst.
        $this->get(route('dashboard', ['datum' => '2026-10-13']))->assertOk()->assertSee('Dagoverzicht')->assertDontSee('Nu aan het werk');
    }

    public function test_candidates_carry_fit_and_what_they_already_do_that_day(): void
    {
        $open = $this->shift('14:00', '16:00');
        $this->anna->buildings()->attach($this->office);
        $morning = Employee::factory()->create(['name' => 'Dirk']);
        $this->shift('09:00', '11:00', $morning);
        $busy = Employee::factory()->create(['name' => 'Cees']);
        $this->shift('15:00', '17:00', $busy);

        $result = app(ReplacementFinder::class)->candidates($open);
        $candidates = collect($result['candidates']);

        // Anna kent het pand en heeft niets anders: de beste keuze.
        $this->assertSame('best', $candidates->firstWhere('name', 'Anna Jansen')['fit']);
        // Dirk werkte die ochtend al: dat staat erbij.
        $this->assertSame([['start' => '09:00', 'end' => '11:00', 'building' => 'Kantoor De Linde']],
            array_map(fn ($d) => collect($d)->only(['start', 'end', 'building'])->all(), $candidates->firstWhere('name', 'Dirk')['day_shifts']));
        $this->assertSame('excluded', collect($result['excluded'])->firstWhere('name', 'Cees')['fit']);
        // "Kent pand niet" is een minpunt, geen afkeuring.
        $bram = $candidates->firstWhere('name', 'Bram de Boer');
        $this->assertTrue(collect($bram['reasons'])->firstWhere('text', 'Kent pand niet')['soft']);
        $this->assertSame('good', $bram['fit']);
    }

    public function test_reporting_sick_gives_one_screen_with_the_shifts_to_replace(): void
    {
        $today = $this->shift('14:00', '16:00', $this->anna);
        $tomorrow = $this->shift('09:00', '11:00', $this->anna, '2026-10-13');
        $unrelated = Shift::factory()->at('2026-10-13', '18:00', '20:00')->create(['title' => 'Andere open dienst']);

        $this->post(route('absences.store'), [
            'employee_id' => $this->anna->id, 'type' => 'ziek',
            'days' => [['date' => '2026-10-12'], ['date' => '2026-10-13']],
        ])->assertSessionHasNoErrors();
        $absence = Absence::firstOrFail();

        // De vrijgemaakte diensten zijn vastgelegd: het scherm toont precies die, niet elke open dienst.
        $this->assertEqualsCanonicalizing([$today->id, $tomorrow->id], $absence->shifts()->pluck('shifts.id')->all());
        $this->get(route('absences.show', $absence))->assertOk()
            ->assertSee('Diensten van Anna Jansen in deze periode')
            ->assertSeeText('0 van 2 ingevuld')
            ->assertSee('Zoek vervanger')
            ->assertDontSee('Andere open dienst');

        // Eén dienst invullen: de voortgang schuift op.
        $this->postJson("/api/shifts/{$today->id}/assignments", ['employee_id' => $this->bram->id, 'source' => 'replacement'])->assertOk();
        $this->get(route('absences.show', $absence))->assertSeeText('1 van 2 ingevuld')->assertSee('✓ Vervangen');
        $this->assertSame(1, $unrelated->fresh()->openSlots());
    }

    public function test_one_off_shift_from_the_board_is_prefilled_and_returns_to_the_board(): void
    {
        $this->get(route('shifts.create', ['datum' => '2026-10-15', 'medewerker' => $this->anna->id, 'pand' => $this->office->id, 'terug' => 'planning']))
            ->assertOk()->assertSee('value="2026-10-15"', false)->assertSee('name="back" value="planning"', false);

        $this->post(route('shifts.store'), [
            'building_id' => $this->office->id, 'title' => 'Oplevering', 'date' => '2026-10-15',
            'start_time' => '09:00', 'end_time' => '12:00', 'required_staff' => 1,
            'employees' => [$this->anna->id], 'back' => 'planning',
        ])->assertRedirect(route('planning', ['view' => 'employee', 'range' => 'week', 'date' => '2026-10-15']));

        $this->assertSame([$this->anna->id], Shift::where('title', 'Oplevering')->firstOrFail()->assignments()->pluck('employee_id')->all());
    }

    public function test_employees_and_buildings_lists_show_who_is_where(): void
    {
        $this->shift('09:00', '11:00', $this->anna);

        $this->get(route('employees.index'))->assertOk()->assertSee('Nu bij Kantoor De Linde tot 11:00')->assertSee('2 / 20 u');
        $this->get(route('buildings.index'))->assertOk()->assertSee('Nu: Anna Jansen');
    }

    public function test_new_building_page_shows_the_steps_that_are_left(): void
    {
        $new = Building::factory()->create(['name' => 'Nieuw pand']);

        $this->get(route('buildings.show', $new))->assertOk()
            ->assertSeeInOrder(['1. Pandgegevens', '2. Vaste taken', 'Eerste taak toevoegen', '3. Vaste bezetting']);
    }
}

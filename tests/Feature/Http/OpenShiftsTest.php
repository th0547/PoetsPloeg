<?php

namespace Tests\Feature\Http;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenShiftsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-13 10:00');
        $this->actingAs(User::factory()->create());
    }

    public function test_lists_open_shifts_per_day_and_hides_full_cancelled_and_past(): void
    {
        $a = Building::factory()->create(['name' => 'Kantoor A']);
        $b = Building::factory()->create(['name' => 'Kantoor B']);
        $open = Shift::factory()->at('2026-10-14', '18:00', '20:00')->create(['building_id' => $a->id, 'title' => 'Open taak', 'required_staff' => 2]);
        $open->assignments()->create(['employee_id' => Employee::factory()->create(['name' => 'Anna'])->id]);
        $full = Shift::factory()->at('2026-10-14', '08:00', '10:00')->create(['building_id' => $a->id, 'title' => 'Volle taak']);
        $full->assignments()->create(['employee_id' => Employee::factory()->create()->id]);
        Shift::factory()->at('2026-10-15', '08:00', '10:00')->create(['building_id' => $b->id, 'title' => 'Geannuleerd', 'cancelled_at' => now()]);
        Shift::factory()->at('2026-10-12', '08:00', '10:00')->create(['building_id' => $b->id, 'title' => 'Verleden']);
        Shift::factory()->at('2026-10-16', '08:00', '10:00')->create(['building_id' => $b->id, 'title' => 'Andere dag']);
        Shift::factory()->at('2026-11-20', '08:00', '10:00')->create(['building_id' => $b->id, 'title' => 'Ver weg']);

        $this->get(route('open-shifts'))->assertOk()
            ->assertSeeInOrder(['Woensdag 14 oktober', 'Open taak', 'Anna', '1 van 2 open', 'Vrijdag 16 oktober', 'Andere dag'])
            ->assertDontSee('Volle taak')->assertDontSee('Geannuleerd')->assertDontSee('Verleden')->assertDontSee('Ver weg')
            ->assertSee('2 diensten met samen 2 open plekken');

        $this->get(route('open-shifts', ['pand' => $b->id]))->assertOk()->assertSee('Andere dag')->assertDontSee('Open taak');
        $this->get(route('open-shifts', ['weken' => 8]))->assertOk()->assertSee('Ver weg');
    }

    public function test_menu_badge_matches_dashboard_count_including_shift_in_progress(): void
    {
        Shift::factory()->at('2026-10-13', '09:00', '11:00')->create(); // bezig om 10:00
        Shift::factory()->at('2026-10-20', '09:00', '11:00')->create(['required_staff' => 2]);

        $this->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['Open diensten', '3'])
            ->assertSeeInOrder(['Open plekken (14 dagen)', '3']);
    }

    public function test_menu_shows_open_slot_count(): void
    {
        Shift::factory()->at('2026-10-14', '18:00', '20:00')->create(['required_staff' => 3]);

        $this->get(route('dashboard'))->assertOk()->assertSee('Open plekken komende 14 dagen', false)->assertSeeInOrder(['Open diensten', '3']);
    }
}

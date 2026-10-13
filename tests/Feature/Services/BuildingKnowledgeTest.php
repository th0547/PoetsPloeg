<?php

namespace Tests\Feature\Services;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\BuildingKnowledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildingKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_knows_building_after_two_worked_shifts(): void
    {
        $this->travelTo('2026-10-13 21:00');
        $building = Building::factory()->create();
        [$twice, $once, $future] = Employee::factory()->count(3)->create();

        $make = fn (string $date, Employee $e, array $extra = []) => Shift::factory()->at($date, '18:00', '20:00')
            ->create(['building_id' => $building->id] + $extra)->assignments()->create(['employee_id' => $e->id]);

        $make('2026-10-12', $twice);
        $make('2026-10-13', $twice);
        $make('2026-10-12', $once);
        $make('2026-10-11', $once, ['cancelled_at' => now()]);
        $make('2026-10-12', $future);
        $make('2026-10-20', $future);

        $this->assertSame(1, app(BuildingKnowledge::class)->learn());
        $this->assertSame([$twice->id], $building->employees()->pluck('employees.id')->all());
        $this->assertSame(0, app(BuildingKnowledge::class)->learn());
    }
}

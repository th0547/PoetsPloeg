<?php

namespace Tests\Feature\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AbsenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbsenceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_unassigns_employee_from_future_shifts_during_absence(): void
    {
        $this->travelTo('2026-10-12 12:00');
        $employee = Employee::factory()->create();
        $colleague = Employee::factory()->create();

        $past = Shift::factory()->at('2026-10-12', '08:00', '10:00')->create();
        $inside = Shift::factory()->at('2026-10-13', '18:00', '20:00')->create(['required_staff' => 2]);
        $after = Shift::factory()->at('2026-10-20', '18:00', '20:00')->create();
        foreach ([$past, $inside, $after] as $shift) {
            $shift->assignments()->create(['employee_id' => $employee->id]);
        }
        $inside->assignments()->create(['employee_id' => $colleague->id]);

        $absence = Absence::factory()->create(['employee_id' => $employee->id]);
        $affected = app(AbsenceService::class)->apply($absence);

        $this->assertSame([$inside->id], $affected->pluck('id')->all());
        $this->assertSame([$colleague->id], $inside->fresh()->assignments->pluck('employee_id')->all());
        $this->assertTrue($inside->fresh()->is_modified);
        $this->assertSame(1, $inside->fresh()->openSlots());
        $this->assertSame(1, $past->assignments()->count());
        $this->assertSame(1, $after->assignments()->count());
    }
}

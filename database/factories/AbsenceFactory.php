<?php

namespace Database\Factories;

use App\Models\Absence;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Absence>
 */
class AbsenceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => 'verlof',
            'starts_at' => '2026-10-12 00:00:00',
            'ends_at' => '2026-10-16 23:59:59',
        ];
    }
}

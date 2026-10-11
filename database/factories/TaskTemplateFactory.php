<?php

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\TaskTemplate>
 */
class TaskTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'name' => 'Dagelijkse schoonmaak',
            'start_time' => '18:00',
            'end_time' => '20:00',
            'required_staff' => 1,
            'recurrence' => 'weekly',
            'repeat_every' => 1,
            'weekdays' => [1, 3, 5],
            'valid_from' => '2026-01-05',
            'skip_holidays' => true,
            'active' => true,
        ];
    }
}

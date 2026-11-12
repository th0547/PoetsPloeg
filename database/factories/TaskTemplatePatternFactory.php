<?php

namespace Database\Factories;

use App\Models\TaskTemplatePattern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskTemplatePattern>
 */
class TaskTemplatePatternFactory extends Factory
{
    public function definition(): array
    {
        return [
            'position' => 0,
            'recurrence' => 'weekly',
            'repeat_every' => 1,
            'weekdays' => [1, 3, 5],
        ];
    }
}

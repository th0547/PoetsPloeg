<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Skill>
 */
class SkillFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->unique()->word()];
    }
}

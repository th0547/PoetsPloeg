<?php

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Kantoor '.fake()->unique()->lastName(),
            'address' => fake()->streetAddress(),
            'postal_code' => fake()->postcode(),
            'city' => fake()->city(),
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->phoneNumber(),
            'color' => fake()->hexColor(),
            'active' => true,
        ];
    }
}

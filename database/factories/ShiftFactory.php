<?php

namespace Database\Factories;

use App\Models\Building;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'building_id' => Building::factory(),
            'date' => '2026-10-12',
            'starts_at' => '2026-10-12 18:00:00',
            'ends_at' => '2026-10-12 20:00:00',
            'required_staff' => 1,
            'title' => 'Schoonmaak',
        ];
    }

    public function at(string $date, string $start, string $end): static
    {
        return $this->state([
            'date' => $date,
            'starts_at' => "$date $start:00",
            'ends_at' => "$date $end:00",
        ]);
    }
}

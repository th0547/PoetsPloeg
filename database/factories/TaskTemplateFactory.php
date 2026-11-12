<?php

namespace Database\Factories;

use App\Models\Building;
use App\Models\TaskTemplate;
use App\Models\TaskTemplatePattern;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Zonder patroon-state krijgt een taak één patroon: elke week op ma, wo en vr.
 * Met weekly(), monthlyNth(), monthlyDay() of rrule() bepaal je de patronen zelf (meerdere kan).
 *
 * @extends Factory<TaskTemplate>
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
            'valid_from' => '2026-01-05',
            'skip_holidays' => true,
            'active' => true,
        ];
    }

    public function configure(): static
    {
        // Patronen uit een state (has) worden vóór deze callback aangemaakt.
        return $this->afterCreating(function (TaskTemplate $template) {
            if (! $template->patterns()->exists()) {
                $template->patterns()->create(['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1, 3, 5]]);
            }
            $template->unsetRelation('patterns');
        });
    }

    /** Patroon toevoegen met eigen kolommen (zie TaskTemplatePattern). */
    public function pattern(array $attributes): static
    {
        return $this->has(TaskTemplatePattern::factory()->state($attributes), 'patterns');
    }

    public function weekly(array $weekdays, int $every = 1, array $extra = []): static
    {
        return $this->pattern(['recurrence' => 'weekly', 'repeat_every' => $every, 'weekdays' => $weekdays] + $extra);
    }

    public function monthlyNth(int $nth, int $weekday, int $every = 1, array $extra = []): static
    {
        return $this->pattern(['recurrence' => 'monthly_nth_weekday', 'repeat_every' => $every, 'weekdays' => null, 'nth' => $nth, 'weekday' => $weekday] + $extra);
    }

    public function monthlyDay(int $day, int $every = 1, array $extra = []): static
    {
        return $this->pattern(['recurrence' => 'monthly_day', 'repeat_every' => $every, 'weekdays' => null, 'day_of_month' => $day] + $extra);
    }

    public function rrule(string $code, array $extra = []): static
    {
        return $this->pattern(['recurrence' => 'rrule', 'weekdays' => null, 'rrule' => $code] + $extra);
    }
}

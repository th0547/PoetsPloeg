<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskTemplate extends Model
{
    use HasFactory;

    public const RECURRENCES = [
        'weekly' => 'Wekelijks',
        'monthly_nth_weekday' => 'Maandelijks op een weekdag',
        'monthly_day' => 'Maandelijks op een vaste datum',
    ];

    protected $fillable = [
        'building_id', 'name', 'description', 'start_time', 'end_time', 'required_staff', 'recurrence',
        'repeat_every', 'weekdays', 'nth', 'weekday', 'day_of_month', 'valid_from', 'valid_until',
        'skip_holidays', 'active',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'skip_holidays' => 'boolean',
            'active' => 'boolean',
            'required_staff' => 'integer',
            'repeat_every' => 'integer',
            'nth' => 'integer',
            'weekday' => 'integer',
            'day_of_month' => 'integer',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    /** Vaste bezetting. */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class)->withPivot('position')->orderByPivot('position');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** Leesbare omschrijving van de herhaling, bijv. "Om de week op ma, wo" of "Elk kwartaal op de eerste maandag". */
    public function recurrenceLabel(): string
    {
        $days = config('poetsploeg.weekdays_short');
        $every = max(1, (int) $this->repeat_every);
        $weeks = match ($every) {
            1 => 'Elke week',
            2 => 'Om de week',
            default => "Elke {$every} weken",
        };
        $months = match ($every) {
            1 => 'Elke maand',
            2 => 'Om de maand',
            3 => 'Elk kwartaal',
            6 => 'Elk half jaar',
            12 => 'Elk jaar',
            default => "Elke {$every} maanden",
        };
        $nth = [1 => 'eerste', 2 => 'tweede', 3 => 'derde', 4 => 'vierde', -1 => 'laatste'][$this->nth] ?? $this->nth.'e';

        return match ($this->recurrence) {
            'weekly' => $weeks.' op '.collect($this->weekdays ?? [])->sort()->map(fn ($d) => $days[$d] ?? $d)->implode(', '),
            'monthly_nth_weekday' => $months.' op de '.$nth.' '.(config('poetsploeg.weekdays')[$this->weekday] ?? ''),
            'monthly_day' => $months.' op de '.$this->day_of_month.'e',
            default => $this->recurrence,
        };
    }
}

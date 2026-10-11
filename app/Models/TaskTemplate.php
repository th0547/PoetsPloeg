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
        'weekly' => 'Wekelijks (of elke N weken)',
        'monthly_nth_weekday' => 'Maandelijks op de N-de weekdag',
        'monthly_day' => 'Maandelijks op vaste dag',
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

    /** Leesbare omschrijving van de herhaling, bijv. "Elke 2 weken op ma, wo". */
    public function recurrenceLabel(): string
    {
        $days = config('novon.weekdays_short');
        $every = max(1, (int) $this->repeat_every);

        return match ($this->recurrence) {
            'weekly' => ($every === 1 ? 'Elke week' : "Elke {$every} weken").' op '
                .collect($this->weekdays ?? [])->sort()->map(fn ($d) => $days[$d] ?? $d)->implode(', '),
            'monthly_nth_weekday' => ($every === 1 ? 'Elke maand' : "Elke {$every} maanden").' op de '
                .($this->nth === -1 ? 'laatste' : $this->nth.'e').' '.(config('novon.weekdays')[$this->weekday] ?? ''),
            'monthly_day' => ($every === 1 ? 'Elke maand' : "Elke {$every} maanden").' op dag '.$this->day_of_month,
            default => $this->recurrence,
        };
    }
}

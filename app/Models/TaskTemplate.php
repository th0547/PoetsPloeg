<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class TaskTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id', 'name', 'description', 'start_time', 'end_time', 'required_staff',
        'valid_from', 'valid_until', 'skip_holidays', 'active',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_until' => 'date',
            'skip_holidays' => 'boolean',
            'active' => 'boolean',
            'required_staff' => 'integer',
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

    /** Herhalingspatronen; een taak heeft er minimaal één. */
    public function patterns(): HasMany
    {
        return $this->hasMany(TaskTemplatePattern::class)->orderBy('position')->orderBy('id');
    }

    /** Leesbare omschrijving van de herhaling, bijv. "Om de week op do; om de week op ma (07:00–09:00)". */
    public function recurrenceLabel(): string
    {
        return $this->patterns
            ->map(fn (TaskTemplatePattern $p) => $p->label().($p->hasOwnTimes() ? ' ('.substr($p->start_time, 0, 5).'–'.substr($p->end_time, 0, 5).')' : ''))
            ->implode('; ');
    }

    /** Tijden voor in lijsten: "18:00–20:00", of met "e.a." als patronen eigen tijden hebben. */
    public function timesLabel(): string
    {
        $label = substr($this->start_time, 0, 5).'–'.substr($this->end_time, 0, 5);

        return $this->patterns->contains(fn (TaskTemplatePattern $p) => $p->hasOwnTimes()) ? $label.' e.a.' : $label;
    }

    /** Valt de taak op deze dag (binnen de geldigheidsperiode en in een van de patronen)? */
    public function occursOn(CarbonImmutable $day): bool
    {
        return $this->patternsOn($day)->isNotEmpty();
    }

    /** De patronen die op deze dag vallen. */
    public function patternsOn(CarbonImmutable $day): Collection
    {
        $day = $day->startOfDay();
        if ($day->lt(CarbonImmutable::instance($this->valid_from)->startOfDay())) {
            return collect();
        }
        if ($this->valid_until && $day->gt(CarbonImmutable::instance($this->valid_until)->startOfDay())) {
            return collect();
        }

        return $this->patterns->filter(fn (TaskTemplatePattern $p) => $p->occursOn($day, $this))->values();
    }
}

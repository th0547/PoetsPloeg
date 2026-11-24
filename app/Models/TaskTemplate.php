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

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** Herhalingspatronen; een taak heeft er minimaal één. */
    public function patterns(): HasMany
    {
        return $this->hasMany(TaskTemplatePattern::class)->orderBy('position')->orderBy('id');
    }

    /** Leesbare omschrijving met tijden, bijv. "Elke week op ma, wo, vr 10:00–12:00; Elke week op di, do 12:00–13:00". */
    public function recurrenceLabel(): string
    {
        return $this->patterns
            ->map(fn (TaskTemplatePattern $p) => $p->label().' '.$p->timesLabel($this))
            ->implode('; ');
    }

    /** Tijden voor in lijsten: "18:00–20:00", of met "e.a." als de patronen verschillende tijden hebben. */
    public function timesLabel(): string
    {
        $labels = $this->patterns->map(fn (TaskTemplatePattern $p) => $p->timesLabel($this))->unique()->values();
        if ($labels->isEmpty()) {
            return substr((string) $this->start_time, 0, 5).'–'.substr((string) $this->end_time, 0, 5);
        }

        return $labels->count() > 1 ? $labels->first().' e.a.' : $labels->first();
    }

    /**
     * Iedereen die vast op een van de patronen staat (relatie patterns.employees geladen).
     *
     * @return Collection<int, Employee>
     */
    public function staff(): Collection
    {
        return $this->patterns->flatMap(fn (TaskTemplatePattern $p) => $p->employees)->unique('id')->values();
    }

    /**
     * De vaste krachten voor deze dienst: die van het patroon van de dienst. Is dat patroon niet meer bekend
     * (oude dienst), dan het patroon dat op die dag en begintijd valt, en anders iedereen van de taak.
     *
     * @return Collection<int, Employee>
     */
    public function staffFor(Shift $shift): Collection
    {
        $this->loadMissing('patterns.employees');
        $pattern = $this->patterns->firstWhere('id', $shift->task_template_pattern_id)
            ?? $this->patternsOn(CarbonImmutable::instance($shift->starts_at))
                ->first(fn (TaskTemplatePattern $p) => substr($p->startTime($this), 0, 5) === $shift->starts_at->format('H:i'));

        return $pattern ? $pattern->employees : $this->staff();
    }

    /** De tijden van de taak volgen het eerste patroon (voor de volgorde in lijsten). */
    public function syncTimesFromPatterns(): void
    {
        $first = $this->patterns()->first();
        if ($first && $first->hasOwnTimes()) {
            $this->forceFill(['start_time' => $first->start_time, 'end_time' => $first->end_time])->saveQuietly();
        }
    }

    /** Valt de taak op deze dag (binnen de geldigheidsperiode en in een van de patronen)? */
    public function occursOn(CarbonImmutable $day): bool
    {
        return $this->patternsOn($day)->isNotEmpty();
    }

    /** De patronen die op deze dag vallen. */
    public function patternsOn(CarbonImmutable $day): Collection
    {
        if (! $this->validOn($day)) {
            return collect();
        }

        return $this->patterns->filter(fn (TaskTemplatePattern $p) => $p->occursOn($day->startOfDay(), $this))->values();
    }

    /** Valt deze dag binnen de geldigheidsperiode (Vanaf / Tot en met)? */
    public function validOn(CarbonImmutable $day): bool
    {
        $day = $day->startOfDay();

        return ! $day->lt(CarbonImmutable::instance($this->valid_from)->startOfDay())
            && ! ($this->valid_until && $day->gt(CarbonImmutable::instance($this->valid_until)->startOfDay()));
    }
}

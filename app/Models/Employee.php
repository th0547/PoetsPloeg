<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'email', 'phone', 'color', 'contract_hours_min', 'contract_hours_max', 'notes', 'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'contract_hours_min' => 'float',
            'contract_hours_max' => 'float',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** Het eigen inlogaccount van de medewerker (rol Medewerker), als dat er is. */
    public function user(): HasOne
    {
        return $this->hasOne(User::class)->where('role', 'medewerker');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    /** Panden waarop de medewerker is ingewerkt. */
    public function buildings(): BelongsToMany
    {
        return $this->belongsToMany(Building::class);
    }

    public function availabilityPatterns(): HasMany
    {
        return $this->hasMany(AvailabilityPattern::class)->orderBy('weekday')->orderBy('start_time');
    }

    /**
     * Vervang het vaste weekpatroon door de (gevalideerde) tijdvakken uit het formulier.
     *
     * @param  array<int, array<string, mixed>>  $patterns  zie AvailabilityPattern::rules()
     */
    public function replaceAvailability(array $patterns): void
    {
        $this->availabilityPatterns()->delete();
        foreach ($patterns as $pattern) {
            [$unit, $every] = AvailabilityPattern::REPEATS[$pattern['repeat'] ?? 'w1'];
            $startsOn = ($every > 1 || $unit === 'day') ? ($pattern['starts_on'] ?? today()->toDateString()) : null;
            $this->availabilityPatterns()->create([
                'type' => $pattern['type'],
                'repeat_unit' => $unit,
                'repeat_every' => $every,
                'starts_on' => $startsOn,
                // Bij herhaling per dag telt de weekdag niet; we bewaren die van de startdatum (handig voor sorteren).
                'weekday' => $unit === 'day' ? CarbonImmutable::parse($startsOn)->dayOfWeekIso : $pattern['weekday'],
                'start_time' => $pattern['start_time'],
                'end_time' => $pattern['end_time'],
                'note' => $pattern['note'] ?? null,
            ]);
        }
        $this->unsetRelation('availabilityPatterns');
    }

    /** Het weekpatroon zoals het formulier (availabilityEditor) het verwacht. */
    public function availabilityRows(): array
    {
        return $this->availabilityPatterns->map(fn (AvailabilityPattern $p) => [
            'weekday' => $p->weekday,
            'type' => $p->type,
            'repeat' => $p->repeatKey(),
            'starts_on' => $p->starts_on?->toDateString(),
            'start_time' => substr($p->start_time, 0, 5),
            'end_time' => substr($p->end_time, 0, 5),
            'note' => $p->note,
        ])->values()->all();
    }

    /**
     * Goedgekeurde afwezigheid voor het weekoverzicht in het formulier: die gaat vóór het vaste patroon.
     *
     * @param  Collection<int, Absence>|null  $absences  al geladen afwezigheid (anders wordt die opgehaald)
     */
    public function absenceBlocks(?Collection $absences = null): array
    {
        return ($absences ?? $this->absences()->get())
            ->where('status', 'approved')->where('ends_at', '>=', now()->subWeeks(8))
            ->map(fn (Absence $a) => [
                'start' => $a->starts_at->format('Y-m-d\TH:i'),
                'end' => $a->ends_at->format('Y-m-d\TH:i'),
                'label' => mb_strtolower($a->typeLabel()),
            ])->values()->all();
    }

    public function absences(): HasMany
    {
        return $this->hasMany(Absence::class)->orderBy('starts_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class, 'shift_assignments')->withPivot('source')->withTimestamps();
    }

    public function fixedTemplates(): BelongsToMany
    {
        return $this->belongsToMany(TaskTemplate::class);
    }
}

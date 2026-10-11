<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

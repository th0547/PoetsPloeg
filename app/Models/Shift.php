<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use HasFactory;

    protected $fillable = [
        'building_id', 'task_template_id', 'date', 'starts_at', 'ends_at', 'required_staff', 'title', 'notes',
        'is_modified', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'is_modified' => 'boolean',
            'required_staff' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    /** Diensten met minder toegewezen medewerkers dan nodig. */
    public function scopeUnderstaffed(Builder $query): void
    {
        $query->whereRaw('(select count(*) from shift_assignments where shift_assignments.shift_id = shifts.id) < shifts.required_staff');
    }

    /** Diensten die (deels) binnen [from, to) vallen. */
    public function scopeOverlapping(Builder $query, $from, $to): void
    {
        $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'shift_assignments')->withPivot('source')->withTimestamps();
    }

    public function durationHours(): float
    {
        return round($this->starts_at->diffInMinutes($this->ends_at) / 60, 2);
    }

    public function openSlots(): int
    {
        $assigned = $this->relationLoaded('assignments') ? $this->assignments->count() : $this->assignments()->count();

        return max(0, $this->required_staff - $assigned);
    }
}

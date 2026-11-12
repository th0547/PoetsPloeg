<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bevestigde (of nog te bevestigen) beschikbaarheid van één medewerker in één week (issue #45). */
class AvailabilityWeek extends Model
{
    protected $fillable = ['employee_id', 'week_start', 'mailed_at', 'reminded_at', 'confirmed_at', 'available_hours', 'explanation', 'other_employer', 'changed_after_confirm'];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'mailed_at' => 'datetime',
            'reminded_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'available_hours' => 'float',
            'changed_after_confirm' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityPattern extends Model
{
    public const TYPES = [
        'available' => 'Kan wel',
        'unavailable' => 'Kan niet',
    ];

    protected $fillable = ['employee_id', 'weekday', 'type', 'start_time', 'end_time', 'note'];

    protected $attributes = ['type' => 'available'];

    public function isUnavailable(): bool
    {
        return $this->type === 'unavailable';
    }

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftAssignment extends Model
{
    public const SOURCES = [
        'fixed' => 'Vaste bezetting',
        'manual' => 'Handmatig',
        'replacement' => 'Vervanging',
    ];

    protected $fillable = ['shift_id', 'employee_id', 'source'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

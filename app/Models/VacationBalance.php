<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Beginsaldo vakantie-uren van een jaar (uit de loonadministratie). */
class VacationBalance extends Model
{
    protected $fillable = ['employee_id', 'year', 'opening_hours', 'note', 'updated_by'];

    protected function casts(): array
    {
        return ['opening_hours' => 'float', 'year' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

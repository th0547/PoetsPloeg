<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Eén versie van het beleid, geldig vanaf valid_from (issue #42). */
class PolicyVersion extends Model
{
    protected $fillable = ['valid_from', 'settings', 'leave_types', 'note', 'employee_notes', 'created_by'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'settings' => 'array',
            'leave_types' => 'array',
            'employee_notes' => 'array',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

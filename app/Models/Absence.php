<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Absence extends Model
{
    use HasFactory;

    public const TYPES = [
        'verlof' => 'Verlof',
        'ziek' => 'Ziek',
        'overig' => 'Overig',
        'verzoek' => 'Verlofverzoek',
    ];

    public const STATUSES = [
        'approved' => 'Goedgekeurd',
        'pending' => 'In afwachting van vervanger',
        'rejected' => 'Afgewezen',
    ];

    protected $fillable = ['employee_id', 'type', 'status', 'starts_at', 'ends_at', 'note', 'decided_at'];

    protected $attributes = ['status' => 'approved'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /** Afwezigheid die echt geldt (geen openstaand of afgewezen verlofverzoek). */
    public function scopeEffective(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    /** Geldend of nog in afwachting (dus niet afgewezen). */
    public function scopeNotRejected(Builder $query): void
    {
        $query->where('status', '!=', 'rejected');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Diensten waarvoor dit verlofverzoek een vervanger nodig heeft. */
    public function shifts(): BelongsToMany
    {
        return $this->belongsToMany(Shift::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}

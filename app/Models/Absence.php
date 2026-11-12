<?php

namespace App\Models;

use App\Support\Policy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Absence extends Model
{
    use HasFactory;

    // Verwijderen = intrekken: de registratie blijft bewaard voor de geschiedenis (deleted_at).
    use SoftDeletes;

    // Soorten afwezigheid staan in het beleid (App\Support\Policy, config/poetsploeg-cao.php),
    // zodat planners ze op de beleidspagina kunnen instellen (issue #42).

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
        return Policy::current()->leaveTypeName($this->type);
    }

    /** De verlofsoort uit het beleid (naam, telt als, betaald, ...). */
    public function leaveType(): ?array
    {
        return Policy::current()->leaveType($this->type);
    }

    /** Titel voor een verzoek, bijv. "Verlofverzoek" of "Verzoek verhuisdag". */
    public function requestLabel(): string
    {
        return $this->type === 'verlof' ? 'Verlofverzoek' : 'Verzoek '.mb_strtolower($this->typeLabel());
    }

    /** Periode als tekst, bijv. "ma 3 nov 2026" of "ma 3 nov 2026 t/m vr 7 nov 2026 12:00". */
    public function periodLabel(): string
    {
        $wholeStart = $this->starts_at->format('H:i') === '00:00';
        $wholeEnd = $this->ends_at->format('H:i') === '23:59';
        if ($this->starts_at->isSameDay($this->ends_at)) {
            return $this->starts_at->translatedFormat('D j M Y')
                .($wholeStart && $wholeEnd ? '' : ', '.$this->starts_at->format('H:i').'–'.$this->ends_at->format('H:i'));
        }

        return $this->starts_at->translatedFormat('D j M Y').($wholeStart ? '' : ' '.$this->starts_at->format('H:i'))
            .' t/m '.$this->ends_at->translatedFormat('D j M Y').($wholeEnd ? '' : ' '.$this->ends_at->format('H:i'));
    }

    /** Kleur van het label in lijsten: ziekte rood, zorg blauw, kort verzuim oranje, niet beschikbaar grijs. */
    public function chipClass(): string
    {
        return match ($this->leaveType()['counts_as'] ?? null) {
            'sickness' => 'bg-red-100 text-red-800',
            'short_leave' => $this->type === 'calamiteit' ? 'bg-orange-100 text-orange-800' : '',
            'care' => 'bg-sky-100 text-sky-800',
            'unavailable' => 'bg-gray-100 text-gray-600',
            default => '',
        };
    }
}

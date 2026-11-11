<?php

namespace App\Models;

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

    /**
     * Soorten afwezigheid. De soort wordt vastgelegd omdat die bepaalt wat er wordt doorbetaald
     * (bijv. huisarts wel, fysiotherapie niet).
     */
    public const TYPES = [
        'verlof' => 'Verlof',
        'verhuisdag' => 'Verhuisdag',
        'calamiteit' => 'Calamiteitenverlof',
        'bijzonder' => 'Bijzonder verlof',
        'onbetaald' => 'Onbetaald verlof',
        'ziek' => 'Ziek',
        'huisarts' => 'Huisarts',
        'tandarts' => 'Tandarts',
        'specialist' => 'Ziekenhuis / specialist',
        'fysiotherapie' => 'Fysiotherapie',
        'overig' => 'Overig',
    ];

    /** Indeling van de keuzelijst. */
    public const TYPE_GROUPS = [
        'Verlof' => ['verlof', 'verhuisdag', 'calamiteit', 'bijzonder', 'onbetaald'],
        'Ziekte en zorg' => ['ziek', 'huisarts', 'tandarts', 'specialist', 'fysiotherapie'],
        'Anders' => ['overig'],
    ];

    /** Soorten die je niet vooraf aanvraagt: altijd direct registreren, nooit als verzoek. */
    public const UNPLANNED = ['ziek', 'calamiteit'];

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
        return self::TYPES[$this->type] ?? ucfirst($this->type);
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

    /** Kleur van het label in lijsten: ziek rood, zorgafspraken blauw, calamiteit oranje. */
    public function chipClass(): string
    {
        return match (true) {
            $this->type === 'ziek' => 'bg-red-100 text-red-800',
            $this->type === 'calamiteit' => 'bg-orange-100 text-orange-800',
            in_array($this->type, self::TYPE_GROUPS['Ziekte en zorg'], true) => 'bg-sky-100 text-sky-800',
            default => '',
        };
    }
}

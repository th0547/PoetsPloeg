<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Building extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'short_name', 'address', 'postal_code', 'city', 'contact_name', 'contact_phone', 'contact_email',
        'access_instructions', 'notes', 'color', 'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /** Medewerkers die op dit pand zijn ingewerkt. */
    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class);
    }

    public function taskTemplates(): HasMany
    {
        return $this->hasMany(TaskTemplate::class)->orderBy('start_time');
    }

    /** Alias voor geneste routes (/panden/{building}/taken/{template}). */
    public function templates(): HasMany
    {
        return $this->taskTemplates();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * Korte naam voor compacte weergaven (maandrooster): de ingevulde korte naam, anders afgeleid van de naam
     * (issue #74), zonder een woord middenin af te kappen; is het woord te lang, dan kapt de weergave het zichtbaar af.
     *
     * - het eerste woord heel: "Ferlin Plastic Automations" → "Ferlin", "Kippers Rijssen" → "Kippers";
     * - een afkorting met meer woorden erachter krijgt hun beginletters: "JB Ventures" → "JBV";
     * - een lidwoord vooraan telt niet: "De Linde" → "Linde";
     * - hoofdletters vooraan aan een woord vast: "RAPIDprototyping" → "RAPID".
     */
    public function shortLabel(): string
    {
        if (filled($this->short_name)) {
            return $this->short_name;
        }
        $words = array_values(array_filter(preg_split('/\s+/', trim((string) $this->name)) ?: [], 'strlen'));
        while (count($words) > 1 && mb_strlen($words[0]) <= 3) {
            $first = $words[0];
            if ($first === mb_strtoupper($first) && preg_match('/\p{L}|\d/u', $first)) {
                $initials = implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 1, 3)));

                return mb_strtoupper($first.$initials);
            }
            array_shift($words);
        }
        $first = $words[0] ?? '';
        if (preg_match('/^(\p{Lu}{3,})\p{Ll}/u', $first, $m)) {
            return $m[1];
        }

        return $first;
    }

    public function fullAddress(): string
    {
        return trim(collect([$this->address, trim($this->postal_code.' '.$this->city)])->filter()->implode(', '));
    }
}

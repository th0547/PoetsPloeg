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
     * Korte naam voor compacte weergaven (maandrooster): de ingevulde korte naam, anders afgeleid van
     * de naam ("Rotomoulding Nederland" → "Rotom", "JB Ventures" → "JB Ven").
     */
    public function shortLabel(): string
    {
        if (filled($this->short_name)) {
            return $this->short_name;
        }
        $words = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $first = $words[0] ?? '';
        if (mb_strlen($first) <= 3 && isset($words[1])) {
            $first .= ' '.$words[1];
        }

        return rtrim(mb_substr($first, 0, mb_strlen($words[0] ?? '') <= 3 ? 6 : 5));
    }

    public function fullAddress(): string
    {
        return trim(collect([$this->address, trim($this->postal_code.' '.$this->city)])->filter()->implode(', '));
    }
}

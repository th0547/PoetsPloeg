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
        'name', 'address', 'postal_code', 'city', 'contact_name', 'contact_phone', 'contact_email',
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

    public function fullAddress(): string
    {
        return trim(collect([$this->address, trim($this->postal_code.' '.$this->city)])->filter()->implode(', '));
    }
}

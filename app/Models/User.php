<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'employee_id',
    ];

    protected $attributes = [
        'role' => 'planner',
    ];

    public const ROLES = [
        'planner' => 'Planner',
        'teamleider' => 'Teamleider',
        'medewerker' => 'Medewerker',
    ];

    /** Rollen die je via Gebruikers toekent; een medewerker-account hoort altijd bij een medewerker. */
    public const STAFF_ROLES = ['planner', 'teamleider'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Planners en teamleiders hebben (voorlopig) dezelfde rechten. */
    public function canPlan(): bool
    {
        return in_array($this->role, ['planner', 'teamleider'], true);
    }

    /** Medewerker met een eigen inlog: ziet alleen de eigen planning, verlof en beschikbaarheid. */
    public function isEmployee(): bool
    {
        return $this->role === 'medewerker';
    }

    /** Uitgenodigd, maar nog geen wachtwoord ingesteld (dat zet ook het e-mailadres op bevestigd). */
    public function invitationPending(): bool
    {
        return $this->isEmployee() && $this->email_verified_at === null;
    }

    /** Waar iemand na het inloggen begint. */
    public function homeRoute(): string
    {
        return $this->isEmployee() ? 'my.planning' : 'dashboard';
    }

    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }
}

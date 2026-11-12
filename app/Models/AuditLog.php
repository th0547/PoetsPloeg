<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén regel in het logboek. Alleen toevoegen, nooit wijzigen. Oude regels worden na
 * config('poetsploeg.audit_retention_months') maanden opgeruimd (model:prune, dagelijks).
 */
class AuditLog extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    /** Soorten acties, met een leesbare naam voor het overzicht. */
    public const ACTIONS = [
        'pii.view' => 'Persoonsgegevens ingezien',
        'auth.login' => 'Ingelogd',
        'auth.failed' => 'Inloggen mislukt',
        'auth.logout' => 'Uitgelogd',
        'user.created' => 'Gebruiker aangemaakt',
        'user.updated' => 'Gebruiker gewijzigd',
        'user.deleted' => 'Gebruiker verwijderd',
        'employee.deleted' => 'Medewerker verwijderd',
        'account.invited' => 'Medewerker uitgenodigd',
        'account.revoked' => 'Inlog ingetrokken',
    ];

    protected $fillable = ['user_id', 'user_name', 'action', 'subject_type', 'subject_id', 'description', 'meta', 'ip'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subMonths(max(1, (int) config('poetsploeg.audit_retention_months'))));
    }
}

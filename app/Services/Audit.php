<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Schrijft naar het logboek (issue #23). Inzage van persoonsgegevens wordt per gebruiker per
 * onderwerp hoogstens één keer per 10 minuten gelogd, zodat het logboek leesbaar blijft.
 */
class Audit
{
    public const VIEW_DEDUP_MINUTES = 10;

    /** @param  Model|null  $actor  wie het deed; standaard de ingelogde gebruiker */
    public static function log(string $action, string $description, ?Model $subject = null, array $meta = [], ?Model $actor = null): ?AuditLog
    {
        $user = $actor ?? auth()->user();

        return AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'description' => mb_substr($description, 0, 255),
            'meta' => $meta ?: null,
            'ip' => request()?->ip(),
        ]);
    }

    /** Inzage van persoonsgegevens (telefoonnummers, toegangsinstructies, verlofgeschiedenis). */
    public static function viewed(Model $subject, string $description): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }
        $key = sprintf('audit:view:%d:%s:%d', $user->getKey(), class_basename($subject), $subject->getKey());
        if (Cache::add($key, true, now()->addMinutes(self::VIEW_DEDUP_MINUTES))) {
            self::log('pii.view', $description, $subject);
        }
    }
}

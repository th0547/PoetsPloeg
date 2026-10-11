<?php

namespace App\Support;

use App\Services\ConflictChecker;

/**
 * Kleine hulpfuncties voor weergave van planningsgegevens.
 */
class Planning
{
    /** Het zwaarste niveau uit een lijst waarschuwingen, of null. */
    public static function worstLevel(array $warnings): ?string
    {
        $levels = array_column($warnings, 'level');
        foreach ([ConflictChecker::DANGER, ConflictChecker::WARNING, ConflictChecker::INFO] as $level) {
            if (in_array($level, $levels, true)) {
                return $level;
            }
        }

        return null;
    }

    /** Waarschuwingen die bevestiging vereisen (info-meldingen niet). */
    public static function needsConfirmation(array $warnings): bool
    {
        return collect($warnings)->contains(fn ($w) => $w['level'] !== ConflictChecker::INFO);
    }

    public static function hours(float $hours): string
    {
        return rtrim(rtrim(number_format($hours, 2, ',', ''), '0'), ',');
    }
}

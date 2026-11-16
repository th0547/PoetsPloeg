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

    /** Initialen voor een avatar: "Jan de Vries" → "JV", "Bram" → "BR". */
    public static function initials(?string $name): string
    {
        $all = array_values(array_filter(preg_split('/\s+/', trim((string) $name)) ?: []));
        // Titels ("dr.", "ing.") tellen niet mee, tenzij er verder niets staat.
        $words = array_values(array_filter($all, fn ($w) => ! str_ends_with($w, '.'))) ?: $all;
        if ($words === []) {
            return '?';
        }
        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_strtoupper(mb_substr($words[0], 0, 1).mb_substr(end($words), 0, 1));
    }

    /** Leesbare tekstkleur (donker of wit) op een achtergrondkleur als "#0d9488". */
    public static function contrast(?string $hex): string
    {
        if (! is_string($hex) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
            return '#ffffff';
        }
        [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));
        $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;

        return $luminance > 0.62 ? '#0f172a' : '#ffffff';
    }

    /** Minuten sinds middernacht. */
    public static function minutes(\DateTimeInterface $moment): int
    {
        return (int) $moment->format('G') * 60 + (int) $moment->format('i');
    }
}

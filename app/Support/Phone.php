<?php

namespace App\Support;

/**
 * Telefoonnummers (issue #71): alleen cijfers, spaties, +, -, ( en ), met minstens 6 cijfers.
 * Zo wordt een achternaam in het telefoonveld geen bel-link.
 */
class Phone
{
    public static function isValid(?string $value): bool
    {
        $value = trim((string) $value);

        return $value !== ''
            && preg_match('/^\+?[0-9 ()\-]+$/', $value) === 1
            && preg_match_all('/[0-9]/', $value) >= 6;
    }

    /** "tel:+31612345678", of null als het geen telefoonnummer is. */
    public static function href(?string $value): ?string
    {
        return self::isValid($value) ? 'tel:'.preg_replace('/[^0-9+]/', '', (string) $value) : null;
    }
}

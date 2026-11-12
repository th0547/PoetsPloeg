<?php

namespace App\Support;

use App\Http\Controllers\HelpController;
use App\Services\RecurrenceRule;

/**
 * Vaste vraag voor een AI-assistent (Claude, ChatGPT) om een herhalingscode te schrijven (issue #39).
 * Eén bron: de toegestane onderdelen komen uit RecurrenceRule::PARTS en de voorbeelden uit de
 * hulppagina. Er staan geen gegevens uit PoetsPloeg in, alleen deze uitleg en wat de gebruiker typt.
 */
class RecurrenceAiPrompt
{
    /** Maximale lengte van de beschrijving van de gebruiker (de link moet kort blijven). */
    public const MAX_WISH = 400;

    public static function base(): string
    {
        $examples = collect(HelpController::RECURRENCE_EXAMPLES)
            ->take(5)
            ->map(fn ($e) => "- {$e[0]} = {$e[1]}")
            ->implode("\n");

        return implode("\n", [
            'Schrijf een iCalendar RRULE (RFC 5545) voor een schoonmaakrooster in de app PoetsPloeg.',
            'Regels:',
            '- Alleen deze onderdelen: '.implode(', ', RecurrenceRule::PARTS).'.',
            '- FREQ is DAILY, WEEKLY, MONTHLY of YEARLY. Geen uren of minuten (geen BYHOUR/BYMINUTE).',
            '- UNTIL als JJJJMMDD. Gebruik COUNT of UNTIL, niet allebei.',
            '- Het patroon telt vanaf een startdatum ("Telt vanaf") die de gebruiker apart instelt; zet geen DTSTART in de code.',
            '- Tijden en feestdagen stelt de gebruiker apart in.',
            'Voorbeelden:',
            $examples,
            'Geef de code op één regel, zonder "RRULE:", en leg in één zin uit wat hij doet. Vraag door als de wens onduidelijk is.',
        ]);
    }
}

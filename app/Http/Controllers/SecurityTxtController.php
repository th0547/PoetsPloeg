<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * /.well-known/security.txt (RFC 9116): waar beveiligingsproblemen gemeld kunnen worden.
 * De vervaldatum wordt steeds opnieuw berekend (ruim binnen het maximum van een jaar),
 * zodat het bestand nooit verloopt.
 */
class SecurityTxtController extends Controller
{
    public function __invoke(): Response
    {
        $contact = config('poetsploeg.security_contact');
        abort_unless(filled($contact), 404);

        $lines = [
            'Contact: '.$contact,
            'Expires: '.now()->utc()->addMonths(6)->startOfMonth()->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: nl, en',
            'Canonical: '.rtrim(config('app.url'), '/').'/.well-known/security.txt',
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}

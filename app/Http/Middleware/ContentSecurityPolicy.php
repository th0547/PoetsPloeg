<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content-Security-Policy als extra vangnet naast de escaping in Blade.
 *
 * - script-src: alleen eigen scripts. 'unsafe-eval' is nodig voor Alpine.js (x-data, @click).
 *   Inline scripts en inline onclick/onsubmit zijn niet toegestaan (zie resources/js/actions.js).
 * - style-src: 'unsafe-inline' is nodig voor style-attributen (kleuren van panden en medewerkers),
 *   Alpine (:style) en vis-timeline/FullCalendar, die stijlen in de pagina zetten.
 *
 * Met POETSPLOEG_CSP=report-only wordt de policy alleen gemeld (in de browserconsole), met
 * POETSPLOEG_CSP=uit helemaal niet meegestuurd.
 */
class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $mode = config('poetsploeg.csp');
        // De foutpagina met details (alleen met APP_DEBUG) gebruikt inline scripts.
        $debugError = config('app.debug') && $response->getStatusCode() >= 500;
        if ($mode === 'uit' || $debugError || $response->headers->has('Content-Security-Policy')) {
            return $response;
        }

        $header = $mode === 'report-only' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        $response->headers->set($header, $this->policy());

        return $response;
    }

    public function policy(): string
    {
        // Tijdens `npm run dev` komen scripts, stijlen en de websocket van de Vite-server.
        $dev = Vite::isRunningHot() ? rtrim((string) file_get_contents(Vite::hotFile())) : null;
        $devWs = $dev ? preg_replace('#^http#', 'ws', $dev) : null;
        $fonts = 'https://fonts.bunny.net'; // lettertype op de inlogpagina

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'unsafe-eval'", $dev],
            'style-src' => ["'self'", "'unsafe-inline'", $fonts, $dev],
            'font-src' => ["'self'", 'data:', $fonts],
            'img-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", $dev, $devWs],
            'object-src' => ["'none'"],
            'base-uri' => ["'none'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'self'"],
        ];

        return collect($directives)
            ->map(fn (array $sources, string $name) => $name.' '.implode(' ', array_filter($sources)))
            ->implode('; ');
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthController extends Controller
{
    /**
     * Publiek: alleen status en database. De draaiende commit wordt niet genoemd (die zou de exacte
     * codeversie verraden). De uitrol stuurt de verwachte commit mee (?commit=<sha>) en krijgt dan
     * alleen terug of die klopt: "versie": "juist" of "anders".
     */
    public function __invoke(Request $request): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'fout';
        }

        $ok = $database === 'ok';
        $body = ['status' => $ok ? 'ok' : 'fout', 'database' => $database];

        $expected = $request->query('commit');
        if (is_string($expected) && $expected !== '') {
            $body['versie'] = hash_equals((string) config('poetsploeg.commit'), $expected) ? 'juist' : 'anders';
        }

        return response()->json($body, $ok ? 200 : 503)->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\NowStatus;
use App\Support\Planning;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Zoekbalk (Ctrl+K): medewerkers met waar ze nu zijn, en panden met wie er nu is.
 * Zonder zoekterm: iedereen die vandaag werkt, wie nu bezig is bovenaan.
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $term = mb_substr(trim((string) $request->query('q', '')), 0, 60);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $now = CarbonImmutable::now();

        $employees = Employee::query()->active()
            ->when($term !== '', fn ($q) => $q->where('name', 'like', $like))
            ->orderBy('name')
            ->limit($term === '' ? 500 : 8)
            ->get(['id', 'name', 'color']);
        $status = NowStatus::forEmployees($employees->pluck('id'), $now);

        $rows = $employees->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->name,
            'color' => $e->color,
            'ink' => Planning::contrast($e->color),
            'initials' => Planning::initials($e->name),
            'state' => $status[$e->id]['state'],
            'label' => $status[$e->id]['label'],
            'today' => $status[$e->id]['today'],
        ]);
        if ($term === '') {
            // Zonder zoekterm alleen wie vandaag werkt of afwezig is: nu bezig, dan straks, dan klaar.
            $order = ['working' => 0, 'later' => 1, 'absent' => 2, 'done' => 3];
            $rows = $rows->filter(fn ($r) => isset($order[$r['state']]))
                ->sortBy(fn ($r) => [$order[$r['state']], $r['today'][0]['start'] ?? '', $r['name']])
                ->take(12);
        }

        $buildings = collect();
        if ($term !== '') {
            $buildings = Building::query()->active()
                ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('city', 'like', $like)->orWhere('address', 'like', $like))
                ->orderBy('name')
                ->limit(6)
                ->get();
            $shifts = Shift::query()->active()
                ->whereIn('building_id', $buildings->pluck('id'))
                ->overlapping($now->startOfDay(), $now->startOfDay()->addDay())
                ->with('assignments.employee:id,name')
                ->orderBy('starts_at')
                ->get()
                ->groupBy('building_id');

            $buildings = $buildings->map(function (Building $b) use ($shifts, $now) {
                $today = $shifts->get($b->id, collect());
                $present = $today->filter(fn (Shift $s) => $s->starts_at->lte($now) && $s->ends_at->gt($now))
                    ->flatMap(fn (Shift $s) => $s->assignments->map(fn ($a) => $a->employee->name))->unique()->values();
                $next = $today->first(fn (Shift $s) => $s->starts_at->gt($now));

                return [
                    'id' => $b->id,
                    'name' => $b->name,
                    'color' => $b->color,
                    'address' => $b->fullAddress(),
                    'label' => match (true) {
                        $present->isNotEmpty() => 'Nu aanwezig: '.$present->join(', ', ' en '),
                        $next !== null => 'Straks '.$next->starts_at->format('H:i').': '.($next->assignments->map(fn ($a) => $a->employee->name)->join(', ', ' en ') ?: 'nog niemand ingepland'),
                        $today->isNotEmpty() => 'Vandaag klaar',
                        default => 'Vandaag geen diensten',
                    },
                    'url' => route('buildings.show', $b),
                ];
            });
        }

        return response()->json([
            'term' => $term,
            'employees' => $rows->values(),
            'buildings' => $buildings->values(),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AbsenceResource;
use App\Http\Resources\V1\BuildingResource;
use App\Http\Resources\V1\ShiftResource;
use App\Models\Shift;
use App\Services\TodayOverview;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Vandaag in de app: dezelfde gegevens als de startpagina (TodayOverview). */
class TodayController extends Controller
{
    public function __invoke(Request $request, TodayOverview $overview): JsonResponse
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $day = $request->filled('date') ? CarbonImmutable::parse($request->query('date')) : CarbonImmutable::today();
        $data = $overview->build($day);
        $warnings = $data['warnings'];

        return response()->json([
            'date' => $data['day']->toDateString(),
            'is_today' => $data['isToday'],
            'holiday' => $data['holiday'],
            'counts' => [
                'shifts' => $data['shiftCount'],
                'working_now' => $data['workingNow'],
                'warnings' => $data['warningCount'],
                'open_today' => $data['openToday'],
                'open_slots_14_days' => $data['openSlotCount'],
                'absent' => $data['absentToday']->count(),
                'pending_requests' => $data['pendingRequests']->count(),
            ],
            'current' => ShiftResource::list($data['current'], $warnings),
            'later' => ShiftResource::list($data['later'], $warnings),
            'finished' => ShiftResource::list($data['finished'], $warnings),
            'open_shifts' => $data['openShifts']->map(fn (Shift $s) => [
                'id' => $s->id,
                'title' => $s->title,
                'date' => $s->starts_at->toDateString(),
                'start_time' => $s->starts_at->format('H:i'),
                'end_time' => $s->ends_at->format('H:i'),
                'open_slots' => max(0, $s->required_staff - $s->assignments_count),
                'building' => BuildingResource::make($s->building),
            ])->values(),
            'absent' => AbsenceResource::collection($data['absentToday']),
            'pending_requests' => AbsenceResource::collection($data['pendingRequests']),
        ]);
    }
}

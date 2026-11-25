<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenShiftController as WebOpenShiftController;
use App\Http\Resources\V1\ShiftResource;
use App\Models\Shift;
use App\Services\OpenShifts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Diensten met open plekken, per dag, met de reden (vaste kracht afwezig, ...). */
class OpenShiftController extends Controller
{
    public function __invoke(Request $request, OpenShifts $openShifts): JsonResponse
    {
        $data = $request->validate([
            'weeks' => ['nullable', 'integer', Rule::in(array_keys(WebOpenShiftController::PERIODS))],
            'building_id' => ['nullable', 'integer', 'exists:buildings,id'],
        ]);
        $shifts = $openShifts->upcoming((int) ($data['weeks'] ?? 2), $data['building_id'] ?? null);
        $missing = $openShifts->missing($shifts);

        return response()->json([
            'shift_count' => $shifts->count(),
            'slot_count' => $shifts->sum(fn (Shift $s) => $s->openSlots()),
            'shifts' => $shifts->map(fn (Shift $s) => (new ShiftResource($s))->resolve($request) + [
                'fixed_missing' => $missing[$s->id] ?? [],
            ])->values(),
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Shift;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Overzicht van alle diensten met open plekken, per dag.
 */
class OpenShiftController extends Controller
{
    public const PERIODS = [1 => '1 week', 2 => '2 weken', 4 => '4 weken', 8 => '8 weken'];

    public function __invoke(Request $request, ShiftGenerator $generator): View
    {
        $weeks = array_key_exists($request->integer('weken'), self::PERIODS) ? $request->integer('weken') : 2;
        $buildingId = $request->integer('pand') ?: null;

        $today = CarbonImmutable::today();
        $until = $today->addWeeks($weeks);
        $generator->generate($today, $until);

        $shifts = Shift::query()->active()->understaffed()
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', $until->addDay())
            ->when($buildingId, fn ($q) => $q->where('building_id', $buildingId))
            ->with(['building', 'assignments.employee'])
            ->orderBy('starts_at')
            ->get();

        return view('shifts.open', [
            'days' => $shifts->groupBy(fn (Shift $s) => $s->starts_at->toDateString()),
            'shiftCount' => $shifts->count(),
            'slotCount' => $shifts->sum(fn (Shift $s) => $s->openSlots()),
            'weeks' => $weeks,
            'buildingId' => $buildingId,
            'buildings' => Building::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Aantal open plekken in de komende 14 dagen (voor de teller in het menu). */
    public static function upcomingOpenSlots(): int
    {
        return (int) Shift::query()->active()->understaffed()
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', CarbonImmutable::today()->addDays(15))
            ->withCount('assignments')
            ->get(['id', 'required_staff'])
            ->sum(fn (Shift $s) => max(0, $s->required_staff - $s->assignments_count));
    }
}

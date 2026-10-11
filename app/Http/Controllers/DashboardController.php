<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\Shift;
use App\Services\ConflictChecker;
use App\Services\DutchHolidays;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ShiftGenerator $generator, ConflictChecker $checker, DutchHolidays $holidays): View
    {
        $day = rescue(fn () => CarbonImmutable::parse($request->query('datum', 'today')), CarbonImmutable::today(), false)->startOfDay();
        $today = CarbonImmutable::today();
        $openUntil = $today->addDays(14);

        $generator->generate(min($day, $today), max($day, $openUntil));

        $shifts = Shift::query()->active()
            ->overlapping($day, $day->addDay())
            ->with(['building', 'assignments.employee', 'skills'])
            ->orderBy('starts_at')
            ->get();
        $warnings = $checker->forShifts($shifts);

        $openShifts = Shift::query()->active()
            ->where('starts_at', '>=', now())
            ->where('starts_at', '<', $openUntil->addDay())
            ->withCount('assignments')
            ->with('building')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Shift $s) => $s->assignments_count < $s->required_staff)
            ->values();

        $absentToday = Absence::query()->with('employee')
            ->where('starts_at', '<', $day->addDay())->where('ends_at', '>', $day)
            ->orderBy('ends_at')->get();

        $warningCount = collect($warnings)->flatten(2)->filter(fn ($w) => is_array($w) && $w['level'] !== ConflictChecker::INFO)->count();

        return view('dashboard', [
            'day' => $day,
            'holiday' => $holidays->nameFor($day),
            'shiftsByBuilding' => $shifts->groupBy(fn ($s) => $s->building->name),
            'shiftCount' => $shifts->count(),
            'warnings' => $warnings,
            'warningCount' => $warningCount,
            'openShifts' => $openShifts,
            'openSlotCount' => $openShifts->sum(fn ($s) => $s->required_staff - $s->assignments_count),
            'absentToday' => $absentToday,
        ]);
    }
}

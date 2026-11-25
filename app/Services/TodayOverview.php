<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Shift;
use Carbon\CarbonImmutable;

/**
 * Vandaag: wie is nu waar, wat komt er nog, en wat vraagt actie (open plekken, verlofverzoeken, afwezigen).
 * Gedeeld door de startpagina (DashboardController) en de app (Api\V1\TodayController).
 */
class TodayOverview
{
    public function __construct(
        private ShiftGenerator $generator,
        private ConflictChecker $checker,
        private DutchHolidays $holidays,
    ) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $day): array
    {
        $day = $day->startOfDay();
        $today = CarbonImmutable::today();
        $now = CarbonImmutable::now();
        $openUntil = $today->addDays(14);

        $this->generator->generate(min($day, $today), max($day, $openUntil));

        $shifts = Shift::query()->active()
            ->overlapping($day, $day->addDay())
            ->with(['building', 'assignments.employee', 'skills'])
            ->orderBy('starts_at')
            ->get();
        $warnings = $this->checker->forShifts($shifts);

        // Zelfde definitie als de teller "Open diensten" in het menu: nog niet afgelopen, komende 14 dagen.
        $openShifts = Shift::query()->active()
            ->where('ends_at', '>', now())
            ->where('starts_at', '<', $openUntil->addDay())
            ->withCount('assignments')
            ->with('building')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Shift $s) => $s->assignments_count < $s->required_staff)
            ->values();

        $absentToday = Absence::query()->effective()->with('employee')
            ->where('starts_at', '<', $day->addDay())->where('ends_at', '>', $day)
            ->orderBy('ends_at')->get();

        $pendingRequests = Absence::query()->where('status', 'pending')->with('employee')->withCount('shifts')
            ->orderBy('starts_at')->get();

        $warningCount = collect($warnings)->flatten(2)->filter(fn ($w) => is_array($w) && $w['level'] !== ConflictChecker::INFO)->count();

        // Vandaag verdelen we de dag in nu bezig, straks en afgerond; een andere dag is één lijst.
        $isToday = $day->isSameDay($today);
        $current = $isToday ? $shifts->filter(fn (Shift $s) => $s->starts_at->lte($now) && $s->ends_at->gt($now))->values() : collect();
        $later = $isToday ? $shifts->filter(fn (Shift $s) => $s->starts_at->gt($now))->values() : $shifts;
        $finished = $isToday ? $shifts->filter(fn (Shift $s) => $s->ends_at->lte($now))->values() : collect();

        return [
            'day' => $day,
            'now' => $now,
            'isToday' => $isToday,
            'holiday' => $this->holidays->nameFor($day),
            'current' => $current,
            'later' => $later,
            'finished' => $finished,
            'workingNow' => $current->flatMap->assignments->pluck('employee_id')->unique()->count(),
            'shiftCount' => $shifts->count(),
            'warnings' => $warnings,
            'warningCount' => $warningCount,
            'openShifts' => $openShifts,
            'openSlotCount' => $openShifts->sum(fn ($s) => $s->required_staff - $s->assignments_count),
            'openToday' => $shifts->sum(fn (Shift $s) => $s->ends_at->gt($now) || ! $isToday ? $s->openSlots() : 0),
            'absentToday' => $absentToday,
            'pendingRequests' => $pendingRequests,
        ];
    }
}

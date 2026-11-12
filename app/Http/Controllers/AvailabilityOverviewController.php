<?php

namespace App\Http\Controllers;

use App\Models\AvailabilityWeek;
use App\Models\Employee;
use App\Services\ConflictChecker;
use App\Services\WeekAvailability;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Overzicht voor planners (issue #45): per medewerker per week bevestigd / open / geen inlog,
 * signalen onder contracturen en het meer-uren-signaal per kwartaal (cao art. 13).
 */
class AvailabilityOverviewController extends Controller
{
    public function __invoke(WeekAvailability $availability, ConflictChecker $checker): View
    {
        $weeks = WeekAvailability::weeks();
        $policy = Policy::current();
        $employees = Employee::active()->with(['user', 'availabilityPatterns'])->orderBy('name')->get();
        $rows = AvailabilityWeek::whereIn('employee_id', $employees->pluck('id'))
            ->whereDate('week_start', '>=', $weeks->first())
            ->get()
            ->groupBy('employee_id');

        $planned = [];
        foreach ($weeks as $week) {
            $planned[$week->toDateString()] = $checker->weekHours($employees->pluck('id'), $week);
        }

        $grid = $employees->map(function (Employee $employee) use ($weeks, $rows, $availability, $planned, $policy) {
            $cells = $weeks->map(function (CarbonImmutable $week) use ($employee, $rows, $availability, $planned, $policy) {
                $row = ($rows[$employee->id] ?? collect())->first(fn ($r) => $r->week_start->isSameDay($week));
                $hours = $row?->available_hours ?? $availability->hours($employee, $week);
                $plannedHours = $planned[$week->toDateString()][$employee->id] ?? 0;

                return [
                    'status' => match (true) {
                        ! $employee->user => 'no_login',
                        $employee->user->invitationPending() => 'invited',
                        $row?->isConfirmed() => 'confirmed',
                        default => 'open',
                    },
                    'row' => $row,
                    'hours' => $hours,
                    'below' => $availability->belowContract($employee, $hours, $week),
                    'planned' => $plannedHours,
                    // Alleen voor weken waarvan het rooster al bekend moet zijn.
                    'planned_below' => $policy->get('contract.signal_planned_below_contract') && $employee->contract_hours_min !== null
                        && $week->lte(CarbonImmutable::today()->startOfWeek()->addWeeks($policy->get('roster.notice_weeks')))
                        && $plannedHours < (float) $employee->contract_hours_min,
                ];
            });

            return ['employee' => $employee, 'cells' => $cells];
        });

        $target = WeekAvailability::targetWeek();
        $open = $grid->filter(fn ($g) => ($g['cells']->last()['status'] ?? null) === 'open')->pluck('employee');

        return view('availability.overview', [
            'weeks' => $weeks,
            'grid' => $grid,
            'target' => $target,
            'openForTarget' => $open,
            'deadline' => $target->subWeeks($policy->get('roster.notice_weeks')),
            'extraHours' => $this->extraHoursSignal($employees, $policy),
        ]);
    }

    /**
     * Meer-uren (cao art. 13): gemiddeld per week in het vorige kwartaal ≥ X% boven het contract.
     *
     * @return list<array{employee: Employee, average: float, contract: float, percent: float}>
     */
    private function extraHoursSignal($employees, Policy $policy): array
    {
        if ($policy->get('contract.extra_hours_small_company')) {
            return [];
        }
        $yearly = $policy->get('contract.extra_hours_period') === 'year';
        $end = $yearly ? CarbonImmutable::today()->startOfYear() : CarbonImmutable::today()->firstOfQuarter();
        $start = $yearly ? $end->subYear() : $end->subMonths(3);
        $weeks = max(1, (int) round($start->diffInDays($end) / 7));
        $threshold = $policy->get('contract.extra_hours_threshold');

        $minutes = DB::table('shift_assignments')
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->whereNull('shifts.cancelled_at')
            ->where('shifts.starts_at', '>=', $start)
            ->where('shifts.starts_at', '<', $end)
            ->whereIn('shift_assignments.employee_id', $employees->pluck('id'))
            ->get(['shift_assignments.employee_id', 'shifts.starts_at', 'shifts.ends_at'])
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => CarbonImmutable::parse($r->starts_at)->diffInMinutes(CarbonImmutable::parse($r->ends_at))));

        $result = [];
        foreach ($employees as $employee) {
            $contract = (float) ($employee->contract_hours_min ?? 0);
            if ($contract <= 0) {
                continue;
            }
            $average = round(($minutes[$employee->id] ?? 0) / 60 / $weeks, 1);
            $percent = round(($average - $contract) / $contract * 100, 1);
            if ($percent >= $threshold) {
                $result[] = compact('employee', 'average', 'contract', 'percent');
            }
        }

        return $result;
    }
}

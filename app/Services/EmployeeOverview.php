<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\NowStatus;
use App\Support\Planning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Overzicht van één medewerker: waar is hij of zij nu, hoe ziet de dag en de week eruit.
 * Gebruikt door het medewerkerpaneel (JSON) en de profielpagina.
 */
class EmployeeOverview
{
    public function __construct(private ShiftGenerator $generator, private ConflictChecker $checker, private DutchHolidays $holidays) {}

    /**
     * @param  CarbonImmutable|null  $date  een dag in de week die getoond wordt (standaard vandaag)
     */
    public function build(Employee $employee, ?CarbonImmutable $date = null): array
    {
        $generator = $this->generator;
        $checker = $this->checker;
        $holidays = $this->holidays;
        $today = CarbonImmutable::today();
        $date = ($date ?? $today)->startOfDay();
        $weekStart = $date->startOfWeek();
        $weekEnd = $weekStart->addDays(6);
        $generator->generate($weekStart, $weekEnd);

        $shifts = Shift::query()->active()
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<', $weekEnd->addDay())
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $employee->id))
            ->with(['building:id,name,color', 'assignments.employee:id,name,color', 'skills:id,name'])
            ->orderBy('starts_at')
            ->get();
        $warnings = $checker->forShifts($shifts);

        $absences = Absence::query()->notRejected()
            ->where('employee_id', $employee->id)
            ->where('ends_at', '>=', min($weekStart, $today))
            ->orderBy('starts_at')
            ->get();

        $holidayNames = $holidays->between($weekStart, $weekEnd);
        $now = CarbonImmutable::now();
        $days = [];
        for ($day = $weekStart; $day->lte($weekEnd); $day = $day->addDay()) {
            $next = $day->addDay();
            $days[] = [
                'date' => $day->toDateString(),
                'label' => $day->translatedFormat('D j M'),
                'today' => $day->isSameDay($today),
                'past' => $day->lt($today),
                'holiday' => $holidayNames[$day->toDateString()] ?? null,
                'absences' => $absences
                    ->filter(fn (Absence $a) => $a->starts_at->lt($next) && $a->ends_at->gt($day))
                    ->map(fn (Absence $a) => ['label' => $a->typeLabel(), 'pending' => $a->isPending(), 'kind' => $a->leaveType()['counts_as'] ?? null])
                    ->values(),
                'shifts' => $shifts
                    ->filter(fn (Shift $s) => $s->starts_at->isSameDay($day))
                    ->map(function (Shift $s) use ($warnings, $employee, $now) {
                        $mine = $warnings[$s->id][$employee->id] ?? [];

                        return [
                            'id' => $s->id,
                            'start' => $s->starts_at->format('H:i'),
                            'end' => $s->ends_at->format('H:i'),
                            'hours' => Planning::hours($s->durationHours()),
                            'building' => $s->building->name,
                            'color' => $s->building->color,
                            'title' => $s->title,
                            'state' => $s->ends_at->lte($now) ? 'done' : ($s->starts_at->lte($now) ? 'now' : 'later'),
                            'level' => Planning::worstLevel($mine),
                            'warnings' => collect($mine)->pluck('message')->all(),
                            'colleagues' => $s->assignments->where('employee_id', '!=', $employee->id)->map(fn ($a) => $a->employee->name)->values(),
                        ];
                    })->values(),
            ];
        }

        $hours = $shifts->sum(fn (Shift $s) => $s->durationHours());
        $contact = Gate::allows('view-contact-details');
        if ($contact) {
            Audit::viewed($employee, "Medewerker {$employee->name}: contactgegevens en planning");
        }
        $employee->loadMissing(['skills:id,name', 'buildings:id,name,color']);

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'color' => $employee->color,
            'ink' => Planning::contrast($employee->color),
            'initials' => Planning::initials($employee->name),
            'active' => $employee->active,
            'phone' => $contact ? $employee->phone : null,
            'email' => $contact ? $employee->email : null,
            'notes' => $contact ? $employee->notes : null,
            'contract' => ['min' => $employee->contract_hours_min, 'max' => $employee->contract_hours_max],
            'skills' => $employee->skills->pluck('name'),
            'buildings' => $employee->buildings->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'color' => $b->color]),
            'status' => NowStatus::forEmployee($employee->id, $now),
            'week' => [
                'start' => $weekStart->toDateString(),
                'number' => $weekStart->isoWeek(),
                'label' => $weekStart->translatedFormat('j M').' – '.$weekEnd->translatedFormat('j M Y'),
                'current' => $weekStart->isSameDay($today->startOfWeek()),
                'prev' => $weekStart->subWeek()->toDateString(),
                'next' => $weekStart->addWeek()->toDateString(),
                'hours' => round($hours, 2),
                'hours_label' => Planning::hours($hours),
                'shifts' => $shifts->count(),
            ],
            'days' => $days,
            'absences' => $absences
                ->filter(fn (Absence $a) => $a->ends_at->gte($now))
                ->take(6)
                ->map(fn (Absence $a) => [
                    'id' => $a->id,
                    'label' => $a->typeLabel(),
                    'period' => $a->periodLabel(),
                    'pending' => $a->isPending(),
                    'kind' => $a->leaveType()['counts_as'] ?? null,
                    'url' => route('absences.show', $a),
                ])->values(),
            'urls' => [
                'profile' => route('employees.show', $employee),
                'edit' => route('employees.edit', $employee),
                'absence' => route('absences.create', ['medewerker' => $employee->id]),
                'planning' => route('planning', ['view' => 'employee', 'range' => 'week', 'date' => $weekStart->toDateString(), 'q' => $employee->name]),
            ],
        ];
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\ConflictChecker;
use App\Services\DutchHolidays;
use App\Services\ShiftGenerator;
use App\Support\Planning;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanningApiController extends Controller
{
    public function __construct(
        private ShiftGenerator $generator,
        private ConflictChecker $checker,
        private DutchHolidays $holidays,
    ) {
    }

    /**
     * Gegevens voor de Gantt-weergave, gegroepeerd per medewerker of per pand.
     */
    public function timeline(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'view' => ['required', 'in:employee,building'],
        ]);
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();
        abort_if($from->diffInDays($to) > 62, 422, 'Kies een periode van maximaal twee maanden.');

        $this->generator->generate($from, $to);
        $toExclusive = $to->addDay();

        $shifts = Shift::query()->active()
            ->overlapping($from, $toExclusive)
            ->with(['building:id,name,color', 'assignments.employee:id,name,color', 'skills:id,name'])
            ->orderBy('starts_at')
            ->get();
        $warnings = $this->checker->forShifts($shifts);

        $items = [];
        $groups = [];

        if ($data['view'] === 'employee') {
            $employees = Employee::query()
                ->where(fn ($q) => $q->where('active', true)
                    ->orWhereIn('id', $shifts->flatMap->assignments->pluck('employee_id')))
                ->orderBy('name')
                ->get();

            $hours = [];
            foreach ($shifts as $shift) {
                foreach ($shift->assignments as $a) {
                    $hours[$a->employee_id] = ($hours[$a->employee_id] ?? 0) + $shift->durationHours();
                }
            }

            $groups[] = ['id' => 'open', 'content' => '<strong>Open diensten</strong>', 'className' => 'group-open'];
            $isWeek = $from->diffInDays($to) <= 6;
            foreach ($employees as $employee) {
                $planned = Planning::hours($hours[$employee->id] ?? 0);
                $contract = $isWeek && $employee->contract_hours_max ? ' / '.Planning::hours($employee->contract_hours_max) : '';
                $over = $isWeek && $employee->contract_hours_max && ($hours[$employee->id] ?? 0) > $employee->contract_hours_max;
                $groups[] = [
                    'id' => $employee->id,
                    'content' => sprintf(
                        '<span class="group-dot" style="background:%s"></span>%s <small class="%s">%s%s u</small>',
                        e($employee->color), e($employee->name), $over ? 'text-red-600 font-semibold' : 'text-gray-500', $planned, $contract,
                    ),
                ];
            }

            foreach ($shifts as $shift) {
                foreach ($shift->assignments as $a) {
                    $w = $warnings[$shift->id][$a->employee_id] ?? [];
                    $items[] = $this->item($shift, "a-{$shift->id}-{$a->employee_id}", $a->employee_id, $shift->building->name.' · '.$shift->title, $w) + [
                        'employeeId' => $a->employee_id,
                    ];
                }
                for ($i = 0; $i < $shift->openSlots(); $i++) {
                    $items[] = $this->item($shift, "o-{$shift->id}-{$i}", 'open', $shift->building->name.' · '.$shift->title, [], 'item-open') + [
                        'employeeId' => null,
                    ];
                }
            }

            $absences = Absence::query()->with('employee:id,name')
                ->where('starts_at', '<', $toExclusive)->where('ends_at', '>', $from)->get();
            foreach ($absences as $absence) {
                $items[] = [
                    'id' => 'abs-'.$absence->id,
                    'group' => $absence->employee_id,
                    'start' => $absence->starts_at->toIso8601String(),
                    'end' => $absence->ends_at->toIso8601String(),
                    'type' => 'background',
                    'className' => 'bg-absence',
                    'content' => e($absence->typeLabel()),
                    'title' => e($absence->employee->name.': '.$absence->typeLabel()),
                ];
            }
        } else {
            $buildings = Building::query()
                ->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $shifts->pluck('building_id')))
                ->orderBy('name')->get();
            foreach ($buildings as $building) {
                $groups[] = [
                    'id' => $building->id,
                    'content' => sprintf('<span class="group-dot" style="background:%s"></span>%s', e($building->color), e($building->name)),
                ];
            }

            foreach ($shifts as $shift) {
                $all = collect($warnings[$shift->id] ?? [])->flatten(1)->all();
                $names = $shift->assignments->map(fn ($a) => $a->employee->name)->implode(', ');
                $label = $shift->title.': '.($names ?: '—');
                $extra = $shift->openSlots() > 0 ? 'item-has-open' : '';
                $item = $this->item($shift, "s-{$shift->id}", $shift->building_id, $label, $all, $extra);
                if ($shift->openSlots() > 0) {
                    $item['content'] .= sprintf(' <span class="badge-open">%d open</span>', $shift->openSlots());
                }
                $items[] = $item + ['employeeId' => null, 'editable' => ['updateTime' => true, 'updateGroup' => false, 'remove' => false]];
            }
        }

        foreach ($this->holidays->between($from, $to) as $date => $name) {
            $items[] = [
                'id' => 'hol-'.$date,
                'start' => $date,
                'end' => CarbonImmutable::parse($date)->addDay()->toDateString(),
                'type' => 'background',
                'className' => 'bg-holiday',
                'content' => e($name),
            ];
        }

        return response()->json(['groups' => $groups, 'items' => $items]);
    }

    /**
     * Verslepen in de Gantt: dienst verplaatsen in tijd en/of naar een andere medewerker.
     */
    public function move(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shift_id' => ['required', 'exists:shifts,id'],
            'from_employee_id' => ['nullable', 'exists:employees,id'],
            'to_employee_id' => ['nullable', 'exists:employees,id'],
            'shift_days' => ['nullable', 'integer', 'between:-366,366'],
            'starts_at' => ['required_without:shift_days', 'date'],
            'ends_at' => ['required_without:shift_days', 'date', 'after:starts_at'],
            'confirm' => ['boolean'],
        ]);

        $shift = Shift::with(['skills', 'assignments.employee'])->findOrFail($data['shift_id']);
        $from = isset($data['from_employee_id']) ? (int) $data['from_employee_id'] : null;
        $to = isset($data['to_employee_id']) ? (int) $data['to_employee_id'] : null;
        if (isset($data['shift_days'])) {
            // Week-/maandweergave: dienst een aantal dagen opschuiven met dezelfde (lokale) tijden.
            $startsAt = CarbonImmutable::instance($shift->starts_at)->addDays((int) $data['shift_days']);
            $endsAt = CarbonImmutable::instance($shift->ends_at)->addDays((int) $data['shift_days']);
        } else {
            $startsAt = CarbonImmutable::parse($data['starts_at'])->setTimezone(config('app.timezone'))->seconds(0);
            $endsAt = CarbonImmutable::parse($data['ends_at'])->setTimezone(config('app.timezone'))->seconds(0);
        }
        $timeChanged = ! $startsAt->equalTo($shift->starts_at) || ! $endsAt->equalTo($shift->ends_at);
        $employeeChanged = $from !== $to;

        if ($to && $employeeChanged && $shift->assignments->contains('employee_id', $to)) {
            return response()->json(['message' => 'Deze medewerker staat al op deze dienst.'], 422);
        }
        if (! $from && $to && $shift->openSlots() === 0) {
            return response()->json(['message' => 'Deze dienst is al volledig bezet.'], 422);
        }

        // Controleer de nieuwe situatie zonder op te slaan.
        $shift->starts_at = $startsAt;
        $shift->ends_at = $endsAt;
        $toCheck = $shift->assignments->pluck('employee_id')->reject(fn ($id) => $id === $from);
        if ($to) {
            $toCheck->push($to);
        }
        if (! $timeChanged) {
            $toCheck = collect($to && $employeeChanged ? [$to] : []);
        }
        $warnings = [];
        if ($toCheck->isNotEmpty()) {
            $names = Employee::whereIn('id', $toCheck)->pluck('name', 'id');
            foreach ($this->checker->forCandidates($shift, $toCheck->unique()->values()) as $id => $result) {
                foreach ($result['warnings'] as $w) {
                    $warnings[] = $w + ['employee' => $names[$id] ?? ''];
                }
            }
        }

        if (Planning::needsConfirmation($warnings) && ! ($data['confirm'] ?? false)) {
            return response()->json(['needs_confirmation' => true, 'warnings' => $warnings]);
        }

        DB::transaction(function () use ($shift, $from, $to, $employeeChanged, $startsAt) {
            $shift->is_modified = true;
            if ($shift->task_template_id === null) {
                $shift->date = $startsAt->toDateString();
            }
            $shift->save();

            if ($employeeChanged) {
                if ($from) {
                    $shift->assignments()->where('employee_id', $from)->delete();
                }
                if ($to) {
                    $shift->assignments()->create(['employee_id' => $to, 'source' => $from ? 'replacement' : 'manual']);
                }
            }
        });

        return response()->json(['ok' => true, 'warnings' => $warnings]);
    }

    /**
     * Gebeurtenissen voor de maandkalender (FullCalendar).
     */
    public function calendar(Request $request): JsonResponse
    {
        $data = $request->validate(['start' => ['required', 'date'], 'end' => ['required', 'date']]);
        $from = CarbonImmutable::parse($data['start'])->startOfDay();
        $to = CarbonImmutable::parse($data['end'])->startOfDay();
        abort_if($from->diffInDays($to) > 62, 422, 'Periode te lang.');

        $this->generator->generate($from, $to->subDay());

        $events = Shift::query()->active()->overlapping($from, $to)
            ->with(['building:id,name,color'])->withCount('assignments')
            ->orderBy('starts_at')->get()
            ->map(fn (Shift $s) => [
                'id' => 'shift-'.$s->id,
                'title' => sprintf('%s %s · %s (%d/%d)', $s->starts_at->format('H:i'), $s->building->name, $s->title, $s->assignments_count, $s->required_staff),
                'start' => $s->starts_at->toIso8601String(),
                'end' => $s->ends_at->toIso8601String(),
                'color' => $s->building->color,
                'classNames' => $s->assignments_count < $s->required_staff ? ['ev-open'] : [],
                'extendedProps' => ['shiftId' => $s->id],
            ])->all();

        $absences = Absence::query()->with('employee:id,name')
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get();
        foreach ($absences as $a) {
            $events[] = [
                'id' => 'abs-'.$a->id,
                'title' => $a->employee->name.' – '.strtolower($a->typeLabel()),
                'start' => $a->starts_at->toDateString(),
                'end' => $a->ends_at->addDay()->toDateString(),
                'allDay' => true,
                'color' => $a->type === 'ziek' ? '#dc2626' : '#6b7280',
                'classNames' => ['ev-absence'],
            ];
        }

        foreach ($this->holidays->between($from, $to->subDay()) as $date => $name) {
            $events[] = ['id' => 'hol-'.$date, 'title' => $name, 'start' => $date, 'allDay' => true, 'display' => 'background', 'color' => '#fde68a'];
            $events[] = ['id' => 'holt-'.$date, 'title' => '🎉 '.$name, 'start' => $date, 'allDay' => true, 'color' => '#b45309', 'classNames' => ['ev-holiday']];
        }

        return response()->json($events);
    }

    private function item(Shift $shift, string $id, int|string $group, string $label, array $warnings, string $extraClass = ''): array
    {
        $level = Planning::worstLevel($warnings);
        $title = collect($warnings)->pluck('message')->prepend(
            $shift->starts_at->format('H:i').'–'.$shift->ends_at->format('H:i').' '.$shift->building->name.' · '.$shift->title
        )->implode("\n");

        return [
            'id' => $id,
            'shiftId' => $shift->id,
            'group' => $group,
            'start' => $shift->starts_at->toIso8601String(),
            'end' => $shift->ends_at->toIso8601String(),
            'day' => $shift->starts_at->toDateString(),
            'startLabel' => $shift->starts_at->format('H:i'),
            'endLabel' => $shift->ends_at->format('H:i'),
            'content' => e($label),
            'title' => e($title),
            'className' => trim(implode(' ', array_filter([$extraClass, $level ? 'lvl-'.$level : null]))),
            'style' => 'border-left: 4px solid '.$shift->building->color,
        ];
    }
}

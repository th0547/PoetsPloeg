<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Services\AbsenceService;
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
    ) {}

    /**
     * Gegevens voor het roosterbord: één gestructureerde payload voor alle weergaven
     * (per medewerker, per pand, kalender; dag, week en maand). De opmaak gebeurt in de browser.
     */
    public function timeline(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'])->startOfDay();
        $to = CarbonImmutable::parse($data['to'])->startOfDay();
        abort_if($from->diffInDays($to) > 62, 422, 'Kies een periode van maximaal twee maanden.');

        $this->generator->generate($from, $to);
        $toExclusive = $to->addDay();

        $shifts = Shift::query()->active()
            ->overlapping($from, $toExclusive)
            ->with(['building:id,name,short_name,color', 'assignments.employee:id,name,color', 'skills:id,name'])
            ->orderBy('starts_at')
            ->get();
        $warnings = $this->checker->forShifts($shifts);

        $employees = Employee::query()
            ->where(fn ($q) => $q->where('active', true)
                ->orWhereIn('id', $shifts->flatMap->assignments->pluck('employee_id')))
            ->with(['skills:id', 'buildings:id', 'availabilityPatterns', 'availabilityDays'])
            ->orderBy('name')
            ->get();

        $buildings = Building::query()
            ->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $shifts->pluck('building_id')))
            ->orderBy('name')
            ->get();

        $absences = Absence::query()->notRejected()
            ->where('starts_at', '<', $toExclusive)->where('ends_at', '>', $from)
            ->orderBy('starts_at')
            ->get();

        $holidays = $this->holidays->between($from, $to);
        $days = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $days[] = [
                'date' => $day->toDateString(),
                'holiday' => $holidays[$day->toDateString()] ?? null,
            ];
        }

        $now = CarbonImmutable::now();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'today' => $now->toDateString(),
            'now_min' => Planning::minutes($now),
            'days' => $days,
            'employees' => $employees->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->name,
                'color' => $e->color,
                'initials' => Planning::initials($e->name),
                'active' => $e->active,
                'contract_min' => $e->contract_hours_min,
                'contract_max' => $e->contract_hours_max,
                'skills' => $e->skills->pluck('id')->all(),
                'buildings' => $e->buildings->pluck('id')->all(),
            ])->values(),
            'buildings' => $buildings->map(fn (Building $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'short' => $b->shortLabel(),
                'color' => $b->color,
                'city' => $b->city,
            ])->values(),
            'skills' => Skill::orderBy('name')->get(['id', 'name']),
            'shifts' => $shifts->map(fn (Shift $s) => $this->shiftRow($s, $warnings[$s->id] ?? []))->values(),
            'absences' => $absences->map(fn (Absence $a) => [
                'id' => $a->id,
                'employee_id' => $a->employee_id,
                'label' => $a->typeLabel(),
                'kind' => $a->leaveType()['counts_as'] ?? null,
                'pending' => $a->isPending(),
                'from_day' => $a->starts_at->toDateString(),
                'to_day' => $a->ends_at->toDateString(),
                'from_min' => Planning::minutes($a->starts_at),
                'to_min' => $a->ends_at->format('H:i') === '23:59' ? 1440 : Planning::minutes($a->ends_at),
                'url' => route('absences.show', $a),
            ])->values(),
            // Tijden waarop iemand volgens de opgegeven beschikbaarheid niet kan (alleen bij korte periodes).
            'unavailable' => $from->diffInDays($to) <= 14 ? $this->unavailable($employees, $from, $to) : (object) [],
        ]);
    }

    /**
     * @param  array<int, list<array>>  $warnings  per medewerker
     */
    private function shiftRow(Shift $shift, array $warnings): array
    {
        $startMin = Planning::minutes($shift->starts_at);

        return [
            'id' => $shift->id,
            'building_id' => $shift->building_id,
            'title' => $shift->title,
            // Dag en tijden in Nederlandse tijd, ongeacht de tijdzone van de browser.
            'day' => $shift->starts_at->toDateString(),
            'start' => $shift->starts_at->format('H:i'),
            'end' => $shift->ends_at->format('H:i'),
            'start_min' => $startMin,
            'end_min' => $startMin + (int) $shift->starts_at->diffInMinutes($shift->ends_at),
            'hours' => $shift->durationHours(),
            'required' => $shift->required_staff,
            'open' => $shift->openSlots(),
            'extra' => $shift->extraStaff(),
            'modified' => $shift->is_modified,
            'one_off' => $shift->task_template_id === null,
            'notes' => filled($shift->notes),
            'skills' => $shift->skills->pluck('name')->all(),
            'level' => Planning::worstLevel(collect($warnings)->flatten(1)->all()),
            'assignments' => $shift->assignments->map(fn ($a) => [
                'employee_id' => $a->employee_id,
                'source' => $a->source,
                'level' => Planning::worstLevel($warnings[$a->employee_id] ?? []),
                'warnings' => collect($warnings[$a->employee_id] ?? [])->map(fn ($w) => ['level' => $w['level'], 'message' => $w['message']])->all(),
            ])->values(),
        ];
    }

    /**
     * Per medewerker per dag wat de opgegeven beschikbaarheid zegt: een korte tekst ("kan 16:00–23:00",
     * "kan niet 08:00–15:00 · School") en de tijdvakken (minuten vanaf middernacht) waarop hij of zij niet kan.
     *
     * @return array<int, array<string, array{hint: string, whole: bool, blocks: list<array{from: int, to: int, note: ?string}>}>>
     */
    private function unavailable($employees, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $time = fn (int $m) => sprintf('%02d:%02d', intdiv($m % 1440, 60), $m % 60);
        $range = fn (array $w) => $time($w['from']).'–'.$time($w['to']);

        $result = [];
        foreach ($employees as $employee) {
            $schedule = $employee->availabilitySchedule();
            if ($schedule->isEmpty()) {
                continue;
            }
            for ($day = $from; $day->lte($to); $day = $day->addDay()) {
                $free = $schedule->freeMinutes($day);
                $info = $schedule->windowsOn($day);
                $can = collect($info['windows'])->where('type', 'available')->sortBy('from')->values();
                $cannot = collect($info['windows'])->where('type', 'unavailable')->sortBy('from')->values();

                $blocks = [];
                $start = null;
                for ($m = 0; $m <= 1440; $m++) {
                    $busy = $m < 1440 && ! $free[$m];
                    if ($busy && $start === null) {
                        $start = $m;
                    } elseif (! $busy && $start !== null) {
                        $note = $cannot->first(fn ($w) => $w['from'] < $m && $w['to'] > $start)['note'] ?? null;
                        $blocks[] = ['from' => $start, 'to' => $m, 'note' => $note];
                        $start = null;
                    }
                }
                if ($blocks === []) {
                    continue;
                }

                $whole = count($blocks) === 1 && $blocks[0]['from'] === 0 && $blocks[0]['to'] === 1440;
                $note = $cannot->pluck('note')->filter()->unique()->implode(', ');
                $hint = match (true) {
                    $whole => 'kan niet'.($note ? ' · '.$note : ''),
                    $info['rest'] === 'unavailable' => 'kan '.$can->map($range)->implode(', '),
                    default => 'kan niet '.$cannot->map($range)->implode(', ').($note ? ' · '.$note : ''),
                };
                $result[$employee->id][$day->toDateString()] = ['hint' => $hint, 'whole' => $whole, 'blocks' => $blocks];
            }
        }

        return $result;
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
            // Lokale (Nederlandse) tijden uit het rooster; eventueel samen met shift_days.
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time', 'different:start_time'],
            'starts_at' => ['required_without_all:shift_days,start_time', 'date'],
            'ends_at' => ['required_without_all:shift_days,start_time', 'date', 'after:starts_at'],
            'confirm' => ['boolean'],
        ]);

        $shift = Shift::with(['skills', 'assignments.employee'])->findOrFail($data['shift_id']);
        $from = isset($data['from_employee_id']) ? (int) $data['from_employee_id'] : null;
        $to = isset($data['to_employee_id']) ? (int) $data['to_employee_id'] : null;
        if (isset($data['start_time'])) {
            // Dagweergave: nieuwe lokale tijden, eventueel op een andere dag.
            $day = CarbonImmutable::instance($shift->starts_at)->startOfDay()->addDays((int) ($data['shift_days'] ?? 0));
            [$startsAt, $endsAt] = ShiftGenerator::timesFor($day, $data['start_time'], $data['end_time']);
        } elseif (isset($data['shift_days'])) {
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

        app(AbsenceService::class)->evaluateForShift($shift);

        return response()->json(['ok' => true, 'warnings' => $warnings]);
    }
}

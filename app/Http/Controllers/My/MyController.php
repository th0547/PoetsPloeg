<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\AvailabilityPattern;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AbsenceService;
use App\Services\LeaveCounters;
use App\Services\LeaveHours;
use App\Services\ShiftGenerator;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Het deel van PoetsPloeg voor medewerkers zelf: eigen planning, verlof aanvragen en
 * beschikbaarheid doorgeven. Alles gaat altijd over de ingelogde medewerker.
 */
class MyController extends Controller
{
    /** Aantal weken op de pagina "Mijn planning". */
    private const WEEKS = 4;

    public function planning(Request $request, ShiftGenerator $generator): View
    {
        $employee = $this->employee($request);
        $from = rescue(fn () => CarbonImmutable::parse($request->query('vanaf', 'today')), CarbonImmutable::today(), false)->startOfWeek();
        $to = $from->addWeeks(self::WEEKS);

        $generator->generate($from, $to->subDay());

        $shifts = Shift::query()->active()
            ->overlapping($from, $to)
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $employee->id))
            ->with(['building', 'employees'])
            ->orderBy('starts_at')
            ->get();

        $absences = $employee->absences()->notRejected()
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->get();

        $days = collect();
        for ($day = $from; $day->lt($to); $day = $day->addDay()) {
            $days->push([
                'date' => $day,
                'shifts' => $shifts->filter(fn (Shift $s) => $s->starts_at->isSameDay($day))->values(),
                'absences' => $absences->filter(fn (Absence $a) => $a->starts_at->lt($day->addDay()) && $a->ends_at->gt($day))->values(),
            ]);
        }

        return view('my.planning', [
            'employee' => $employee,
            'from' => $from,
            'to' => $to,
            'weeks' => $days->chunk(7),
            'hours' => $shifts->sum(fn (Shift $s) => $s->durationHours()),
            'shiftCount' => $shifts->count(),
        ]);
    }

    /** Statusfilter voor de geschiedenis. */
    public const HISTORY_FILTERS = ['alle' => 'Alle', 'approved' => 'Goedgekeurd', 'pending' => 'In afwachting', 'rejected' => 'Afgewezen', 'ingetrokken' => 'Ingetrokken'];

    public function absences(Request $request, LeaveCounters $counters): View
    {
        $employee = $this->employee($request);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::HISTORY_FILTERS))],
            'jaar' => ['nullable', 'integer', 'between:2000,2100'],
        ]);
        $status = $filters['status'] ?? 'alle';
        $year = (int) ($filters['jaar'] ?? now()->year);
        $absences = $employee->absences()->get();

        $history = Absence::withTrashed()->where('employee_id', $employee->id)
            ->where('ends_at', '<', now()->startOfDay())
            ->when($status === 'ingetrokken', fn ($q) => $q->onlyTrashed())
            ->when(! in_array($status, ['alle', 'ingetrokken'], true), fn ($q) => $q->where('status', $status)->whereNull('deleted_at'))
            ->when($filters['jaar'] ?? null, fn ($q) => $q->where('starts_at', '>=', CarbonImmutable::create($year, 1, 1))->where('starts_at', '<', CarbonImmutable::create($year + 1, 1, 1)))
            ->orderByDesc('starts_at')
            ->get();

        return view('my.absences', [
            'employee' => $employee,
            'current' => $absences->where('ends_at', '>=', now()->startOfDay())->values(),
            'history' => $history,
            'status' => $status,
            'year' => $year,
            'yearFiltered' => isset($filters['jaar']),
            'counters' => $counters->counters($employee, $year),
            'balance' => $counters->balance($employee, $year),
            'showBalance' => Policy::current()->get('vacation.show_balance_employees'),
        ]);
    }

    public function createAbsence(Request $request): View
    {
        $employee = $this->employee($request);
        // Dagen waarop je ingepland staat (komende maanden), voor de kalender: daar kies je een verlofsoort.
        $shiftDays = Shift::query()->active()
            ->where('starts_at', '>=', CarbonImmutable::today())
            ->where('starts_at', '<', CarbonImmutable::today()->addMonths(6))
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $employee->id))
            ->pluck('starts_at')->map(fn ($d) => $d->toDateString())->unique()->values()->all();

        return view('my.absence-create', [
            'types' => self::requestableTypes(),
            'shiftDays' => $shiftDays,
            'unavailableTypes' => Policy::current()->leaveTypes(true)->filter(fn ($t) => $t['counts_as'] === 'unavailable')->keys()->all(),
        ]);
    }

    /** Een verlofaanvraag is altijd een verzoek: goedgekeurd zodra elke dienst een vervanger heeft. */
    public function storeAbsence(Request $request, AbsenceService $service, LeaveHours $leaveHours): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::requestableTypes()))],
            'days' => ['required', 'array', 'min:1', 'max:366'],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct', 'after_or_equal:today'],
            'days.*.start_time' => ['nullable', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:1000'],
            'confirm_zero' => ['boolean'],
        ], [
            'days.required' => 'Kies minimaal één dag in de kalender.',
            'days.*.date.after_or_equal' => 'Je kunt geen verlof aanvragen voor een dag die al voorbij is.',
        ], [
            'days.*.date' => 'datum',
            'days.*.start_time' => 'begintijd',
            'days.*.end_time' => 'eindtijd',
        ]);
        $type = Policy::current()->leaveType($data['type']);
        $periods = $service->periodsFromDays($data['days']);

        // Regels per verlofsoort uit het beleid (issue #42).
        if (($type['note_required'] ?? false) && blank($data['note'] ?? null)) {
            throw ValidationException::withMessages(['note' => 'Geef een toelichting bij deze soort verlof.']);
        }
        $first = collect($periods)->min(fn ($p) => $p[0]);
        if (($type['min_days_ahead'] ?? 0) > 0 && $first->lt(CarbonImmutable::today()->addDays($type['min_days_ahead']))) {
            throw ValidationException::withMessages(['days' => "Vraag {$type['name']} minstens {$type['min_days_ahead']} dagen van tevoren aan."]);
        }

        // "Niet beschikbaar" kan alleen op dagen zonder dienst (issue #43).
        if ($type['counts_as'] === 'unavailable') {
            $busy = collect($periods)->flatMap(fn ($p) => Shift::query()->active()->overlapping($p[0], $p[1])
                ->whereHas('assignments', fn ($q) => $q->where('employee_id', $employee->id))->pluck('starts_at'));
            if ($busy->isNotEmpty()) {
                throw ValidationException::withMessages(['type' => 'Op '.$busy->map(fn ($d) => $d->translatedFormat('D j M'))->unique()->join(', ', ' en ')
                    .' sta je ingepland. Kies daarvoor een verlofsoort; "Niet beschikbaar" is alleen voor dagen zonder dienst.']);
            }
        } elseif (! $request->boolean('confirm_zero') && $type['counts_as'] !== 'sickness') {
            // Werk je deze dagen normaal niet, dan is het geen verlof: stel "Niet beschikbaar" voor.
            $hours = collect($periods)->sum(fn ($p) => $leaveHours->estimate($employee, $p[0], $p[1])['hours']);
            if ($hours <= 0 && array_key_exists('niet_beschikbaar', self::requestableTypes())) {
                throw ValidationException::withMessages(['confirm_zero' => 'Op deze dag(en) werk je normaal niet (0 uur), dus het telt niet als verlof. Kies "Niet beschikbaar", of vink hieronder aan dat je toch verlof wilt aanvragen.']);
            }
        }

        $direct = $type['direct'];
        $absences = DB::transaction(fn () => collect($periods)->map(fn (array $period) => Absence::create([
            'employee_id' => $employee->id,
            'type' => $data['type'],
            'starts_at' => $period[0],
            'ends_at' => $period[1],
            'note' => $data['note'] ?? null,
        ])));
        if (! $direct) {
            $absences->each(fn (Absence $absence) => $service->submitRequest($absence));
        }

        $hoursText = $direct ? '' : ' ('.rtrim(rtrim(number_format($absences->sum('hours'), 2, ',', ''), '0'), ',').' uur)';
        $pending = $absences->filter(fn (Absence $a) => $a->fresh()->isPending())->count();
        $message = match (true) {
            $direct => 'Doorgegeven: je bent op deze dag(en) niet beschikbaar.',
            $pending === 0 => "Je aanvraag{$hoursText} is direct goedgekeurd: je stond in deze periode niet ingepland.",
            default => "Je aanvraag{$hoursText} is ingediend. Je blijft voorlopig ingepland; zodra er voor al je diensten een vervanger is, wordt je verlof goedgekeurd.",
        };

        return redirect()->route('my.absences')->with('status', $message);
    }

    /** Een eigen aanvraag die nog in afwachting is, kun je zelf intrekken. */
    public function destroyAbsence(Request $request, Absence $absence): RedirectResponse
    {
        $employee = $this->employee($request);
        abort_unless($absence->employee_id === $employee->id, 404);
        abort_unless($absence->isPending(), 422, 'Alleen een aanvraag die nog in afwachting is, kun je zelf intrekken.');

        $absence->delete();

        return redirect()->route('my.absences')->with('status', 'Je aanvraag is ingetrokken.');
    }

    public function availability(Request $request): View
    {
        $employee = $this->employee($request)->load('availabilityPatterns');

        return view('my.availability', [
            'employee' => $employee,
            'patterns' => old('patterns', $employee->availabilityRows()),
            'absenceBlocks' => $employee->absenceBlocks(),
        ]);
    }

    /**
     * Beschikbaarheid doorgeven. Diensten waar je al op staat blijven staan: de planner ziet
     * een waarschuwing als een dienst buiten je nieuwe beschikbaarheid valt en lost dat op.
     */
    public function updateAvailability(Request $request): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate(AvailabilityPattern::rules());

        DB::transaction(fn () => $employee->replaceAvailability($data['patterns'] ?? []));

        return redirect()->route('my.availability')->with('status',
            'Je beschikbaarheid is opgeslagen. Diensten waarvoor je al bent ingepland blijven staan; heb je daar een probleem mee, overleg dan met je planner.');
    }

    /** Soorten die een medewerker zelf aanvraagt; ziek en calamiteit meld je direct bij de planner. */
    public static function requestableTypes(): array
    {
        return Policy::current()->requestableTypes();
    }

    private function employee(Request $request): Employee
    {
        return $request->user()->employee;
    }
}

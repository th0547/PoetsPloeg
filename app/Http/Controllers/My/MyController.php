<?php

namespace App\Http\Controllers\My;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\AvailabilityPattern;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AbsenceService;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
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

    public function absences(Request $request): View
    {
        $employee = $this->employee($request);
        $absences = $employee->absences()->get();

        return view('my.absences', [
            'employee' => $employee,
            'current' => $absences->where('ends_at', '>=', now()->startOfDay())->values(),
            'history' => $absences->where('ends_at', '<', now()->startOfDay())->sortByDesc('starts_at')->values(),
        ]);
    }

    public function createAbsence(Request $request): View
    {
        $this->employee($request);

        return view('my.absence-create', ['types' => self::requestableTypes()]);
    }

    /** Een verlofaanvraag is altijd een verzoek: goedgekeurd zodra elke dienst een vervanger heeft. */
    public function storeAbsence(Request $request, AbsenceService $service): RedirectResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(self::requestableTypes()))],
            'days' => ['required', 'array', 'min:1', 'max:366'],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct', 'after_or_equal:today'],
            'days.*.start_time' => ['nullable', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'days.required' => 'Kies minimaal één dag in de kalender.',
            'days.*.date.after_or_equal' => 'Je kunt geen verlof aanvragen voor een dag die al voorbij is.',
        ], [
            'days.*.date' => 'datum',
            'days.*.start_time' => 'begintijd',
            'days.*.end_time' => 'eindtijd',
        ]);

        $periods = $service->periodsFromDays($data['days']);
        $absences = DB::transaction(fn () => collect($periods)->map(fn (array $period) => Absence::create([
            'employee_id' => $employee->id,
            'type' => $data['type'],
            'starts_at' => $period[0],
            'ends_at' => $period[1],
            'note' => $data['note'] ?? null,
        ])));
        $absences->each(fn (Absence $absence) => $service->submitRequest($absence));

        $pending = $absences->filter(fn (Absence $a) => $a->fresh()->isPending())->count();
        $message = $pending === 0
            ? 'Je aanvraag is direct goedgekeurd: je stond in deze periode niet ingepland.'
            : 'Je aanvraag is ingediend. Je blijft voorlopig ingepland; zodra er voor al je diensten een vervanger is, wordt je verlof goedgekeurd.';

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
        return collect(Absence::TYPES)->except(Absence::UNPLANNED)->all();
    }

    private function employee(Request $request): Employee
    {
        return $request->user()->employee;
    }
}

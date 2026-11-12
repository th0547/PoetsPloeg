<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AbsenceService;
use App\Services\Audit;
use App\Services\LeaveHours;
use App\Support\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AbsenceController extends Controller
{
    /** Keuzes voor het filter "Periode". */
    public const PERIODS = [
        'huidig' => 'Huidig en gepland',
        'verleden' => 'Verleden',
        'alles' => 'Alles',
    ];

    /** Keuzes voor het filter "Status"; ingetrokken registraties staan alleen hier. */
    public const STATUS_FILTERS = [
        'pending' => 'In afwachting van vervanger',
        'approved' => 'Goedgekeurd',
        'rejected' => 'Afgewezen',
        'ingetrokken' => 'Ingetrokken',
    ];

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'medewerker' => ['nullable', 'integer'],
            'soort' => ['nullable', Rule::in(Policy::current()->leaveTypes()->keys()->all())],
            'status' => ['nullable', Rule::in(array_keys(self::STATUS_FILTERS))],
            'periode' => ['nullable', Rule::in(array_keys(self::PERIODS))],
        ]);
        // De oude link ?verleden=1 blijft werken.
        $period = $filters['periode'] ?? ($request->boolean('verleden') ? 'verleden' : 'huidig');
        $status = $filters['status'] ?? null;
        $withdrawn = $status === 'ingetrokken';
        $newestFirst = $period !== 'huidig';

        $absences = Absence::query()->with('employee')
            ->when($withdrawn, fn ($q) => $q->onlyTrashed())
            ->when($status && ! $withdrawn, fn ($q) => $q->where('status', $status))
            ->when($filters['medewerker'] ?? null, fn ($q, $id) => $q->where('employee_id', $id))
            ->when($filters['soort'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($period === 'huidig', fn ($q) => $q->where('ends_at', '>=', now()->startOfDay()))
            ->when($period === 'verleden', fn ($q) => $q->where('ends_at', '<', now()->startOfDay()))
            ->orderBy('starts_at', $newestFirst ? 'desc' : 'asc')
            ->paginate(50);

        return view('absences.index', [
            'absences' => $absences,
            'period' => $period,
            'status' => $status,
            'filters' => $filters,
            // Ook inactieve medewerkers: hun geschiedenis moet terug te vinden blijven.
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'active']),
        ]);
    }

    public function create(Request $request): View
    {
        return view('absences.create', [
            'employees' => Employee::active()->orderBy('name')->get(),
            'selected' => $request->integer('medewerker') ?: null,
        ]);
    }

    public function store(Request $request, AbsenceService $service, LeaveHours $leaveHours): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in(Policy::current()->leaveTypes(true)->keys()->all())],
            'as_request' => ['boolean'],
            'days' => ['required', 'array', 'min:1', 'max:366'],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'days.*.start_time' => ['nullable', 'date_format:H:i'],
            'days.*.end_time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [
            'days.required' => 'Kies minimaal één dag in de kalender.',
        ], [
            'days.*.date' => 'datum',
            'days.*.start_time' => 'begintijd',
            'days.*.end_time' => 'eindtijd',
        ]);

        $periods = $service->periodsFromDays($data['days']);
        $asRequest = ($data['as_request'] ?? false) && ! in_array($data['type'], Policy::current()->directTypes(), true);

        $absences = DB::transaction(fn () => collect($periods)->map(fn (array $period) => Absence::create([
            'employee_id' => $data['employee_id'],
            'type' => $data['type'],
            'starts_at' => $period[0],
            'ends_at' => $period[1],
            'note' => $data['note'] ?? null,
        ])));

        if ($asRequest) {
            $absences->each(fn (Absence $absence) => $service->submitRequest($absence));
            $shiftCount = $absences->sum(fn (Absence $absence) => $absence->shifts()->count());
            $message = $this->requestMessage($absences->count(), $shiftCount);
        } else {
            $affected = $absences->sum(fn (Absence $absence) => $service->apply($absence)->count());
            $message = $this->registeredMessage($absences->count(), $affected, $absences->first()->employee->name);
            // Ziek tijdens vakantie: die uren tellen niet als vakantie (cao art. 29 lid 2).
            if (Policy::current()->leaveType($data['type'])['counts_as'] === 'sickness') {
                $moved = $absences->sum(fn (Absence $absence) => $leaveHours->applySickness($absence));
                if ($moved > 0) {
                    $message .= ' '.rtrim(rtrim(number_format($moved, 2, ',', ''), '0'), ',').' uur vakantie in deze periode telt nu als ziekte.';
                }
            }
        }

        // Eén periode: direct door naar de vervangers. Meerdere losse periodes: het overzicht van deze medewerker.
        $target = $absences->count() === 1
            ? route('absences.show', $absences->first())
            : route('absences.index', ['medewerker' => $data['employee_id'], 'periode' => 'alles']);

        return redirect($target)->with('status', $message);
    }

    public function show(Absence $absence, AbsenceService $service): View
    {
        $absence->load('employee');

        // Een verzoek (in afwachting of afgewezen), of een goedgekeurd verzoek waarvoor diensten vervangen zijn.
        if ($absence->status !== 'approved' || $absence->shifts()->exists()) {
            $service->evaluate($absence);
            $absence->refresh();

            return view('absences.request', [
                'absence' => $absence,
                'rows' => $service->requestShifts($absence),
            ]);
        }

        // Alle diensten in deze periode met open plekken, zodat de planner direct vervangers kan zoeken.
        $openShifts = Shift::query()->active()
            ->overlapping($absence->starts_at, $absence->ends_at)
            ->where('ends_at', '>', now())
            ->withCount('assignments')
            ->with('building')
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Shift $s) => $s->assignments_count < $s->required_staff)
            ->values();

        return view('absences.show', compact('absence', 'openShifts'));
    }

    /** Verlofuren aanpassen (de schatting klopt niet, of er is iets afgesproken). */
    public function updateHours(Request $request, Absence $absence): RedirectResponse
    {
        $data = $request->validate([
            'hours' => ['required', 'numeric', 'min:0', 'max:9999'],
            'days' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        $old = $absence->hoursLabel();
        $absence->forceFill(['hours' => $data['hours'], 'days' => $data['days'] ?? $absence->days, 'hours_source' => 'handmatig'])->save();
        Audit::log('absence.hours', "Verlofuren van {$absence->employee->name} aangepast: {$old} → {$absence->hoursLabel()}", $absence);

        return back()->with('status', 'Uren bijgewerkt.');
    }

    public function reject(Absence $absence, AbsenceService $service): RedirectResponse
    {
        abort_unless($absence->isPending(), 422, 'Alleen een openstaand verlofverzoek kan worden afgewezen.');
        $service->reject($absence);

        return redirect()->route('absences.show', $absence)
            ->with('status', 'Verlofverzoek afgewezen. '.$absence->employee->name.' blijft ingepland op de diensten die nog niet vervangen waren.');
    }

    /** Intrekken: de registratie telt niet meer mee, maar blijft bewaard in de geschiedenis. */
    public function destroy(Absence $absence): RedirectResponse
    {
        $absence->delete();

        return redirect()->route('absences.index', ['medewerker' => $absence->employee_id, 'status' => 'ingetrokken', 'periode' => 'alles'])
            ->with('status', 'Afwezigheid ingetrokken; de registratie blijft bewaard onder status "Ingetrokken". Controleer de planning: eerder vrijgemaakte diensten worden niet automatisch teruggezet.');
    }

    private function registeredMessage(int $periods, int $affected, string $name): string
    {
        $what = $periods === 1 ? 'Afwezigheid geregistreerd.' : sprintf('Afwezigheid geregistreerd in %d losse periodes.', $periods);
        if ($affected === 0) {
            return $what.' Er waren geen diensten ingepland in deze '.($periods === 1 ? 'periode.' : 'periodes.');
        }

        return $what.sprintf(' %s is van %d dienst(en) gehaald; ', $name, $affected)
            .($periods === 1 ? 'zoek hieronder een vervanger.' : 'open een periode om vervangers te zoeken.');
    }

    private function requestMessage(int $requests, int $shifts): string
    {
        if ($shifts === 0) {
            return $requests === 1
                ? 'Verlofverzoek direct goedgekeurd: er stonden geen diensten in deze periode.'
                : sprintf('%d verlofverzoeken direct goedgekeurd: er stonden geen diensten in deze periodes.', $requests);
        }
        $todo = sprintf('Zoek voor %d %s een vervanger; daarna wordt het verlof automatisch goedgekeurd.', $shifts, $shifts === 1 ? 'dienst' : 'diensten');

        return $requests === 1
            ? 'Verlofverzoek ingediend. '.$todo
            : sprintf('%d verlofverzoeken ingediend (losse periodes). ', $requests).$todo;
    }
}

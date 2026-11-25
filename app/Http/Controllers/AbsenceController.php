<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\Employee;
use App\Services\AbsenceRegistration;
use App\Services\AbsenceService;
use App\Services\Audit;
use App\Services\LeaveCounters;
use App\Support\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function store(Request $request, AbsenceRegistration $registration): RedirectResponse
    {
        $data = $request->validate(...AbsenceRegistration::rules());
        ['absences' => $absences, 'message' => $message] = $registration->register($data);

        // Eén periode: direct door naar de vervangers. Meerdere losse periodes: het overzicht van deze medewerker.
        $target = $absences->count() === 1
            ? route('absences.show', $absences->first())
            : route('absences.index', ['medewerker' => $data['employee_id'], 'periode' => 'alles']);

        return redirect($target)->with('status', $message);
    }

    /**
     * Het vervangscherm: alle diensten die door deze afwezigheid een vervanger nodig hebben, met per
     * dienst de beste kandidaten. Voor directe uitval (ziek) en voor verlofverzoeken.
     */
    public function show(Absence $absence, AbsenceRegistration $registration): View
    {
        $absence->load('employee');

        return view('absences.show', ['absence' => $absence] + $registration->replacements($absence));
    }

    /** CSV per medewerker per soort voor de loonadministratie (issue #44). */
    public function export(Request $request, LeaveCounters $counters): Response
    {
        $year = (int) $request->integer('jaar', (int) now()->year);
        Audit::log('absences.exported', "Verloftellers {$year} geëxporteerd (CSV)");

        return response($counters->csv($year), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"verlof-{$year}.csv\"",
        ]);
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
}

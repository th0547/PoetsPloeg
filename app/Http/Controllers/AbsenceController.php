<?php

namespace App\Http\Controllers;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\Shift;
use App\Services\AbsenceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AbsenceController extends Controller
{
    public function index(Request $request): View
    {
        $past = $request->boolean('verleden');
        $absences = Absence::query()->with('employee')
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when(! $past, fn ($q) => $q->where('ends_at', '>=', now()->startOfDay()))
            ->when($past, fn ($q) => $q->where('ends_at', '<', now()->startOfDay()))
            ->orderBy($past ? 'ends_at' : 'starts_at', $past ? 'desc' : 'asc')
            ->paginate(50);

        return view('absences.index', compact('absences', 'past'));
    }

    public function create(Request $request): View
    {
        return view('absences.create', [
            'employees' => Employee::active()->orderBy('name')->get(),
            'selected' => $request->integer('medewerker') ?: null,
        ]);
    }

    public function store(Request $request, AbsenceService $service): RedirectResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'type' => ['required', Rule::in(array_keys(Absence::TYPES))],
            'start_date' => ['required', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $startsAt = CarbonImmutable::parse($data['start_date'].' '.($data['start_time'] ?? '00:00'));
        $endsAt = empty($data['end_time'])
            ? CarbonImmutable::parse($data['end_date'])->endOfDay()->startOfMinute()
            : CarbonImmutable::parse($data['end_date'].' '.$data['end_time']);
        if ($endsAt->lte($startsAt)) {
            return back()->withInput()->withErrors(['end_time' => 'Het einde moet na het begin liggen.']);
        }

        $absence = Absence::create([
            'employee_id' => $data['employee_id'],
            'type' => $data['type'],
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'note' => $data['note'] ?? null,
        ]);

        if ($absence->type === 'verzoek') {
            $service->submitRequest($absence);
            $count = $absence->shifts()->count();

            return redirect()->route('absences.show', $absence)->with('status', $absence->fresh()->status === 'approved'
                ? 'Verlofverzoek direct goedgekeurd: er stonden geen diensten in deze periode.'
                : sprintf('Verlofverzoek ingediend. Zoek voor %d %s een vervanger; daarna wordt het verlof automatisch goedgekeurd.',
                    $count, $count === 1 ? 'dienst' : 'diensten'));
        }

        $affected = $service->apply($absence);

        return redirect()->route('absences.show', $absence)->with('status', $affected->isEmpty()
            ? 'Afwezigheid geregistreerd. Er waren geen diensten ingepland in deze periode.'
            : sprintf('Afwezigheid geregistreerd. %s is van %d dienst(en) gehaald; zoek hieronder een vervanger.', $absence->employee->name, $affected->count()));
    }

    public function show(Absence $absence, AbsenceService $service): View
    {
        $absence->load('employee');

        if ($absence->type === 'verzoek' || $absence->shifts()->exists()) {
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

    public function reject(Absence $absence, AbsenceService $service): RedirectResponse
    {
        abort_unless($absence->isPending(), 422, 'Alleen een openstaand verlofverzoek kan worden afgewezen.');
        $service->reject($absence);

        return redirect()->route('absences.show', $absence)
            ->with('status', 'Verlofverzoek afgewezen. '.$absence->employee->name.' blijft ingepland op de diensten die nog niet vervangen waren.');
    }

    public function destroy(Absence $absence): RedirectResponse
    {
        $absence->delete();

        return redirect()->route('absences.index')->with('status', 'Afwezigheid verwijderd. Controleer de planning: eerder vrijgemaakte diensten worden niet automatisch teruggezet.');
    }
}

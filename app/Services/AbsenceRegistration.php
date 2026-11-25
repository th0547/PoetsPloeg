<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Shift;
use App\Support\Policy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Afwezigheid registreren door een planner (direct of als verlofverzoek) en het vervangscherm.
 * Gedeeld door de website (AbsenceController) en de app (Api\V1\AbsenceController).
 */
class AbsenceRegistration
{
    public function __construct(private AbsenceService $service, private LeaveHours $leaveHours) {}

    /** Validatieregels, meldingen en veldnamen voor het registreren. */
    public static function rules(): array
    {
        return [
            [
                'employee_id' => ['required', 'exists:employees,id'],
                'type' => ['required', Rule::in(Policy::current()->leaveTypes(true)->keys()->all())],
                'as_request' => ['boolean'],
                'days' => ['required', 'array', 'min:1', 'max:366'],
                'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct'],
                'days.*.start_time' => ['nullable', 'date_format:H:i'],
                'days.*.end_time' => ['nullable', 'date_format:H:i'],
                'note' => ['nullable', 'string', 'max:1000'],
            ],
            ['days.required' => 'Kies minimaal één dag in de kalender.'],
            ['days.*.date' => 'datum', 'days.*.start_time' => 'begintijd', 'days.*.end_time' => 'eindtijd'],
        ];
    }

    /**
     * Registreer de gekozen dagen (aaneengesloten dagen worden één periode). Direct: de medewerker gaat
     * meteen van de diensten af. Als verzoek: de medewerker blijft ingepland tot er vervangers zijn.
     *
     * @return array{absences: Collection<int, Absence>, message: string}
     */
    public function register(array $data): array
    {
        $periods = $this->service->periodsFromDays($data['days']);
        $asRequest = ($data['as_request'] ?? false) && ! in_array($data['type'], Policy::current()->directTypes(), true);

        $absences = DB::transaction(fn () => collect($periods)->map(fn (array $period) => Absence::create([
            'employee_id' => $data['employee_id'],
            'type' => $data['type'],
            'starts_at' => $period[0],
            'ends_at' => $period[1],
            'note' => $data['note'] ?? null,
        ])));

        if ($asRequest) {
            $absences->each(fn (Absence $absence) => $this->service->submitRequest($absence));
            $shiftCount = $absences->sum(fn (Absence $absence) => $absence->shifts()->count());

            return ['absences' => $absences, 'message' => $this->requestMessage($absences->count(), $shiftCount)];
        }

        $affected = $absences->sum(fn (Absence $absence) => $this->service->apply($absence)->count());
        $message = $this->registeredMessage($absences->count(), $affected, $absences->first()->employee->name);
        // Ziek tijdens vakantie: die uren tellen niet als vakantie (cao art. 29 lid 2).
        if (Policy::current()->leaveType($data['type'])['counts_as'] === 'sickness') {
            $moved = $absences->sum(fn (Absence $absence) => $this->leaveHours->applySickness($absence));
            if ($moved > 0) {
                $message .= ' '.rtrim(rtrim(number_format($moved, 2, ',', ''), '0'), ',').' uur vakantie in deze periode telt nu als ziekte.';
            }
        }

        return ['absences' => $absences, 'message' => $message];
    }

    /**
     * Het vervangscherm: de diensten die door deze afwezigheid een vervanger nodig hebben, met per dienst
     * de stand ('todo': aanvrager staat er nog op, 'open': plek open, 'done': vervangen of geannuleerd).
     *
     * @return array{rows: Collection<int, array{shift: Shift, state: string}>, linked: bool}
     */
    public function replacements(Absence $absence): array
    {
        if ($absence->isPending()) {
            $this->service->evaluate($absence);
            $absence->refresh();
        }

        $rows = $this->service->requestShifts($absence);
        $linked = $rows->isNotEmpty();
        if (! $linked && $absence->status === 'approved') {
            // Oudere registratie, van vóór het vastleggen van de vrijgemaakte diensten: toon alle
            // open diensten in de periode.
            $rows = Shift::query()->active()->understaffed()
                ->overlapping($absence->starts_at, $absence->ends_at)
                ->where('ends_at', '>', now())
                ->with(['building', 'assignments.employee'])
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Shift $shift) => ['shift' => $shift, 'state' => 'open']);
        }

        // Bij een gekoppelde lijst weten we welke diensten van deze medewerker waren.
        return ['rows' => $rows, 'linked' => $linked];
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

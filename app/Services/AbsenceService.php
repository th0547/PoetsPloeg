<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Verwerkt verlof, uitval en verlofverzoeken.
 *
 * - Direct geregistreerd (ziek, verlof, huisarts, ...): de medewerker wordt direct van getroffen diensten gehaald.
 * - Als verzoek ingediend: de medewerker blijft ingepland tot er per dienst een vervanger is gevonden;
 *   zodra alle diensten vervangen zijn, wordt het verzoek automatisch goedgekeurd.
 */
class AbsenceService
{
    /**
     * Diensten (vanaf nu) waarop de medewerker staat tijdens de afwezigheid.
     *
     * @return Collection<int, Shift>
     */
    public function affectedShifts(Absence $absence): Collection
    {
        return Shift::query()
            ->active()
            ->overlapping($absence->starts_at, $absence->ends_at)
            ->where('ends_at', '>', now())
            ->whereHas('assignments', fn ($q) => $q->where('employee_id', $absence->employee_id))
            ->with('building')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Haal de medewerker van alle getroffen diensten af.
     *
     * @return Collection<int, Shift> de diensten die nu (deels) open staan
     */
    public function apply(Absence $absence): Collection
    {
        $shifts = $this->affectedShifts($absence);

        DB::transaction(function () use ($shifts, $absence) {
            foreach ($shifts as $shift) {
                $shift->assignments()->where('employee_id', $absence->employee_id)->delete();
                $shift->update(['is_modified' => true]);
            }
        });

        return $shifts;
    }

    /**
     * Nieuw verlofverzoek: leg vast voor welke diensten een vervanger nodig is.
     * Zonder diensten in de periode wordt het verzoek meteen goedgekeurd.
     */
    public function submitRequest(Absence $absence): void
    {
        $absence->update(['status' => 'pending']);
        $this->evaluate($absence);
    }

    /**
     * Per dienst van het verzoek: 'todo' (aanvrager staat er nog op), 'open' (aanvrager eraf, plek nog open)
     * of 'done' (vervangen of dienst geannuleerd).
     *
     * @return Collection<int, array{shift: Shift, state: string}>
     */
    public function requestShifts(Absence $absence): Collection
    {
        return $absence->shifts()
            ->with(['building', 'assignments.employee'])
            ->orderBy('starts_at')
            ->get()
            ->map(function (Shift $shift) use ($absence) {
                $state = match (true) {
                    $shift->cancelled_at !== null => 'done',
                    $shift->assignments->contains('employee_id', $absence->employee_id) => 'todo',
                    $shift->openSlots() > 0 => 'open',
                    default => 'done',
                };

                return ['shift' => $shift, 'state' => $state];
            });
    }

    /**
     * Keur een openstaand verzoek goed als alle diensten vervangen zijn.
     *
     * @return bool of het verzoek (nu) is goedgekeurd
     */
    public function evaluate(Absence $absence): bool
    {
        if (! $absence->isPending()) {
            return $absence->status === 'approved';
        }

        // Diensten die er sinds het indienen bij zijn gekomen (bijv. nieuw ingepland) ook meenemen.
        $absence->shifts()->syncWithoutDetaching($this->affectedShifts($absence)->pluck('id'));

        $pending = $this->requestShifts($absence)->contains(fn ($row) => $row['state'] !== 'done');
        if ($pending) {
            return false;
        }

        // De soort (verlof, verhuisdag, huisarts, ...) blijft staan: die is nodig voor de administratie.
        $absence->update(['status' => 'approved', 'decided_at' => now()]);
        // Voor de zekerheid: de medewerker van eventuele overige diensten in de periode halen.
        $this->apply($absence);

        return true;
    }

    /**
     * Na een wijziging in de bezetting van een dienst: openstaande verzoeken die erbij horen opnieuw beoordelen.
     *
     * @return Collection<int, Absence> de verzoeken die daardoor zijn goedgekeurd
     */
    public function evaluateForShift(Shift $shift): Collection
    {
        return Absence::query()
            ->where('status', 'pending')
            ->whereHas('shifts', fn ($q) => $q->where('shifts.id', $shift->id))
            ->get()
            ->filter(fn (Absence $a) => $this->evaluate($a))
            ->values();
    }

    public function reject(Absence $absence): void
    {
        $absence->update(['status' => 'rejected', 'decided_at' => now()]);
    }

    /**
     * Zet de gekozen dagen om in aaneengesloten periodes: dagen die op elkaar aansluiten
     * (tot 23:59 en de volgende dag vanaf 00:00) worden één afwezigheid.
     *
     * @param  array<int, array{date: string, start_time?: ?string, end_time?: ?string}>  $days
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function periodsFromDays(array $days): array
    {
        $rows = [];
        foreach ($days as $i => $day) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $day['date']);
            $start = $date->setTimeFromTimeString($day['start_time'] ?? '00:00');
            $end = $date->setTimeFromTimeString($day['end_time'] ?? '23:59');
            if ($end->lte($start)) {
                throw ValidationException::withMessages([
                    "days.{$i}.end_time" => sprintf('De eindtijd moet na de begintijd liggen (%s).', $date->translatedFormat('D j M')),
                ]);
            }
            $rows[] = [$start, $end];
        }
        usort($rows, fn ($a, $b) => $a[0] <=> $b[0]);

        $periods = [];
        foreach ($rows as [$start, $end]) {
            $last = count($periods) - 1;
            $adjoins = $last >= 0
                && $periods[$last][1]->format('H:i') === '23:59'
                && $start->equalTo($periods[$last][1]->addDay()->startOfDay());
            if ($adjoins) {
                $periods[$last][1] = $end;
            } else {
                $periods[] = [$start, $end];
            }
        }

        return $periods;
    }
}

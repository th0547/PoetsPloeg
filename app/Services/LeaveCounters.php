<?php

namespace App\Services;

use App\Models\Absence;
use App\Models\Employee;
use App\Models\VacationBalance;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Verloftellers en indicatief vakantiesaldo per medewerker per jaar (issue #44).
 * Gebaseerd op de uren per afwezigheid (issue #43). De loonadministratie is leidend.
 */
class LeaveCounters
{
    public const CARE_GROUP = 'Zorg/kort verzuim';

    /**
     * Tellers per soort: [label => [approved_hours, approved_days, pending_hours, pending_days, counts_as]].
     * Zorgsoorten worden (volgens het beleid) samengevoegd; "Niet beschikbaar" telt niet mee.
     *
     * @return Collection<string, array>
     */
    public function counters(Employee $employee, int $year): Collection
    {
        $policy = Policy::current();
        $group = $policy->get('privacy.group_care_types');

        return $this->absences($employee, $year)
            ->reject(fn (Absence $a) => in_array($a->status, ['rejected'], true))
            ->reject(fn (Absence $a) => ($a->leaveType()['counts_as'] ?? null) === 'unavailable')
            ->groupBy(function (Absence $a) use ($group) {
                $type = $a->leaveType();

                return $group && ($type['counts_as'] ?? null) === 'care' ? self::CARE_GROUP : $a->typeLabel();
            })
            ->map(fn (Collection $items) => [
                'approved_hours' => round($items->where('status', 'approved')->sum('hours'), 2),
                'approved_days' => (int) $items->where('status', 'approved')->sum('days'),
                'pending_hours' => round($items->where('status', 'pending')->sum('hours'), 2),
                'pending_days' => (int) $items->where('status', 'pending')->sum('days'),
                'counts_as' => $items->first()->leaveType()['counts_as'] ?? 'other',
            ])
            ->sortKeys();
    }

    /**
     * Indicatief vakantiesaldo: beginsaldo + opbouw volgens contract + opbouw over extra uren − opgenomen.
     *
     * @return array{opening: float, contract_accrual: float, extra_hours: float, extra_accrual: float, taken: float, pending: float, balance: float, percent: float, contract_hours: float, has_opening: bool}
     */
    public function balance(Employee $employee, int $year): array
    {
        $policy = Policy::at(CarbonImmutable::create($year, 12, 31));
        $percent = $policy->get('vacation.accrual_statutory_percent') + $policy->get('vacation.accrual_extra_percent');
        $contract = (float) ($employee->contract_hours_min ?? $employee->contract_hours_max ?? 0);
        $opening = VacationBalance::where('employee_id', $employee->id)->where('year', $year)->first();

        $vacation = $this->absences($employee, $year)->filter(fn (Absence $a) => ($a->leaveType()['counts_as'] ?? null) === 'vacation');
        $taken = round($vacation->where('status', 'approved')->sum('hours'), 2);
        $pending = round($vacation->where('status', 'pending')->sum('hours'), 2);

        $extraHours = $policy->get('vacation.accrual_over_extra_hours') ? $this->extraHours($employee, $year, $contract) : 0.0;
        $contractAccrual = round($contract * 52 * $percent / 100, 2);
        $extraAccrual = round($extraHours * $percent / 100, 2);
        $openingHours = (float) ($opening->opening_hours ?? 0);

        return [
            'opening' => $openingHours,
            'has_opening' => $opening !== null,
            'contract_hours' => $contract,
            'percent' => round($percent, 2),
            'contract_accrual' => $contractAccrual,
            'extra_hours' => $extraHours,
            'extra_accrual' => $extraAccrual,
            'taken' => $taken,
            'pending' => $pending,
            'balance' => round($openingHours + $contractAccrual + $extraAccrual - $taken, 2),
        ];
    }

    /** CSV voor de loonadministratie: per medewerker per soort de goedgekeurde uren en dagen. */
    public function csv(int $year): string
    {
        $rows = [['medewerker', 'soort', 'telt als', 'uren', 'dagen', 'aangevraagd (uren)']];
        foreach (Employee::orderBy('name')->get() as $employee) {
            foreach ($this->counters($employee, $year) as $label => $c) {
                $rows[] = [$employee->name, $label, config('poetsploeg-cao.counts_as')[$c['counts_as']] ?? $c['counts_as'],
                    $this->num($c['approved_hours']), $c['approved_days'], $this->num($c['pending_hours'])];
            }
        }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM, zodat Excel de tekens goed leest
        foreach ($rows as $row) {
            // Geen formules: waarden die met = + - @ beginnen krijgen een apostrof.
            fputcsv($out, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row), ';');
        }
        rewind($out);

        return stream_get_contents($out);
    }

    /** Afwezigheid die in dit jaar begint (niet ingetrokken). */
    private function absences(Employee $employee, int $year): Collection
    {
        return Absence::query()
            ->where('employee_id', $employee->id)
            ->where('starts_at', '>=', CarbonImmutable::create($year, 1, 1))
            ->where('starts_at', '<', CarbonImmutable::create($year + 1, 1, 1))
            ->get();
    }

    /** Gewerkte uren boven het contract (per week, tot en met vandaag), op basis van ingeplande diensten. */
    private function extraHours(Employee $employee, int $year, float $contract): float
    {
        $from = CarbonImmutable::create($year, 1, 1);
        $to = min(CarbonImmutable::create($year + 1, 1, 1), CarbonImmutable::now());
        if ($to->lte($from)) {
            return 0.0;
        }
        $rows = DB::table('shift_assignments')
            ->join('shifts', 'shifts.id', '=', 'shift_assignments.shift_id')
            ->where('shift_assignments.employee_id', $employee->id)
            ->whereNull('shifts.cancelled_at')
            ->where('shifts.starts_at', '>=', $from)
            ->where('shifts.ends_at', '<=', $to)
            ->get(['shifts.starts_at', 'shifts.ends_at']);

        return round($rows
            ->groupBy(fn ($r) => CarbonImmutable::parse($r->starts_at)->startOfWeek()->toDateString())
            ->sum(fn ($week) => max(0, $week->sum(fn ($r) => CarbonImmutable::parse($r->starts_at)->diffInMinutes(CarbonImmutable::parse($r->ends_at)) / 60) - $contract)), 2);
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }
}

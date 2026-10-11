<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Shift;

/**
 * Zoekt en rangschikt geschikte vervangers voor een dienst.
 */
class ReplacementFinder
{
    public function __construct(private ConflictChecker $checker)
    {
    }

    /**
     * @return array{candidates: list<array>, excluded: list<array>}
     */
    public function candidates(Shift $shift): array
    {
        $shift->loadMissing(['skills', 'assignments']);
        $assigned = $shift->assignments->pluck('employee_id');

        $employees = Employee::active()->whereNotIn('id', $assigned)->orderBy('name')->get(['id', 'name', 'color', 'phone']);
        $results = $this->checker->forCandidates($shift, $employees->pluck('id'));

        $candidates = [];
        $excluded = [];
        foreach ($employees as $employee) {
            $checks = $results[$employee->id]['checks'];
            $row = [
                'id' => $employee->id,
                'name' => $employee->name,
                'color' => $employee->color,
                'phone' => $employee->phone,
                'week_hours' => $checks['week_hours'],
                'reasons' => $this->reasons($checks),
                'warnings' => $results[$employee->id]['warnings'],
            ];

            if ($checks['absent'] || $checks['overlap']) {
                $excluded[] = $row;

                continue;
            }

            $row['score'] = $this->score($checks);
            $candidates[] = $row;
        }

        usort($candidates, fn ($a, $b) => [$b['score'], $a['week_hours'], $a['name']] <=> [$a['score'], $b['week_hours'], $b['name']]);

        return ['candidates' => $candidates, 'excluded' => $excluded];
    }

    private function score(array $checks): int
    {
        $score = match ($checks['availability']) {
            'ok' => 40,
            'none' => 20,
            default => 0,
        };
        $score += $checks['missing_skills'] === [] ? 30 : 0;
        $score += $checks['knows_building'] ? 20 : 0;
        $max = $checks['contract_max'];
        $score += ($max === null || $checks['new_week_hours'] <= $max) ? 10 : 0;

        return $score;
    }

    /**
     * @return list<array{ok: bool|null, text: string}>
     */
    private function reasons(array $checks): array
    {
        $reasons = [];

        if ($checks['absent']) {
            $reasons[] = ['ok' => false, 'text' => 'Afwezig'];
        }
        if ($checks['overlap']) {
            $reasons[] = ['ok' => false, 'text' => 'Al ingepland op dat moment'];
        }

        $reasons[] = match ($checks['availability']) {
            'ok' => ['ok' => true, 'text' => 'Beschikbaar'],
            'none' => ['ok' => null, 'text' => 'Geen weekpatroon ingevuld'],
            default => ['ok' => false, 'text' => 'Buiten beschikbaarheid'],
        };

        $reasons[] = $checks['missing_skills'] === []
            ? ['ok' => true, 'text' => 'Heeft vereiste vaardigheden']
            : ['ok' => false, 'text' => 'Mist: '.implode(', ', $checks['missing_skills'])];

        $reasons[] = $checks['knows_building']
            ? ['ok' => true, 'text' => 'Kent dit pand']
            : ['ok' => false, 'text' => 'Kent pand niet'];

        $max = $checks['contract_max'];
        $hours = $this->num($checks['week_hours']).' → '.$this->num($checks['new_week_hours']).' u'
            .($max !== null ? ' (max '.$this->num($max).')' : '');
        $reasons[] = ['ok' => $max === null ? null : $checks['new_week_hours'] <= $max, 'text' => 'Week: '.$hours];

        return $reasons;
    }

    private function num(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ''), '0'), ',');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\Skill;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Losse, eenmalige opdrachten (zonder taaksjabloon).
 */
class ShiftController extends Controller
{
    public function create(Request $request): View
    {
        return view('shifts.create', [
            'buildings' => Building::active()->orderBy('name')->get(),
            'skills' => Skill::orderBy('name')->get(),
            'employees' => Employee::active()->orderBy('name')->get(),
            'selectedBuilding' => $request->integer('pand') ?: null,
            'date' => $request->query('datum', today()->toDateString()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'building_id' => ['required', 'exists:buildings,id'],
            'title' => ['required', 'string', 'max:255'],
            'date' => ['required', 'date'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'required_staff' => ['required', 'integer', 'min:1', 'max:50'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'skills' => ['array'],
            'skills.*' => ['exists:skills,id'],
            'employees' => ['array'],
            'employees.*' => ['exists:employees,id'],
        ]);

        [$startsAt, $endsAt] = ShiftGenerator::timesFor(CarbonImmutable::parse($data['date']), $data['start_time'], $data['end_time']);

        DB::transaction(function () use ($data, $startsAt, $endsAt) {
            $shift = Shift::create([
                'building_id' => $data['building_id'],
                'date' => $startsAt->toDateString(),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'required_staff' => $data['required_staff'],
                'title' => $data['title'],
                'notes' => $data['notes'] ?? null,
                'is_modified' => true,
            ]);
            $shift->skills()->sync($data['skills'] ?? []);
            foreach (array_slice($data['employees'] ?? [], 0, $data['required_staff']) as $employeeId) {
                $shift->assignments()->create(['employee_id' => $employeeId, 'source' => 'manual']);
            }
        });

        return redirect()->route('dashboard', ['datum' => $startsAt->toDateString()])
            ->with('status', 'Opdracht ingepland.');
    }
}

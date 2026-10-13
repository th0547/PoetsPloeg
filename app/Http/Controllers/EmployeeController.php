<?php

namespace App\Http\Controllers;

use App\Models\AvailabilityPattern;
use App\Models\Building;
use App\Models\Employee;
use App\Models\Skill;
use App\Services\AvailabilityEnforcer;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    public function __construct(private AvailabilityEnforcer $enforcer) {}

    public function index(Request $request): View
    {
        $employees = Employee::query()
            ->with(['skills:id,name', 'buildings:id,name'])
            ->when($request->query('q'), fn ($q, $term) => $q->where('name', 'like', "%{$term}%"))
            ->when(! $request->boolean('inactief'), fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->get();

        return view('employees.index', compact('employees'));
    }

    public function create(): View
    {
        return $this->form(new Employee(['active' => true, 'color' => sprintf('#%06x', random_int(0x222222, 0xCCCCCC))]));
    }

    public function store(Request $request): RedirectResponse
    {
        $employee = DB::transaction(fn () => $this->save(new Employee, $request));

        return redirect()->route('employees.edit', $employee)->with('status', 'Medewerker aangemaakt.');
    }

    /** Na opslaan: medewerker van diensten buiten de (nieuwe) beschikbaarheid halen. */
    private function releaseMessage(Employee $employee, string $message): string
    {
        $released = $this->enforcer->release($employee->fresh());
        if ($released->isEmpty()) {
            return $message;
        }

        return $message.sprintf(' %s is van %d %s gehaald die buiten de beschikbaarheid vallen; die staan nu bij Open diensten.',
            $employee->name, $released->count(), $released->count() === 1 ? 'dienst' : 'diensten');
    }

    public function edit(Employee $employee): View
    {
        return $this->form($employee);
    }

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        DB::transaction(fn () => $this->save($employee, $request));

        return redirect()->route('employees.edit', $employee)->with('status', $this->releaseMessage($employee, 'Medewerker opgeslagen.'));
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $employee->delete();

        return redirect()->route('employees.index')->with('status', 'Medewerker verwijderd.');
    }

    private function form(Employee $employee): View
    {
        $employee->loadMissing(['skills', 'buildings', 'availabilityPatterns']);

        return view('employees.form', [
            'employee' => $employee,
            'skills' => Skill::orderBy('name')->get(),
            'buildings' => Building::orderBy('name')->get(),
            'patterns' => old('patterns', $employee->availabilityPatterns->map(fn ($p) => [
                'weekday' => $p->weekday,
                'type' => $p->type,
                'repeat' => $p->repeatKey(),
                'starts_on' => $p->starts_on?->toDateString(),
                'start_time' => substr($p->start_time, 0, 5),
                'end_time' => substr($p->end_time, 0, 5),
                'note' => $p->note,
            ])->values()->all()),
            'absences' => $employee->exists
                ? $employee->absences()->where('ends_at', '>=', now()->subDays(30))->get()
                : collect(),
        ]);
    }

    private function save(Employee $employee, Request $request): Employee
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'contract_hours_min' => ['nullable', 'numeric', 'min:0', 'max:80'],
            'contract_hours_max' => array_filter(['nullable', 'numeric', 'min:0', 'max:80', $request->filled('contract_hours_min') ? 'gte:contract_hours_min' : null]),
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['boolean'],
            'skills' => ['array'],
            'skills.*' => ['exists:skills,id'],
            'buildings' => ['array'],
            'buildings.*' => ['exists:buildings,id'],
            'patterns' => ['array'],
            'patterns.*.weekday' => ['required', 'integer', 'between:1,7'],
            'patterns.*.type' => ['required', Rule::in(array_keys(AvailabilityPattern::TYPES))],
            'patterns.*.repeat' => ['nullable', Rule::in(array_keys(AvailabilityPattern::REPEATS))],
            'patterns.*.starts_on' => ['nullable', 'date'],
            'patterns.*.note' => ['nullable', 'string', 'max:100'],
            'patterns.*.start_time' => ['required', 'date_format:H:i'],
            'patterns.*.end_time' => ['required', 'date_format:H:i'],
        ], [], [
            'contract_hours_max' => 'max. contracturen',
            'contract_hours_min' => 'min. contracturen',
        ]);

        $employee->fill(collect($data)->except(['skills', 'buildings', 'patterns'])->all());
        $employee->active = $request->boolean('active');
        $employee->save();

        $employee->skills()->sync($data['skills'] ?? []);
        $employee->buildings()->sync($data['buildings'] ?? []);
        $employee->availabilityPatterns()->delete();
        foreach ($data['patterns'] ?? [] as $pattern) {
            [$unit, $every] = AvailabilityPattern::REPEATS[$pattern['repeat'] ?? 'w1'];
            $startsOn = ($every > 1 || $unit === 'day') ? ($pattern['starts_on'] ?? today()->toDateString()) : null;
            $employee->availabilityPatterns()->create([
                'type' => $pattern['type'],
                'repeat_unit' => $unit,
                'repeat_every' => $every,
                'starts_on' => $startsOn,
                // Bij herhaling per dag telt de weekdag niet; we bewaren die van de startdatum (handig voor sorteren).
                'weekday' => $unit === 'day' ? CarbonImmutable::parse($startsOn)->dayOfWeekIso : $pattern['weekday'],
                'start_time' => $pattern['start_time'],
                'end_time' => $pattern['end_time'],
                'note' => $pattern['note'] ?? null,
            ]);
        }

        return $employee;
    }
}

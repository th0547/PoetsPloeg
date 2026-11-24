<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Shift;
use App\Rules\PhoneNumber;
use App\Services\Audit;
use App\Services\DutchHolidays;
use App\Services\FixedStaffCheck;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BuildingController extends Controller
{
    public function index(): View
    {
        $buildings = Building::query()
            ->withCount(['taskTemplates', 'employees'])
            ->orderByDesc('active')->orderBy('name')
            ->get();

        // Per pand: de diensten van vandaag en wie er nu is.
        $now = CarbonImmutable::now();
        $today = Shift::query()->active()
            ->overlapping($now->startOfDay(), $now->startOfDay()->addDay())
            ->with('assignments.employee:id,name,color')
            ->orderBy('starts_at')
            ->get()
            ->groupBy('building_id');

        return view('buildings.index', compact('buildings', 'today', 'now'));
    }

    public function create(): View
    {
        return view('buildings.form', [
            'building' => new Building(['active' => true, 'color' => sprintf('#%06x', random_int(0x222222, 0xCCCCCC))]),
            'employees' => Employee::active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $building = Building::create($this->validated($request));
        $building->employees()->sync($request->input('employees', []));

        return redirect()->route('buildings.show', $building)->with('status', 'Pand aangemaakt. Voeg nu de vaste taken toe.');
    }

    public function show(Request $request, Building $building, ShiftGenerator $generator, DutchHolidays $holidays): View
    {
        $building->load(['taskTemplates.skills', 'taskTemplates.patterns.employees', 'employees']);
        if (Gate::allows('view-contact-details')) {
            Audit::viewed($building, "Pand {$building->name}: contactgegevens en toegangsinstructies");
        }

        // Weekrooster: maandag t/m zondag van de gekozen week.
        $weekStart = rescue(fn () => CarbonImmutable::parse($request->query('week', 'today')), CarbonImmutable::today(), false)
            ->startOfWeek();
        $weekEnd = $weekStart->addDays(6);
        $generator->generate($weekStart, $weekEnd);

        $shifts = Shift::query()->active()
            ->where('building_id', $building->id)
            ->where('starts_at', '>=', $weekStart)
            ->where('starts_at', '<', $weekEnd->addDay())
            ->with('assignments.employee')
            ->orderBy('starts_at')
            ->get();

        // Werkdagen altijd tonen, het weekend alleen als er dan diensten zijn.
        $days = collect(range(0, 6))->map(fn ($i) => $weekStart->addDays($i))
            ->filter(fn (CarbonImmutable $d) => ! $d->isWeekend()
                || $shifts->contains(fn (Shift $s) => $s->starts_at->isSameDay($d)))
            ->values();

        // [rij-sleutel][Y-m-d] => diensten; rij-sleutel is het template-id of 'los' voor losse opdrachten.
        $grid = $shifts->groupBy(fn (Shift $s) => $s->task_template_id ?? 'los')
            ->map(fn ($rows) => $rows->groupBy(fn (Shift $s) => $s->starts_at->toDateString()));

        $staffEmployees = Employee::query()
            ->where(fn ($q) => $q->where('active', true)
                ->orWhereIn('id', $building->taskTemplates->flatMap->staff()->pluck('id')))
            ->orderBy('name')
            ->get();
        $knownIds = $building->employees->pluck('id')->all();

        return view('buildings.show', [
            'building' => $building,
            'weekStart' => $weekStart,
            'days' => $days,
            'grid' => $grid,
            'holidays' => $holidays->between($weekStart, $weekEnd),
            'staffEmployees' => $staffEmployees->sortBy(fn ($e) => [in_array($e->id, $knownIds) ? 0 : 1, $e->name])->values(),
            'knownIds' => $knownIds,
        ]);
    }

    /**
     * Bezettingstabel: vaste bezetting (per patroon) en aantal personen van alle taken van een pand in één keer opslaan.
     */
    public function updateStaffing(Request $request, Building $building, ShiftGenerator $generator, FixedStaffCheck $staffCheck): RedirectResponse
    {
        $templates = $building->taskTemplates()->with(['patterns.employees:id', 'skills:id,name'])->get()->keyBy('id');
        $patternIds = $templates->flatMap->patterns->pluck('id');

        $data = $request->validate([
            'staff' => ['array'],
            'staff.*' => ['integer', 'min:1', 'max:50'],
            'fixed' => ['array'],
            'fixed.*' => ['array'],
            'fixed.*.*' => ['integer', 'exists:employees,id'],
        ], [], ['staff.*' => 'aantal personen']);

        $unknown = collect(array_keys($data['staff'] ?? []))->reject(fn ($id) => $templates->has((int) $id))
            ->merge(collect(array_keys($data['fixed'] ?? []))->reject(fn ($id) => $patternIds->contains((int) $id)));
        abort_if($unknown->isNotEmpty(), 422, 'Onbekende taak voor dit pand.');

        // Eerst bepalen wat er verandert; nieuwe vaste krachten toetsen we op beschikbaarheid, afwezigheid en overlap.
        $changes = [];
        $problems = [];
        $notes = [];
        foreach ($templates as $id => $template) {
            $patterns = $template->patterns->values();
            $staff = (int) ($data['staff'][$id] ?? $template->required_staff);
            $selected = $patterns->map(fn ($p) => collect($data['fixed'][$p->id] ?? [])->map(fn ($e) => (int) $e)->unique()->values())->all();
            $added = $patterns->map(fn ($p, $i) => $selected[$i]->diff($p->employees->pluck('id'))->values()->all())->all();

            $sameEmployees = $patterns->every(fn ($p, $i) => $selected[$i]->sort()->values()->all() === $p->employees->pluck('id')->sort()->values()->all());
            if ($staff === $template->required_staff && $sameEmployees) {
                continue;
            }
            $changes[$id] = [$template, $selected, $staff];

            $results = FixedStaffCheck::selected($staffCheck->forTemplate($template, collect($added)->flatten()->unique(), $template->id), $added);
            foreach (FixedStaffCheck::patternMessages($template, $results) as $message) {
                $problems[] = $template->name.' – '.$message;
            }
            foreach (FixedStaffCheck::flatten($results) as $r) {
                if ($r['possible'] < $r['total']) {
                    $notes[] = sprintf('%s staat op %d van de %d diensten van %s', $r['name'], $r['possible'], $r['total'], $template->name);
                }
            }
        }

        if ($problems !== [] && ! $request->boolean('confirm_staffing')) {
            return redirect()->to(route('buildings.show', $building).'#bezetting')
                ->withInput()
                ->withErrors(['staffing' => $problems]);
        }

        DB::transaction(function () use ($changes, $building) {
            foreach ($changes as [$template, $selected, $staff]) {
                $template->update(['required_staff' => $staff]);
                foreach ($template->patterns->values() as $i => $pattern) {
                    $pattern->employees()->sync($selected[$i]->mapWithKeys(fn ($e, $position) => [$e => ['position' => $position]])->all());
                    // Wie vast op een taak staat, kent het pand.
                    $building->employees()->syncWithoutDetaching($selected[$i]->all());
                }
            }
        });

        foreach ($changes as [$template]) {
            $generator->regenerateTemplate($template);
        }

        $count = count($changes);

        return redirect()->route('buildings.show', $building)->with('status', $count === 0
            ? 'Geen wijzigingen in de bezetting.'
            : sprintf('Bezetting opgeslagen voor %d %s. Toekomstige diensten zijn bijgewerkt (handmatig aangepaste diensten blijven staan).',
                $count, $count === 1 ? 'taak' : 'taken')
                .($notes === [] ? '' : ' Let op: '.collect($notes)->join('; ').'. De overige plekken staan bij Open diensten.'));
    }

    public function edit(Building $building): View
    {
        return view('buildings.form', [
            'building' => $building->load('employees'),
            'employees' => Employee::where(fn ($q) => $q->where('active', true)->orWhereIn('id', $building->employees->pluck('id')))
                ->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Building $building): RedirectResponse
    {
        $building->update($this->validated($request));
        $building->employees()->sync($request->input('employees', []));

        return redirect()->route('buildings.show', $building)->with('status', 'Pand opgeslagen.');
    }

    public function destroy(Building $building): RedirectResponse
    {
        $building->delete();

        return redirect()->route('buildings.index')->with('status', 'Pand verwijderd.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:12'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50', new PhoneNumber],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'access_instructions' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'employees' => ['array'],
            'employees.*' => ['exists:employees,id'],
        ]);
        unset($data['employees']);
        $data['active'] = $request->boolean('active');

        return $data;
    }
}

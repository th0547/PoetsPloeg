<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Skill;
use App\Models\TaskTemplate;
use App\Models\TaskTemplatePattern;
use App\Services\DutchHolidays;
use App\Services\FixedStaffCheck;
use App\Services\RecurrenceRule;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TaskTemplateController extends Controller
{
    /** Uitkomst van de controle op de vaste bezetting bij de laatste save(), voor de melding. */
    private array $staffing = [];

    public function __construct(private ShiftGenerator $generator, private DutchHolidays $holidays, private FixedStaffCheck $staffCheck) {}

    public function create(Building $building): View
    {
        $template = new TaskTemplate([
            'start_time' => '18:00',
            'end_time' => '20:00',
            'required_staff' => 1,
            'valid_from' => today(),
            'skip_holidays' => true,
            'active' => true,
        ]);
        $pattern = new TaskTemplatePattern(['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1, 2, 3, 4, 5], 'start_time' => '18:00', 'end_time' => '20:00']);
        $pattern->setRelation('employees', collect());
        $template->setRelation('patterns', collect([$pattern]));

        return $this->form($building, $template);
    }

    public function store(Request $request, Building $building): RedirectResponse
    {
        $template = DB::transaction(fn () => $this->save($building, new TaskTemplate, $request));
        $this->generator->generateAhead(collect([$template]));

        return redirect()->route('buildings.show', $building)->with('status', 'Taak toegevoegd en ingepland.'.$this->staffingNote());
    }

    public function edit(Building $building, TaskTemplate $template): View
    {
        return $this->form($building, $template);
    }

    public function update(Request $request, Building $building, TaskTemplate $template): RedirectResponse
    {
        DB::transaction(fn () => $this->save($building, $template, $request));
        $this->generator->regenerateTemplate($template);

        return redirect()->route('buildings.show', $building)
            ->with('status', 'Taak opgeslagen. Toekomstige diensten zijn bijgewerkt (handmatig aangepaste diensten blijven staan).'.$this->staffingNote());
    }

    public function destroy(Building $building, TaskTemplate $template): RedirectResponse
    {
        DB::transaction(function () use ($template) {
            // Toekomstige, niet aangepaste diensten verdwijnen; historie blijft bewaard.
            $template->shifts()->whereDate('date', '>=', today())->where('is_modified', false)->delete();
            $template->delete();
        });

        return redirect()->route('buildings.show', $building)->with('status', 'Taak verwijderd.');
    }

    private function form(Building $building, TaskTemplate $template): View
    {
        $template->loadMissing(['skills', 'patterns.employees']);
        $hasOld = session()->hasOldInput();
        $staffIds = $template->staff()->pluck('id');
        $knownIds = $building->employees()->pluck('employees.id')->all();

        return view('templates.form', [
            'building' => $building,
            'template' => $template,
            // Beginstand van de herhalingskiezer (resources/js/recurrence.js). Gaat via @js() naar
            // Alpine: dat codeert alles als JSON, zodat een waarde nooit als JavaScript kan worden uitgevoerd.
            'recurrence' => [
                'patterns' => old('patterns') !== null
                    ? $this->oldPatterns(old('patterns'))
                    : $template->patterns->map(fn (TaskTemplatePattern $p) => $p->toEditor($template))->values()->all(),
                'validFrom' => old('valid_from', $template->valid_from?->toDateString()),
                'validUntil' => old('valid_until', $template->valid_until?->toDateString()),
                'requiredStaff' => (int) old('required_staff', $template->required_staff ?: 1),
                'skipHolidays' => $hasOld ? (bool) old('skip_holidays') : (bool) $template->skip_holidays,
                // Feestdagen waarop niet wordt ingepland, voor de voorbeeldkalender.
                'holidays' => collect($this->holidays->between(today()->startOfYear(), today()->addYears(3)->endOfYear()))
                    ->filter(fn ($name, $date) => $this->holidays->isSkippable(CarbonImmutable::parse($date)))
                    ->all(),
                'previewUrl' => route('api.recurrence.preview'),
                'helpUrl' => route('help.recurrence'),
                // Vaste bezetting per patroon: wie er te kiezen is en de controle (resources/js/staffing.js).
                'staff' => [
                    'url' => route('api.staffing.check'),
                    'buildingId' => $building->id,
                    'templateId' => $template->exists ? $template->id : null,
                    'serverWarned' => session('errors')?->has('staffing') ?? false,
                    'employees' => Employee::where(fn ($q) => $q->where('active', true)->orWhereIn('id', $staffIds))
                        ->orderBy('name')->get(['id', 'name', 'color'])
                        ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->name, 'color' => $e->color, 'known' => in_array($e->id, $knownIds, true)])
                        ->values()->all(),
                ],
            ],
            'skills' => Skill::orderBy('name')->get(),
        ]);
    }

    /**
     * Voorbeeld voor een eigen code (RRULE): de datums in de gevraagde periode, berekend met
     * dezelfde code als de echte planning. Geeft de invoer nooit terug, alleen datums of een melding.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rrule' => ['required', 'string', 'max:300'],
            'starts_on' => ['required', 'date'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        if ($from->diffInDays($to) > 400) {
            $to = $from->addDays(400);
        }

        try {
            $code = RecurrenceRule::normalize($data['rrule']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'rrule' => $code,
            'label' => RecurrenceRule::describe($code),
            'dates' => array_keys(RecurrenceRule::datesBetween($code, CarbonImmutable::parse($data['starts_on']), $from, $to)),
        ]);
    }

    /** Aanvulling op de melding na opslaan: wat betekent de vaste bezetting voor de diensten? */
    private function staffingNote(): string
    {
        $problems = collect(FixedStaffCheck::flatten($this->staffing))->filter(fn ($r) => $r['status'] !== FixedStaffCheck::OK && $r['possible'] < $r['total']);
        if ($problems->isEmpty()) {
            return '';
        }

        return ' Let op: '.$problems->map(fn ($r) => sprintf('%s staat op %d van de %d diensten', $r['name'], $r['possible'], $r['total']))->join(', ', ' en ')
            .'. De overige plekken staan bij Open diensten.';
    }

    /** Patronen uit old() terug naar de beginstand van de editor (na een validatiefout). */
    private function oldPatterns(mixed $patterns): array
    {
        return collect(is_array($patterns) ? $patterns : [])->map(fn ($p) => [
            'recurrence' => (string) ($p['recurrence'] ?? 'weekly'),
            'every' => max(1, (int) ($p['repeat_every'] ?? 1)),
            'weekdays' => array_map('intval', (array) ($p['weekdays'] ?? [])),
            'nth' => (int) ($p['nth'] ?? 1),
            'weekday' => (int) ($p['weekday'] ?? 1),
            'dayOfMonth' => (int) ($p['day_of_month'] ?? 1),
            // Ruwe invoer (kan na een fout ongeldig zijn); veilig omdat het via @js() als JSON-string gaat.
            'rrule' => is_string($p['rrule'] ?? null) ? mb_substr($p['rrule'], 0, 300) : '',
            'startsOn' => is_string($p['starts_on'] ?? null) ? $p['starts_on'] : null,
            'id' => is_numeric($p['id'] ?? null) ? (int) $p['id'] : null,
            'startTime' => is_string($p['start_time'] ?? null) ? $p['start_time'] : null,
            'endTime' => is_string($p['end_time'] ?? null) ? $p['end_time'] : null,
            'employees' => collect((array) ($p['employees'] ?? []))->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->values()->all(),
        ])->values()->all();
    }

    private function save(Building $building, TaskTemplate $template, Request $request): TaskTemplate
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'required_staff' => ['required', 'integer', 'min:1', 'max:50'],
            ...TaskTemplatePattern::rules(),
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'skills' => ['array'],
            'skills.*' => ['exists:skills,id'],
        ], TaskTemplatePattern::messages(), [
            'patterns.*.rrule' => 'code',
            'patterns.*.starts_on' => 'telt vanaf',
            'patterns.*.start_time' => 'begintijd',
            'patterns.*.end_time' => 'eindtijd',
        ]);
        $patterns = array_values($data['patterns']);
        $staff = array_map(fn (array $p) => TaskTemplatePattern::employeeIdsFromInput($p), $patterns);

        // Vaste krachten die (een deel van) de diensten van hun patroon niet kunnen draaien: eerst waarschuwen.
        $draft = FixedStaffCheck::draft($building, ['skip_holidays' => $request->boolean('skip_holidays')] + $data);
        $this->staffing = FixedStaffCheck::selected(
            $this->staffCheck->forTemplate($draft, collect($staff)->flatten()->unique(), $template->exists ? $template->id : null),
            $staff,
        );
        if (FixedStaffCheck::needsConfirmation(FixedStaffCheck::flatten($this->staffing)) && ! $request->boolean('confirm_staffing')) {
            throw ValidationException::withMessages(['staffing' => FixedStaffCheck::patternMessages($draft, $this->staffing)]);
        }

        $template->fill(collect($data)->except(['skills', 'patterns'])->all());
        $template->start_time = $patterns[0]['start_time'];
        $template->end_time = $patterns[0]['end_time'];
        $template->skip_holidays = $request->boolean('skip_holidays');
        $template->active = $request->boolean('active');
        $template->building()->associate($building);
        $template->save();

        // Patronen bijwerken. Een bestaand patroon houdt zijn id, zodat diensten (ook handmatig aangepaste)
        // en de vaste bezetting eraan gekoppeld blijven. Verwijderde patronen: het patroon-id van hun diensten
        // wordt leeg (nullOnDelete).
        $existing = $template->patterns()->get()->keyBy('id');
        $kept = [];
        foreach ($patterns as $i => $input) {
            $attributes = TaskTemplatePattern::attributesFromInput($input, $i);
            $pattern = isset($input['id']) && $existing->has((int) $input['id'])
                ? tap($existing[(int) $input['id']])->update($attributes)
                : $template->patterns()->create($attributes);
            $pattern->employees()->sync(collect($staff[$i])->mapWithKeys(fn ($id, $position) => [$id => ['position' => $position]])->all());
            $kept[] = $pattern->id;
        }
        $template->patterns()->whereNotIn('id', $kept)->delete();
        $template->unsetRelation('patterns');

        $template->skills()->sync($data['skills'] ?? []);
        // Wie vast op een taak staat, kent het pand (zoals bij de bezettingstabel; issue #70).
        $building->employees()->syncWithoutDetaching(collect($staff)->flatten()->unique()->values()->all());

        return $template;
    }
}

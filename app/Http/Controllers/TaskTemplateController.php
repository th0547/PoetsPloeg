<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Skill;
use App\Models\TaskTemplate;
use App\Models\TaskTemplatePattern;
use App\Services\DutchHolidays;
use App\Services\RecurrenceRule;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TaskTemplateController extends Controller
{
    public function __construct(private ShiftGenerator $generator, private DutchHolidays $holidays) {}

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
        $template->setRelation('patterns', collect([new TaskTemplatePattern(['recurrence' => 'weekly', 'repeat_every' => 1, 'weekdays' => [1, 2, 3, 4, 5]])]));

        return $this->form($building, $template);
    }

    public function store(Request $request, Building $building): RedirectResponse
    {
        $template = DB::transaction(fn () => $this->save($building, new TaskTemplate, $request));
        $this->generator->generateAhead(collect([$template]));

        return redirect()->route('buildings.show', $building)->with('status', 'Taak toegevoegd en ingepland.');
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
            ->with('status', 'Taak opgeslagen. Toekomstige diensten zijn bijgewerkt (handmatig aangepaste diensten blijven staan).');
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
        $template->loadMissing(['skills', 'employees', 'patterns']);
        $hasOld = session()->hasOldInput();

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
                'startTime' => old('start_time', substr((string) $template->start_time, 0, 5)),
                'endTime' => old('end_time', substr((string) $template->end_time, 0, 5)),
                'skipHolidays' => $hasOld ? (bool) old('skip_holidays') : (bool) $template->skip_holidays,
                // Feestdagen waarop niet wordt ingepland, voor de voorbeeldkalender.
                'holidays' => collect($this->holidays->between(today()->startOfYear(), today()->addYears(3)->endOfYear()))
                    ->filter(fn ($name, $date) => $this->holidays->isSkippable(CarbonImmutable::parse($date)))
                    ->all(),
                'previewUrl' => route('api.recurrence.preview'),
                'helpUrl' => route('help.recurrence'),
            ],
            'skills' => Skill::orderBy('name')->get(),
            'employees' => Employee::where(fn ($q) => $q->where('active', true)->orWhereIn('id', $template->employees->pluck('id')))
                ->orderBy('name')->get(),
            'knownIds' => $building->employees()->pluck('employees.id')->all(),
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
            'ownTimes' => filter_var($p['own_times'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'startTime' => is_string($p['start_time'] ?? null) ? $p['start_time'] : null,
            'endTime' => is_string($p['end_time'] ?? null) ? $p['end_time'] : null,
        ])->values()->all();
    }

    private function save(Building $building, TaskTemplate $template, Request $request): TaskTemplate
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'required_staff' => ['required', 'integer', 'min:1', 'max:50'],
            ...TaskTemplatePattern::rules(),
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'skills' => ['array'],
            'skills.*' => ['exists:skills,id'],
            'employees' => ['array'],
            'employees.*' => ['exists:employees,id'],
        ], [
            'patterns.required' => 'Voeg minimaal één patroon toe.',
            'patterns.max' => 'Een taak kan hoogstens '.TaskTemplatePattern::MAX_PER_TEMPLATE.' patronen hebben.',
            'patterns.*.weekdays.required_if' => 'Kies minimaal één weekdag.',
            'patterns.*.rrule.required_if' => 'Vul een code in.',
            'patterns.*.start_time.required_if_accepted' => 'Vul een begintijd in.',
            'patterns.*.end_time.required_if_accepted' => 'Vul een eindtijd in.',
        ], [
            'patterns.*.rrule' => 'code',
            'patterns.*.starts_on' => 'telt vanaf',
        ]);

        $template->fill(collect($data)->except(['skills', 'employees', 'patterns'])->all());
        $template->skip_holidays = $request->boolean('skip_holidays');
        $template->active = $request->boolean('active');
        $template->building()->associate($building);
        $template->save();

        // Patronen vervangen. Diensten houden hun patroon-id tot regenerateTemplate() de toekomstige
        // opnieuw opbouwt; bij verwijderde patronen wordt dat id leeg (nullOnDelete).
        $template->patterns()->delete();
        foreach (array_values($data['patterns']) as $i => $pattern) {
            $template->patterns()->create(TaskTemplatePattern::attributesFromInput($pattern, $i));
        }
        $template->unsetRelation('patterns');

        $template->skills()->sync($data['skills'] ?? []);
        $template->employees()->sync(
            collect($data['employees'] ?? [])->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all()
        );

        return $template;
    }
}

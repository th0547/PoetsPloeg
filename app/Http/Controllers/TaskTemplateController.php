<?php

namespace App\Http\Controllers;

use App\Models\Building;
use App\Models\Employee;
use App\Models\Skill;
use App\Models\TaskTemplate;
use App\Services\DutchHolidays;
use App\Services\ShiftGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TaskTemplateController extends Controller
{
    public function __construct(private ShiftGenerator $generator, private DutchHolidays $holidays) {}

    public function create(Building $building): View
    {
        return $this->form($building, new TaskTemplate([
            'recurrence' => 'weekly',
            'repeat_every' => 1,
            'weekdays' => [1, 2, 3, 4, 5],
            'start_time' => '18:00',
            'end_time' => '20:00',
            'required_staff' => 1,
            'valid_from' => today(),
            'skip_holidays' => true,
            'active' => true,
        ]));
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
        $template->loadMissing(['skills', 'employees']);
        $hasOld = session()->hasOldInput();

        return view('templates.form', [
            'building' => $building,
            'template' => $template,
            // Beginstand van de herhalingskiezer (resources/js/recurrence.js).
            'recurrence' => [
                'recurrence' => old('recurrence', $template->recurrence),
                'every' => (int) old('repeat_every', $template->repeat_every),
                'weekdays' => array_map('intval', old('weekdays', $template->weekdays ?? [])),
                'nth' => (int) old('nth', $template->nth ?? 1),
                'weekday' => (int) old('weekday', $template->weekday ?? 1),
                'dayOfMonth' => (int) old('day_of_month', $template->day_of_month ?? 1),
                'validFrom' => old('valid_from', $template->valid_from?->toDateString()),
                'validUntil' => old('valid_until', $template->valid_until?->toDateString()),
                'skipHolidays' => $hasOld ? (bool) old('skip_holidays') : (bool) $template->skip_holidays,
                // Feestdagen waarop niet wordt ingepland, voor de voorbeeldkalender.
                'holidays' => collect($this->holidays->between(today()->startOfYear(), today()->addYears(3)->endOfYear()))
                    ->filter(fn ($name, $date) => $this->holidays->isSkippable(CarbonImmutable::parse($date)))
                    ->all(),
            ],
            'skills' => Skill::orderBy('name')->get(),
            'employees' => Employee::where(fn ($q) => $q->where('active', true)->orWhereIn('id', $template->employees->pluck('id')))
                ->orderBy('name')->get(),
            'knownIds' => $building->employees()->pluck('employees.id')->all(),
        ]);
    }

    private function save(Building $building, TaskTemplate $template, Request $request): TaskTemplate
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'required_staff' => ['required', 'integer', 'min:1', 'max:50'],
            'recurrence' => ['required', Rule::in(array_keys(TaskTemplate::RECURRENCES))],
            'repeat_every' => ['required', 'integer', 'min:1', 'max:52'],
            'weekdays' => ['required_if:recurrence,weekly', 'array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'nth' => ['required_if:recurrence,monthly_nth_weekday', 'nullable', Rule::in([1, 2, 3, 4, -1])],
            'weekday' => ['required_if:recurrence,monthly_nth_weekday', 'nullable', 'integer', 'between:1,7'],
            'day_of_month' => ['required_if:recurrence,monthly_day', 'nullable', 'integer', 'between:1,31'],
            'valid_from' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'skills' => ['array'],
            'skills.*' => ['exists:skills,id'],
            'employees' => ['array'],
            'employees.*' => ['exists:employees,id'],
        ], [
            'weekdays.required_if' => 'Kies minimaal één weekdag.',
        ]);

        $template->fill(collect($data)->except(['skills', 'employees'])->all());
        $template->weekdays = $data['recurrence'] === 'weekly' ? array_map('intval', $data['weekdays'] ?? []) : null;
        $template->skip_holidays = $request->boolean('skip_holidays');
        $template->active = $request->boolean('active');
        $template->building()->associate($building);
        $template->save();

        $template->skills()->sync($data['skills'] ?? []);
        $template->employees()->sync(
            collect($data['employees'] ?? [])->values()->mapWithKeys(fn ($id, $i) => [$id => ['position' => $i]])->all()
        );

        return $template;
    }
}

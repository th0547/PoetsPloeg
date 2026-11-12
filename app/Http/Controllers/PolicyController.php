<?php

namespace App\Http\Controllers;

use App\Models\PolicyVersion;
use App\Services\Audit;
use App\Support\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Beleidspagina (issue #42): cao-afspraken instellen en overschrijven. Elke opslag is een versie
 * met een ingangsdatum; alleen afwijkingen van de cao-standaard worden bewaard.
 */
class PolicyController extends Controller
{
    public function index(Request $request): View
    {
        $date = rescue(fn () => CarbonImmutable::parse($request->query('versie', 'today')), CarbonImmutable::today(), false);
        $policy = Policy::at($date);
        $versions = PolicyVersion::with('author:id,name')->orderByDesc('valid_from')->get();
        $upcoming = $versions->first(fn (PolicyVersion $v) => $v->valid_from->isAfter(CarbonImmutable::today()));

        return view('policy.index', [
            'policy' => $policy,
            'shownDate' => $date,
            'versions' => $versions,
            'upcoming' => $upcoming,
            'canEdit' => Gate::allows('manage-policy'),
            'deviations' => $this->deviations($policy),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $groups = Policy::groups();
        $rules = [
            'valid_from' => ['required', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:2000'],
            'employee_notes' => ['nullable', 'array'],
            'employee_notes.*' => ['nullable', 'string', 'max:500'],
            'leave_types' => ['required', 'array'],
            'leave_types.*.name' => ['required', 'string', 'max:60'],
            'leave_types.*.group' => ['required', 'string', 'max:40'],
            'leave_types.*.counts_as' => ['required', Rule::in(array_keys(config('poetsploeg-cao.counts_as')))],
            'leave_types.*.paid' => ['required', 'integer', 'between:0,100'],
            'leave_types.*.paid_by' => ['required', Rule::in(['werkgever', 'UWV'])],
            'leave_types.*.unit' => ['required', Rule::in(array_keys(config('poetsploeg-cao.units')))],
            'leave_types.*.max' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'leave_types.*.period' => ['required', Rule::in(array_keys(config('poetsploeg-cao.periods')))],
            'leave_types.*.min_days_ahead' => ['required', 'integer', 'between:0,365'],
            'leave_types.*.refusal' => ['required', Rule::in(array_keys(config('poetsploeg-cao.refusal')))],
            'leave_types.*.explanation' => ['nullable', 'string', 'max:500'],
            'leave_types.*.key' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]*$/'],
        ];
        foreach ($groups as $group => $definition) {
            foreach ($definition['items'] as $item => $setting) {
                $rules["settings.{$group}.{$item}"] = $this->settingRules($setting);
            }
        }
        $data = $request->validate($rules, [
            'valid_from.after_or_equal' => 'Een nieuwe versie gaat vandaag of later in; het verleden blijft zoals het was.',
        ], [
            'leave_types.*.name' => 'naam verlofsoort',
        ]);

        // Instellingen: alleen afwijkingen van de standaard bewaren.
        $settings = [];
        $unfavorable = [];
        foreach ($groups as $group => $definition) {
            foreach ($definition['items'] as $item => $setting) {
                $key = "{$group}.{$item}";
                $raw = $setting['type'] === 'bool'
                    ? $request->boolean("settings.{$group}.{$item}")
                    : ($data['settings'][$group][$item] ?? $setting['default']);
                $value = Policy::cast($setting, $raw);
                if ($value !== Policy::cast($setting, $setting['default'])) {
                    $settings[$key] = $value;
                    if (Policy::unfavorable($setting, $value)) {
                        $unfavorable[] = $setting['label'];
                    }
                }
            }
        }

        // Verlofsoorten: afwijkingen van standaardsoorten en eigen soorten.
        $defaults = config('poetsploeg-cao.leave_types');
        $leaveTypes = [];
        foreach ($request->input('leave_types', []) as $formKey => $input) {
            $key = array_key_exists($formKey, $defaults) || ! str_starts_with((string) $formKey, 'nieuw')
                ? (string) $formKey
                : Str::snake(Str::ascii($input['key'] ?? '') ?: $input['name']);
            if ($key === '' || ! preg_match('/^[a-z0-9_]+$/', $key)) {
                throw ValidationException::withMessages(["leave_types.{$formKey}.name" => 'Geef de nieuwe verlofsoort een naam.']);
            }
            $type = [];
            foreach (array_keys(Policy::LEAVE_FIELDS) as $field) {
                $type[$field] = Policy::castLeaveField($field, in_array(Policy::LEAVE_FIELDS[$field], ['bool'], true)
                    ? ($input[$field] ?? false) : ($input[$field] ?? null));
            }
            $default = $defaults[$key] ?? null;
            if ($default === null) {
                $leaveTypes[$key] = $type + ['source' => 'bedrijfsbeleid'];
            } else {
                $diff = array_filter($type, fn ($v, $field) => $v !== Policy::castLeaveField($field, $default[$field] ?? null), ARRAY_FILTER_USE_BOTH);
                if ($diff) {
                    $leaveTypes[$key] = $diff;
                    if (Policy::leaveTypeUnfavorable($default, array_merge($default, $type))) {
                        $unfavorable[] = 'Verlofsoort '.$type['name'];
                    }
                }
            }
        }

        if ($unfavorable && blank($data['note'] ?? null)) {
            throw ValidationException::withMessages([
                'note' => 'Je wijkt ten nadele van de werknemer af van de cao ('.implode(', ', $unfavorable).'). Geef een toelichting.',
            ]);
        }

        $previous = Policy::at($data['valid_from']);
        // Zelfde ingangsdatum = die versie bijwerken (whereDate: SQLite bewaart datums met tijd).
        $version = PolicyVersion::whereDate('valid_from', $data['valid_from'])->first() ?? new PolicyVersion(['valid_from' => $data['valid_from']]);
        $version->fill([
            'settings' => $settings,
            'leave_types' => $leaveTypes,
            'note' => $data['note'] ?? null,
            'employee_notes' => array_filter($data['employee_notes'] ?? [], fn ($v) => filled($v)) ?: null,
            'created_by' => $request->user()->id,
        ])->save();
        Policy::forget();

        $changed = $this->changedKeys($previous, Policy::at($data['valid_from']));
        Audit::log('policy.updated', 'Beleid gewijzigd, geldig vanaf '.$version->valid_from->format('d-m-Y'), $version,
            array_filter(['gewijzigd' => array_slice($changed, 0, 30), 'nadelig' => $unfavorable]));

        return redirect()->route('policy.index', ['versie' => $version->valid_from->toDateString()])
            ->with('status', 'Beleid opgeslagen, geldig vanaf '.$version->valid_from->translatedFormat('j F Y').'.'
                .($changed ? ' Gewijzigd: '.count($changed).' '.(count($changed) === 1 ? 'onderdeel' : 'onderdelen').'.' : ' Er was niets gewijzigd.'));
    }

    /** Leesversie zoals medewerkers die zien (voor planners: "Bekijken als medewerker"). */
    public function preview(): View
    {
        return view('policy.preview', ['policy' => Policy::current(), 'upcoming' => $this->upcoming()]);
    }

    /** Leesversie voor medewerkers (/mijn/beleid). */
    public function employee(): View
    {
        abort_unless(Policy::current()->get('privacy.visible_to_employees'), 404);

        return view('my.policy', ['policy' => Policy::current(), 'upcoming' => $this->upcoming()]);
    }

    private function upcoming(): ?array
    {
        $version = PolicyVersion::whereDate('valid_from', '>', CarbonImmutable::today())->orderBy('valid_from')->first();
        if (! $version) {
            return null;
        }
        $changed = $this->changedKeys(Policy::current(), Policy::at($version->valid_from), employeeOnly: true);

        return $changed ? ['date' => $version->valid_from, 'changed' => $changed] : null;
    }

    private function settingRules(array $setting): array
    {
        return match ($setting['type']) {
            'int' => ['nullable', 'integer', 'min:'.($setting['min'] ?? 0), 'max:'.($setting['max'] ?? 100000)],
            'decimal' => ['nullable', 'numeric', 'min:'.($setting['min'] ?? 0), 'max:'.($setting['max'] ?? 100000)],
            'bool' => ['nullable'],
            'select' => ['nullable', Rule::in(array_keys($setting['options']))],
            'time' => ['nullable', 'date_format:H:i'],
            'weekday' => ['nullable', 'integer', 'between:1,7'],
            default => ['nullable', 'string', 'max:255'],
        };
    }

    /** @return list<array{key: string, label: string, group: string, unfavorable: bool}> */
    private function deviations(Policy $policy): array
    {
        $list = [];
        foreach (Policy::groups() as $group => $definition) {
            foreach ($definition['items'] as $item => $setting) {
                if ($policy->isDeviating("{$group}.{$item}")) {
                    $list[] = ['key' => "{$group}.{$item}", 'label' => $setting['label'], 'group' => $definition['label'], 'unfavorable' => $policy->isUnfavorable("{$group}.{$item}")];
                }
            }
        }
        foreach ($policy->leaveTypes() as $type) {
            if ($type['deviating'] || $type['custom']) {
                $list[] = ['key' => 'leave.'.$type['key'], 'label' => $type['name'].($type['custom'] ? ' (eigen soort)' : ''), 'group' => 'Verlofsoorten', 'unfavorable' => $type['unfavorable']];
            }
        }

        return $list;
    }

    /** @return list<string> leesbare namen van wat er verschilt */
    private function changedKeys(Policy $a, Policy $b, bool $employeeOnly = false): array
    {
        $changed = [];
        foreach (Policy::groups() as $group => $definition) {
            foreach ($definition['items'] as $item => $setting) {
                if ($employeeOnly && empty($setting['employee'])) {
                    continue;
                }
                if ($a->get("{$group}.{$item}") !== $b->get("{$group}.{$item}")) {
                    $changed[] = $setting['label'];
                }
            }
        }
        $typesA = $a->leaveTypes();
        foreach ($b->leaveTypes() as $key => $type) {
            $old = $typesA->get($key);
            if (! $old || Policy::leaveTypeDiffers($old, $type)) {
                $changed[] = 'Verlofsoort '.$type['name'];
            }
        }

        return $changed;
    }
}
